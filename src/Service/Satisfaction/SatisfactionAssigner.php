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

    $existing = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy([
      'session' => $session,
      'stagiaire' => $stagiaire,
      'template' => $tpl,
    ]);
    if ($existing) return;

    $a = (new SatisfactionAssignment())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setSession($session)
      ->setStagiaire($stagiaire)
      ->setTemplate($tpl)
      ->setIsRequired(true);

    $this->em->persist($a);
  }


  /**
   * Crée 1 affectation SatisfactionAssignment par stagiaire (si template défini sur la formation)
   * Anti-doublons
   */
  public function assignForSession(Session $session, Utilisateur $user, Entite $entite): int
  {
    $formation = $session->getFormation();
    if (!$formation) return 0;

    $template = $formation->getSatisfactionTemplate();
    if (!$template) return 0; // ✅ rien à créer si la formation n’a pas de template

    $repo = $this->em->getRepository(SatisfactionAssignment::class);

    $created = 0;

    /** @var Inscription $ins */
    foreach ($session->getInscriptions() as $ins) {
      $stagiaire = $ins->getStagiaire();
      if (!$stagiaire) continue;

      // ✅ anti-doublon
      $exists = $repo->findOneBy([
        'session'   => $session,
        'stagiaire' => $stagiaire,
        'template'  => $template,
      ]);
      if ($exists) continue;

      $a = (new SatisfactionAssignment())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setSession($session)
        ->setStagiaire($stagiaire)
        ->setTemplate($template)
        ->setIsRequired(true);

      $this->em->persist($a);
      $created++;
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
