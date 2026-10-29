<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Session, Entite, Formateur, ContratFormateur, AuditLog};
use App\Enum\ContratFormateurStatus;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SessionTrainerCoverageController extends AbstractController
{
    #[Route('/administrateur/{entite}/session/{id}/couverture-intervenants', name: 'app_admin_session_trainer_coverage', methods: ['GET', 'POST'])]
    #[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
    public function edit(Entite $entite, Session $session, Request $request, EntityManagerInterface $em): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $contracts = array_values(array_filter($em->getRepository(ContratFormateur::class)->findBy(['entite'=>$entite, 'session'=>$session]), static fn($c) => $c->getStatus() !== ContratFormateurStatus::RESILIE));
        $trainers = array_values(array_filter($session->getFormateursEffectifs(), static fn($f) => $f->getEntite()?->getId() === $entite->getId()));
        $builder = $this->createFormBuilder();
        foreach ($contracts as $contract) {
            // Preserve historical coverage even when an intervenant is no longer on the planning.
            $choices = $trainers;
            foreach ($contract->getIntervenantsCouverts() as $trainer) if (!in_array($trainer, $choices, true) && $trainer->getEntite()?->getId() === $entite->getId()) $choices[] = $trainer;
            $builder->add('contract_'.$contract->getId(), EntityType::class, ['class'=>Formateur::class, 'choices'=>$choices, 'multiple'=>true, 'expanded'=>true, 'required'=>false, 'label'=>false,
                'choice_label'=>static fn(Formateur $f) => trim($f->getUtilisateur()?->getPrenom().' '.$f->getUtilisateur()?->getNom()), 'data'=>$contract->getIntervenantsCouverts()->toArray()]);
        }
        $form = $builder->getForm(); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->wrapInTransaction(function () use ($contracts, $form, $em, $entite, $session): void {
                $changes = [];
                foreach ($contracts as $contract) {
                    $before = $contract->getIntervenantsCouverts()->map(fn($f) => $f->getId())->toArray();
                    foreach ($contract->getIntervenantsCouverts()->toArray() as $trainer) $contract->removeIntervenantCouvert($trainer);
                    foreach ($form->get('contract_'.$contract->getId())->getData() as $trainer) $contract->addIntervenantCouvert($trainer);
                    $changes[] = ['contractId'=>$contract->getId(), 'before'=>$before, 'after'=>$contract->getIntervenantsCouverts()->map(fn($f) => $f->getId())->toArray()];
                }
                $em->persist((new AuditLog())->setEntite($entite)->setActor($this->getUser())->setCreateur($this->getUser())->setCreatedAt(new \DateTimeImmutable())->setEvent('session_trainer_coverage_updated')->setPayload(['sessionId'=>$session->getId(), 'changes'=>$changes]));
            });
            $this->addFlash('success', 'Rattachements enregistrés. Les titulaires, montants et documents signés des contrats sont conservés.');
            return $this->redirectToRoute('app_administrateur_session_show', ['entite'=>$entite->getId(), 'id'=>$session->getId()]);
        }
        return $this->render('administrateur/session/trainer_coverage.html.twig', ['entite'=>$entite, 'session'=>$session, 'contracts'=>$contracts, 'form'=>$form->createView()]);
    }
}
