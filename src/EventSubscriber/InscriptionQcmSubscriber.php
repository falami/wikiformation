<?php

namespace App\EventSubscriber;

use App\Entity\Inscription;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Enum\StatusInscription;
use App\Service\Qcm\QcmAssignmentManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

final class InscriptionQcmSubscriber implements EventSubscriber
{
  public function __construct(private QcmAssignmentManager $manager) {}

  public function getSubscribedEvents(): array
  {
    return [Events::preUpdate];
  }

  public function preUpdate(PreUpdateEventArgs $args, Utilisateur $user, Entite $entite): void
  {
    $entity = $args->getObject();
    if (!$entity instanceof Inscription) return;

    if (!$args->hasChangedField('status')) return;

    $new = $args->getNewValue('status');
    if (!$new instanceof StatusInscription) return;

    // 👉 adapte si ton enum s'appelle autrement
    if ($new === StatusInscription::TERMINE || $new === StatusInscription::CONFIRME) {
      $this->manager->ensurePreAndPostAssignments($entity, $user, $entite);
    }
  }
}
