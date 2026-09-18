<?php

namespace App\EventSubscriber;

use App\Entity\Inscription;
use App\Entity\QcmAssignment;
use App\Enum\StatusInscription;
use App\Service\Qcm\QcmAssignmentManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

final class InscriptionQcmSubscriber implements EventSubscriber
{
  public function __construct(private QcmAssignmentManager $manager) {}

  public function getSubscribedEvents(): array
  {
    return [Events::onFlush];
  }

  public function onFlush(OnFlushEventArgs $args): void
  {
    $em = $args->getObjectManager();
    $uow = $em->getUnitOfWork();

    foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $inscription) {
      if (!$inscription instanceof Inscription
        || !in_array($inscription->getStatus(), [StatusInscription::TERMINE, StatusInscription::CONFIRME], true)) {
        continue;
      }

      $changes = $uow->getEntityChangeSet($inscription);
      $createur = $inscription->getCreateur();
      $entite = $inscription->getEntite();
      if (!isset($changes['status']) || !$createur || !$entite) {
        continue;
      }

      $this->manager->ensurePreAndPostAssignments($inscription, $createur, $entite, flush: false);

      foreach ($uow->getScheduledEntityInsertions() as $assignment) {
        if ($assignment instanceof QcmAssignment && $assignment->getInscription() === $inscription) {
          $uow->computeChangeSet($em->getClassMetadata(QcmAssignment::class), $assignment);
        }
      }
    }
  }
}
