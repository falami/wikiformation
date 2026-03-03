<?php

declare(strict_types=1);

namespace App\EventSubscriber\Positioning;

use App\Entity\Inscription;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Entity\PositioningAttempt;
use App\Entity\SessionPositioning;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;

final class AutoCreateAttemptsOnFlushSubscriber implements EventSubscriber
{
  public function getSubscribedEvents(): array
  {
    return [Events::onFlush];
  }

  public function onFlush(OnFlushEventArgs $args, Utilisateur $user, Entite $entite): void
  {
    $em  = $args->getObjectManager();
    $uow = $em->getUnitOfWork();

    foreach ($uow->getScheduledEntityInsertions() as $entity) {
      if (!$entity instanceof Inscription) {
        continue;
      }

      $this->handleNewInscription($em, $uow, $entity, $user, $entite);
    }
  }

  private function handleNewInscription(EntityManagerInterface $em, $uow, Inscription $inscription, Utilisateur $user, Entite $entite): void
  {
    $session   = $inscription->getSession();
    $stagiaire = $inscription->getStagiaire();

    if (!$session || !$stagiaire) {
      return;
    }

    $sessionPositionings = $em->getRepository(SessionPositioning::class)->findBy(
      ['session' => $session],
      ['position' => 'ASC', 'id' => 'ASC']
    );

    foreach ($sessionPositionings as $sp) {
      $q = $sp->getQuestionnaire();

      $attempt = (new PositioningAttempt())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setSession($session)
        ->setInscription($inscription)
        ->setStagiaire($stagiaire)
        ->setQuestionnaire($q);

      $em->persist($attempt);

      $meta = $em->getClassMetadata(PositioningAttempt::class);
      $uow->computeChangeSet($meta, $attempt);
    }
  }
}
