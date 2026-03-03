<?php

declare(strict_types=1);

namespace App\Service\Qcm;

use App\Entity\Inscription;
use App\Entity\Qcm;
use App\Entity\QcmAssignment;
use App\Entity\QcmAttempt;
use App\Entity\Session;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Enum\QcmAssignmentStatus;
use App\Enum\QcmPhase;
use Doctrine\ORM\EntityManagerInterface as EM;

final class QcmAssigner
{
  public function __construct(private EM $em) {}

  /**
   * Affecte 2 QCM (PRE + POST) à tous les inscrits d’une session.
   * Anti-doublons par (session, inscription, phase).
   */
  public function assignForSession(Session $session, Qcm $qcmPre, Qcm $qcmPost, $user, $entite): int
  {
    $created = 0;

    foreach ($session->getInscriptions() as $ins) {
      $created += $this->assignForInscription($session, $ins, $qcmPre, QcmPhase::PRE, $user, $entite) ? 1 : 0;
      $created += $this->assignForInscription($session, $ins, $qcmPost, QcmPhase::POST, $user, $entite) ? 1 : 0;
    }

    return $created;
  }

  /**
   * Affecte 1 QCM (phase donnée) à une inscription.
   * Anti-doublon : 1 assignment max par (session, inscription, phase).
   */
  public function assignForInscription(Session $session, Inscription $inscription, Qcm $qcm, QcmPhase $phase, Utilisateur $user, Entite $entite): bool
  {
    $repo = $this->em->getRepository(QcmAssignment::class);

    $exists = $repo->findOneBy([
      'session' => $session,
      'inscription' => $inscription,
      'phase' => $phase,
    ]);
    if ($exists) return false;

    $a = (new QcmAssignment())
      ->setSession($session)
      ->setCreateur($user)
      ->setEntite($entite)
      ->setInscription($inscription)
      ->setQcm($qcm)
      ->setPhase($phase)
      ->setStatus(QcmAssignmentStatus::ASSIGNED);

    $this->em->persist($a);

    return true;
  }

  /**
   * Crée la tentative si absente (pattern Satisfaction).
   */
  public function ensureAttempt(QcmAssignment $assignment, Utilisateur $user, Entite $entite): QcmAttempt
  {
    if ($assignment->getAttempt()) return $assignment->getAttempt();

    $attempt = (new QcmAttempt())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setAssignment($assignment);
    $this->em->persist($attempt);

    return $attempt;
  }
}
