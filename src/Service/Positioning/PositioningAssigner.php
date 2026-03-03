<?php
// src/Service/Positioning/PositioningAssigner.php
declare(strict_types=1);

namespace App\Service\Positioning;

use App\Entity\{
  Inscription,
  Session,
  PositioningAssignment,
  PositioningAttempt,
  PositioningQuestionnaire,
  SessionPositioning,
  Utilisateur,
  Entite
};
use Doctrine\ORM\EntityManagerInterface;

final class PositioningAssigner
{
  public function __construct(private EntityManagerInterface $em) {}

  /**
   * Attribution directe à un stagiaire (sans session/inscription)
   * => crée PositioningAssignment (inscription NULL) + PositioningAttempt si absent.
   */
  public function assignToUser(Utilisateur $stagiaire, PositioningQuestionnaire $questionnaire, Utilisateur $user, Entite $entite, bool $required = false): PositioningAssignment
  {
    $repo = $this->em->getRepository(PositioningAssignment::class);

    // ✅ IMPORTANT : on cherche uniquement l'assignation "directe" (non liée)
    $existing = $repo->findOneBy([
      'stagiaire' => $stagiaire,
      'questionnaire' => $questionnaire,
      'inscription' => null,
      'session' => null,
    ]);

    if ($existing) {
      if ($required && !$existing->isRequired()) {
        $existing->setIsRequired(true);
      }

      // ✅ si l'assignation existe mais pas l'attempt, on le crée
      if (!$existing->getAttempt()) {
        $attempt = (new PositioningAttempt())
          ->setCreateur($user)
          ->setEntite($entite)
          ->setAssignment($existing)
          ->setStagiaire($stagiaire)
          ->setQuestionnaire($questionnaire);

        $existing->setAttempt($attempt); // synchro inverse
        $this->em->persist($attempt);
      }

      return $existing;
    }

    $a = (new PositioningAssignment())
      ->setStagiaire($stagiaire)
      ->setCreateur($user)
      ->setEntite($entite)
      ->setQuestionnaire($questionnaire)
      ->setIsRequired($required);

    $this->em->persist($a);

    $attempt = (new PositioningAttempt())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setAssignment($a)
      ->setStagiaire($stagiaire)
      ->setQuestionnaire($questionnaire);

    // ✅ synchro inverse (très important pour OneToOne mappedBy/inversedBy)
    $a->setAttempt($attempt);

    $this->em->persist($attempt);

    return $a;
  }

  /**
   * Rattache une attribution à une inscription/session.
   * - si une assignation existe déjà pour (inscription + questionnaire) => on la renvoie
   * - sinon on réutilise une assignation directe (si elle existe) puis on la "link"
   * - sinon on en crée une nouvelle et on la link
   */
  public function linkToInscription(Inscription $inscription, PositioningQuestionnaire $questionnaire, Utilisateur $user, Entite $entite, bool $required = false): PositioningAssignment
  {
    $stagiaire = $inscription->getStagiaire();
    $session   = $inscription->getSession();

    $repo = $this->em->getRepository(PositioningAssignment::class);

    // ✅ 1) déjà attribué pour CETTE inscription ?
    $already = $repo->findOneBy([
      'inscription' => $inscription,
      'questionnaire' => $questionnaire,
    ]);

    if ($already) {
      if ($required && !$already->isRequired()) {
        $already->setIsRequired(true);
      }

      // garantit attempt
      if (!$already->getAttempt()) {
        $attempt = (new PositioningAttempt())
          ->setCreateur($user)
          ->setEntite($entite)
          ->setAssignment($already)
          ->setStagiaire($stagiaire)
          ->setQuestionnaire($questionnaire)
          ->setInscription($inscription)
          ->setSession($session);

        $already->setAttempt($attempt);
        $this->em->persist($attempt);
      } else {
        $already->getAttempt()
          ?->setInscription($inscription)
          ->setSession($session);
      }

      return $already;
    }

    // ✅ 2) réutilise une assignation directe si existante
    $a = $this->assignToUser($stagiaire, $questionnaire, $user, $entite, $required);

    $a->setInscription($inscription)
      ->setSession($session)
      ->setLinkedAt(new \DateTimeImmutable());

    if ($required && !$a->isRequired()) {
      $a->setIsRequired(true);
    }

    $attempt = $a->getAttempt();
    if ($attempt) {
      $attempt->setInscription($inscription)->setSession($session);
    }

    return $a;
  }

  /**
   * À partir des SessionPositioning : attribue à tous les inscrits.
   */
  public function createAssignmentsForSession(Session $session, Utilisateur $user, Entite $entite): int
  {
    $count = 0;

    $sps = $this->em->getRepository(SessionPositioning::class)->findBy(
      ['session' => $session],
      ['position' => 'ASC', 'id' => 'ASC']
    );

    if (!$sps) return 0;

    foreach ($session->getInscriptions() as $inscription) {
      foreach ($sps as $sp) {
        $q = $sp->getQuestionnaire();

        // si tu veux gérer "required" via SessionPositioning :
        $required = method_exists($sp, 'isRequired') ? (bool) $sp->isRequired() : false;

        $before = $this->em->getRepository(PositioningAssignment::class)->findOneBy([
          'inscription' => $inscription,
          'questionnaire' => $q,
        ]);

        $this->linkToInscription($inscription, $q, $user, $entite, $required);
        if (!$before) $count++;
      }
    }

    return $count;
  }
}
