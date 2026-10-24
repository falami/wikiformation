<?php
namespace App\EventSubscriber;
use App\Entity\{Session,Inscription};
use App\Service\Document\SessionCertificates;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
#[AsDoctrineListener(event: Events::onFlush)]
final class SessionCertificatesListener {
 public function __construct(private SessionCertificates $certificates) {}
 public function onFlush(OnFlushEventArgs $event): void {
  $em=$event->getObjectManager(); $uow=$em->getUnitOfWork(); $inscriptions=[];
  foreach (array_merge($uow->getScheduledEntityInsertions(),$uow->getScheduledEntityUpdates()) as $entity) {
   if ($entity instanceof Session) foreach ($entity->getInscriptions() as $i) $inscriptions[spl_object_id($i)]=$i;
   if ($entity instanceof Inscription) $inscriptions[spl_object_id($entity)]=$entity;
  }
  foreach ($inscriptions as $i) if ($att=$this->certificates->prepare($i)) {
   $em->persist($att); $uow->computeChangeSet($em->getClassMetadata($att::class),$att);
  }
 }
}
