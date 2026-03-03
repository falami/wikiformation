<?php

declare(strict_types=1);

namespace App\Service\FormateurSatisfaction;

use App\Entity\FormateurSatisfactionAssignment;
use App\Entity\FormateurSatisfactionTemplate;
use App\Entity\{Session, Utilisateur, Entite, FormateurSatisfactionAttempt, Formateur};
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
    if (method_exists($session, 'getStatus') && $session->getStatus() !== StatusSession::FULL) {
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
   * ✅ Très robuste : essaye plusieurs stratégies pour récupérer les formateurs.
   * Adapte simplement les method_exists à ton modèle (ContratFormateur, etc.).
   *
   * @return Utilisateur[]
   */
  private function resolveFormateurs(Session $session): array
  {
    $formateurs = [];

    // 1) Formateur “référent” de session
    if (method_exists($session, 'getFormateur')) {
      $f = $session->getFormateur();
      if ($f instanceof Formateur) {
        $formateurs[] = $f;
      }
    }

    // 2) Formateurs par jour (si géré)
    foreach ($session->getJours() as $jour) {
      if (method_exists($jour, 'getFormateur')) {
        $jf = $jour->getFormateur();
        if ($jf instanceof Formateur) {
          $formateurs[] = $jf;
        }
      }
    }

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
