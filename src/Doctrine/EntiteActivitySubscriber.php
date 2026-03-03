<?php

namespace App\Doctrine;

use App\Entity\Entite;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;

final class EntiteActivitySubscriber implements EventSubscriber
{
  public function getSubscribedEvents(): array
  {
    return [Events::onFlush];
  }

  public function onFlush(OnFlushEventArgs $args): void
  {
    $em  = $args->getObjectManager();
    $uow = $em->getUnitOfWork();

    $touched = [];

    // inserts + updates
    foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $entity) {
      if ($entity instanceof Entite) continue;
      if (!method_exists($entity, 'getEntite')) continue;

      $entite = $entity->getEntite();
      if (!$entite instanceof Entite) continue;

      $oid = spl_object_id($entite);
      if (isset($touched[$oid])) continue;
      $touched[$oid] = true;

      $entite->touchActivity();

      $meta = $em->getClassMetadata(Entite::class);
      // IMPORTANT : planifier l'update de Entite dans le même flush
      $uow->computeChangeSet($meta, $entite);
    }
  }
}
