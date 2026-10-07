<?php

namespace App\Service\Qcm;

use App\Entity\Inscription;
use App\Entity\QcmAssignment;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Enum\QcmPhase;
use App\Enum\QcmAssignmentStatus;
use App\Repository\QcmAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;

final class QcmAssignmentManager
{
  public function __construct(
    private EntityManagerInterface $em,
    private QcmAssignmentRepository $assignRepo,
  ) {}

  public function ensurePreAndPostAssignments(Inscription $inscription, Utilisateur $user, Entite $entite, bool $flush = true): void
  {
    $session = $inscription->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()
        || $inscription->getEntite()?->getId() !== $entite->getId()) return;
    $formation = $session->getFormation();
    if (!$formation || $formation->getEntite()?->getId() !== $entite->getId()) return;

    foreach ([QcmPhase::PRE, QcmPhase::POST] as $phase) {
      $qcm = $phase === QcmPhase::PRE ? $formation->getQcmPre() : $formation->getQcmPost();
      if (!$qcm || !$qcm->isActive() || $qcm->getEntite()?->getId() !== $entite->getId()
          || $inscription->isAutomaticQcmExcluded($phase)) continue;
      $existing = null;
      foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
        if ($pending instanceof QcmAssignment && $pending->getInscription() === $inscription && $pending->getPhase() === $phase) {
          $existing = $pending;
          break;
        }
      }
      if (!$existing && $inscription->getId() !== null) {
        $existing = $this->assignRepo->findOneByInscriptionAndPhase($inscription, $phase);
      }
      if ($existing) continue;

      $a = (new QcmAssignment())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setSession($session)
        ->setInscription($inscription)
        ->setQcm($qcm)
        ->setPhase($phase)
        ->setStatus(QcmAssignmentStatus::ASSIGNED);

      $this->em->persist($a);
      $inscription->addQcmAssignment($a);
    }

    if ($flush) {
      $this->em->flush();
    }
  }
}
