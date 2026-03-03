<?php
// src/EventSubscriber/Positioning/InscriptionPositioningSubscriber.php
declare(strict_types=1);

namespace App\EventSubscriber\Positioning;

use App\Entity\Inscription;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Service\Positioning\PositioningAssigner;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

final class InscriptionPositioningSubscriber implements EventSubscriber
{
  public function __construct(private PositioningAssigner $assigner) {}

  public function getSubscribedEvents(): array
  {
    return [Events::postPersist];
  }

  public function postPersist(LifecycleEventArgs $args, Utilisateur $user, Entite $entite): void
  {
    $entity = $args->getObject();
    if (!$entity instanceof Inscription) return;

    // ✅ on attribue les questionnaires de session (si tu gardes cette feature)
    $created = $this->assigner->createAssignmentsForSession($entity->getSession(), $user, $entite);

    if ($created > 0) {
      $args->getObjectManager()->flush();
    }
  }
}
