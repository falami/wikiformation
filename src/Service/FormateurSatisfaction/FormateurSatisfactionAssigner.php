<?php

declare(strict_types=1);

namespace App\Service\FormateurSatisfaction;

use App\Entity\FormateurSatisfactionAssignment;
use App\Entity\FormateurSatisfactionTemplate;
use App\Entity\{Session, Utilisateur, Entite, FormateurSatisfactionAttempt};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityManagerInterface as EM;

final class FormateurSatisfactionAssigner
{
  public function __construct(private EM $em) {}

  /**
   * Crée 1 assignment par (session, formateur) si la session est FULL
   * et qu’un template formateur existe pour la formation.
   */
  public function assignForSession(Session $session, Utilisateur $user, Entite $entite): int
  {
    if (method_exists($session, 'getStatus') && !in_array($session->getStatus(), [StatusSession::FULL, StatusSession::IN_PROGRESS], true)) {
      return 0;
    }

    $formation = $session->getFormation();
    if (!$formation) return 0;

    // ✅ Stratégie template :
    // - soit tu ajoutes un champ "formateurSatisfactionTemplate" sur Formation
    // - soit tu prends un template actif lié à la formation (M2M) dans FormateurSatisfactionTemplate
    $tpl = $this->resolveTemplateForSession($session);
    if (!$tpl) return 0;

    $formateurs = $this->resolveFormateurs($session);
    if (count($formateurs) === 0) return 0;

    $repo = $this->em->getRepository(FormateurSatisfactionAssignment::class);

    $created = 0;
    foreach ($formateurs as $f) {
      $exists = $repo->findOneBy([
        'session'   => $session,
        'formateur' => $f,
        'template'  => $tpl,
      ]);
      if ($exists) continue;

      $a = (new FormateurSatisfactionAssignment())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setSession($session)
        ->setFormateur($f)
        ->setTemplate($tpl)
        ->setIsRequired(true);

      $this->em->persist($a);
      $created++;
    }

    return $created;
  }

  /**
   * Synchronize existing questionnaires only when the effective teaching team changes.
   * Never transfer an attempt, even an empty one: its original author is evidence.
   * @param list<int> $previousUserIds
   */
  public function synchronizeReplacement(Session $session, Utilisateur $actor, Entite $entite, array $previousUserIds): void
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) throw new \InvalidArgumentException('Organisme incohérent.');
    $targets = [];
    foreach ($this->resolveFormateurs($session) as $user) $targets[$user->getId()] = $user;
    if (!$targets) return;
    $outgoing = array_diff($previousUserIds, array_keys($targets));
    if (!$outgoing) return;
    $groups = [];
    foreach ($this->em->getRepository(FormateurSatisfactionAssignment::class)->findBy(['session'=>$session, 'entite'=>$entite]) as $assignment) {
      $groups[$assignment->getTemplate()->getId()][] = $assignment;
    }
    foreach ($groups as $assignments) {
      $source = null; $movable = []; $assigned = [];
      foreach ($assignments as $assignment) {
        $id = $assignment->getFormateur()?->getId();
        if (isset($targets[$id])) $assigned[$id] = true;
        if (in_array($id, $outgoing, true)) {
          $source ??= $assignment;
          if (!$assignment->getAttempt()) $movable[] = $assignment;
        }
      }
      if (!$source) continue;
      foreach ($targets as $id => $user) {
        if (isset($assigned[$id])) continue;
        $assignment = array_shift($movable);
        if (!$assignment) {
          $assignment = (new FormateurSatisfactionAssignment())->setSession($session)->setEntite($entite)
            ->setCreateur($actor)->setTemplate($source->getTemplate())->setIsRequired($source->isRequired());
          $this->em->persist($assignment);
        }
        $assignment->setFormateur($user);
        // A late replacement must be able to answer, without reopening any submitted work.
        if (!(new \App\Service\Satisfaction\SatisfactionAccess())->canFillTrainer($assignment)
            && $session->getDateFin() && $session->getDateFin() < new \DateTimeImmutable()
            && $session->getStatus() !== StatusSession::CANCELED) {
          $assignment->reopenUntil(new \DateTimeImmutable('+7 days', new \DateTimeZone('Europe/Paris')));
        }
        $assigned[$id] = true;
      }
      // A replacement already assigned to this template must not leave duplicate empty obligations.
      foreach ($movable as $obsolete) $this->em->remove($obsolete);
    }
  }

  public function ensureAttempt(FormateurSatisfactionAssignment $assignment, Utilisateur $user, Entite $entite): FormateurSatisfactionAttempt
  {
    if ($assignment->getAttempt()) return $assignment->getAttempt();

    $attempt = (new FormateurSatisfactionAttempt())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setAssignment($assignment);

    $assignment->setAttempt($attempt);

    $this->em->persist($attempt);
    return $attempt;
  }

  private function resolveTemplateForSession(Session $session): ?FormateurSatisfactionTemplate
  {
    $formation = $session->getFormation();
    if (!$formation) return null;

    // ✅ Option A (recommandé) : champ sur Formation
    if (method_exists($formation, 'getFormateurSatisfactionTemplate') && $formation->getFormateurSatisfactionTemplate()) {
      return $formation->getFormateurSatisfactionTemplate();
    }

    // ✅ Option B : chercher dans les templates actifs de l’entité, filtrés par formation M2M
    $entite = $session->getEntite();
    if (!$entite) return null;

    return $this->em->getRepository(FormateurSatisfactionTemplate::class)->createQueryBuilder('t')
      ->leftJoin('t.formations', 'f')
      ->andWhere('t.entite = :e')->setParameter('e', $entite)
      ->andWhere('t.isActive = 1')
      ->andWhere('f = :fo')->setParameter('fo', $formation)
      ->orderBy('t.id', 'DESC')
      ->setMaxResults(1)
      ->getQuery()->getOneOrNullResult();
  }

  /**
   * Intervenants effectifs du planning, sans ajouter un référent entièrement remplacé.
   *
   * @return Utilisateur[]
   */
  private function resolveFormateurs(Session $session): array
  {
    // Follow the same per-slot fallback as attendance and trainer access.
    // A principal trainer replaced on every slot is not an effective trainer.
    $formateurs = $session->getFormateursEffectifs();

    // 3) Map vers Utilisateur + unique
    $users = [];
    foreach ($formateurs as $f) {
      $u = $f->getUtilisateur();
      if ($u instanceof Utilisateur) {
        $users[] = $u;
      }
    }

    return $this->uniqueUsers($users);
  }


  /** @param Utilisateur[] $users */
  private function uniqueUsers(array $users): array
  {
    $seen = [];
    $out = [];
    foreach ($users as $u) {
      $id = $u->getId();
      if (!$id || isset($seen[$id])) continue;
      $seen[$id] = true;
      $out[] = $u;
    }
    return $out;
  }
}
