<?php

declare(strict_types=1);

namespace App\Service\Satisfaction;

use App\Entity\Inscription;
use App\Entity\SatisfactionAssignment;
use App\Entity\SatisfactionAttempt;
use App\Entity\{Session, Utilisateur, Entite};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityManagerInterface as EM;

final class SatisfactionAssigner
{
  public function __construct(private EM $em) {}

  public function assignForSessionIfFull(Session $session, Utilisateur $user, Entite $entite): void
  {
    if ($session->getStatus() !== StatusSession::FULL) return;

    $tpl = $session->getFormation()?->getSatisfactionTemplate();
    if (!$tpl) return;

    foreach ($session->getInscriptions() as $ins) {
      $this->assignForInscriptionIfEligible($ins, $user, $entite);
    }
  }

  public function assignForInscriptionIfEligible(Inscription $inscription, Utilisateur $user, Entite $entite): void
  {
      $session = $inscription->getSession();
      if (!$session) return;

      if ($session->getStatus() !== StatusSession::FULL) return;

      $tpl = $session->getFormation()?->getSatisfactionTemplate();
      if (!$tpl) return;

      $stagiaire = $inscription->getStagiaire();
      if (!$stagiaire) return;

      $repo = $this->em->getRepository(SatisfactionAssignment::class);

      // ✅ anti-doublon "par inscription"
      $existing = $repo->findOneBy([
          'inscription' => $inscription,
          'template'    => $tpl,
      ]);
      if ($existing) return;

      $a = (new SatisfactionAssignment())
          ->setCreateur($user)
          ->setEntite($entite)
          ->setSession($session)
          ->setStagiaire($stagiaire)
          ->setTemplate($tpl)
          ->setIsRequired(true)
          ->setInscription($inscription); // ✅ LE LIEN IMPORTANT

      // optionnel mais clean: synchro inverse
      // $inscription->addSatisfactionAssignment($a);

      $this->em->persist($a);
  }


  /**
   * Crée 1 affectation SatisfactionAssignment par stagiaire (si template défini sur la formation)
   * Anti-doublons
   */
  public function assignForSession(Session $session, Utilisateur $user, Entite $entite): int
  {
      if ($session->getStatus() !== StatusSession::FULL) return 0;

      $tpl = $session->getFormation()?->getSatisfactionTemplate();
      if (!$tpl) return 0;

      $created = 0;
      foreach ($session->getInscriptions() as $ins) {
          $created += $this->assignForInscriptionIfEligible($ins, $user, $entite) ? 1 : 0;
      }
      return $created;
  }

  /**
   * Optionnel : crée la tentative (attempt) si absente
   */
  public function ensureAttempt(SatisfactionAssignment $assignment, Utilisateur $user, Entite $entite): SatisfactionAttempt
  {
    if ($assignment->getAttempt()) {
      return $assignment->getAttempt();
    }

    $attempt = (new SatisfactionAttempt())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setAssignment($assignment);

    $this->em->persist($attempt);

    return $attempt;
  }
}
