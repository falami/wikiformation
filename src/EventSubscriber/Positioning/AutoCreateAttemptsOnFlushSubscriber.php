<?php

declare(strict_types=1);

namespace App\EventSubscriber\Positioning;

use App\Entity\Inscription;
use App\Entity\PositioningAssignment;
use App\Entity\PositioningAttempt;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;

final class AutoCreateAttemptsOnFlushSubscriber implements EventSubscriber
{
  public function getSubscribedEvents(): array
  {
    return [Events::onFlush];
  }

  public function onFlush(OnFlushEventArgs $args): void
  {
    $em  = $args->getObjectManager();
    $uow = $em->getUnitOfWork();

    foreach ($uow->getScheduledEntityInsertions() as $entity) {
      if (!$entity instanceof Inscription) {
        continue;
      }

      $inscription = $entity;
      $session = $inscription->getSession();
      $stagiaire = $inscription->getStagiaire();
      $createur = $inscription->getCreateur();
      $entite = $inscription->getEntite();
      if (!$session || !$stagiaire || !$createur || !$entite) {
        continue;
      }

      // Inclut les questionnaires d'une session créée dans cette transaction.
      foreach ($session->getSessionPositionings() as $positioning) {
        $questionnaire = $positioning->getQuestionnaire();
        if (!$questionnaire) {
          continue;
        }

        $assignment = null;
        foreach (array_merge($inscription->getPositioningAssignments()->toArray(), $uow->getScheduledEntityInsertions()) as $candidate) {
          if ($candidate instanceof PositioningAssignment
            && $candidate->getInscription() === $inscription
            && $candidate->getQuestionnaire() === $questionnaire) {
            $assignment = $candidate;
            break;
          }
        }

        if (!$assignment) {
          $assignment = (new PositioningAssignment())
            ->setCreateur($createur)
            ->setEntite($entite)
            ->setSession($session)
            ->setInscription($inscription)
            ->setStagiaire($stagiaire)
            ->setQuestionnaire($questionnaire)
            ->setIsRequired($positioning->isRequired())
            ->setLinkedAt(new \DateTimeImmutable());
          $inscription->addPositioningAssignment($assignment);
          $em->persist($assignment);
          $uow->computeChangeSet($em->getClassMetadata(PositioningAssignment::class), $assignment);
        }

        if ($assignment->getAttempt()) {
          continue;
        }

        $attempt = (new PositioningAttempt())
          ->setCreateur($createur)
          ->setEntite($entite)
          ->setAssignment($assignment)
          ->setSession($session)
          ->setInscription($inscription)
          ->setStagiaire($stagiaire)
          ->setQuestionnaire($questionnaire);
        $assignment->setAttempt($attempt);
        $inscription->addPositioningAttempt($attempt);
        $em->persist($attempt);
        $uow->computeChangeSet($em->getClassMetadata(PositioningAttempt::class), $attempt);
      }
    }
  }
}
