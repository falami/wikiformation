<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\{HabilitationDossier, HabilitationRevision, Inscription};
use App\Service\Habilitation\HabilitationWorkflow;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/** New registrations inherit the formation model; existing dossiers are never silently reassigned. */
#[AsDoctrineListener(event: Events::onFlush)]
final class HabilitationAssignmentListener
{
    public function __construct(private HabilitationWorkflow $workflow, private \App\Service\Habilitation\HabilitationAssignments $assignments) {}

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager(); $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $inscription) {
            if (!$inscription instanceof Inscription) continue;
            $dossier = $this->assignments->prepare($inscription);
            if (!$dossier) continue;
            foreach ($uow->getScheduledEntityInsertions() as $pending) {
                if ($pending instanceof HabilitationDossier && $pending->getInscription() === $inscription) continue 2;
            }
            $revision = $this->workflow->record($dossier, $inscription->getCreateur(), 'assign', ['source' => 'formation', 'model' => $dossier->getTemplate()->getTitre()]);
            foreach ([$dossier, $revision] as $entity) {
                $em->persist($entity); $uow->computeChangeSet($em->getClassMetadata($entity::class), $entity);
            }
        }
    }
}
