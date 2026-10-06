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
    public function __construct(private HabilitationWorkflow $workflow) {}

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager(); $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $inscription) {
            if (!$inscription instanceof Inscription) continue;
            $session = $inscription->getSession(); $entite = $inscription->getEntite();
            $template = $session?->getFormation()?->getHabilitationTemplate();
            $company = $inscription->getEntreprise() ?? $inscription->getStagiaire()?->getEntreprise();
            $formateur = $session?->getFormateur();
            if (!$formateur && $session && count($session->getFormateursEffectifs()) === 1) $formateur = $session->getFormateursEffectifs()[0];
            $creator = $inscription->getCreateur();
            if (!$entite || !$template?->getActive() || !$company || !$formateur?->getUtilisateur() || !$creator) continue;
            if ($session->getEntite() !== $entite || $template->getEntite() !== $entite || $company->getEntite() !== $entite || $formateur->getEntite() !== $entite) continue;
            foreach ($uow->getScheduledEntityInsertions() as $pending) {
                if ($pending instanceof HabilitationDossier && $pending->getInscription() === $inscription) continue 2;
            }
            $dossier = (new HabilitationDossier())->setEntite($entite)->setInscription($inscription)
                ->setTemplate($template)->setSchema($template->getSchema())->setEntreprise($company)->setFormateur($formateur->getUtilisateur());
            $revision = $this->workflow->record($dossier, $creator, 'assign', ['source' => 'formation', 'model' => $template->getTitre()]);
            foreach ([$dossier, $revision] as $entity) {
                $em->persist($entity); $uow->computeChangeSet($em->getClassMetadata($entity::class), $entity);
            }
        }
    }
}
