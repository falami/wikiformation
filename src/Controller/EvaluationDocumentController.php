<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{Entite, EvaluationDossier, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Evaluation\EvaluationResultNotifier;
use App\Service\Pdf\{OrganisationBranding, PdfManager};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/evaluations/{entite}/{id}', requirements: ['entite' => '\d+', 'id' => '\d+'])]
final class EvaluationDocumentController extends AbstractController
{
    #[Route('/attestation', name: 'app_evaluation_attestation', methods: ['GET'])]
    public function attestation(Entite $entite, EvaluationDossier $dossier, PdfManager $pdf, OrganisationBranding $branding): Response
    {
        $this->checkAccess($entite, $dossier);
        if (!$dossier->isSuccessful()) throw $this->createNotFoundException('Attestation indisponible.');
        return $this->document($entite, $dossier, $pdf, $branding, true);
    }

    #[Route('/bilan', name: 'app_evaluation_results_pdf', methods: ['GET'])]
    public function results(Entite $entite, EvaluationDossier $dossier, PdfManager $pdf, OrganisationBranding $branding): Response
    {
        $this->checkAccess($entite, $dossier);
        if (!$dossier->getPublishedAt()) throw $this->createNotFoundException('Bilan non publié.');
        return $this->document($entite, $dossier, $pdf, $branding, false);
    }

    #[Route('/notification', name: 'app_evaluation_notify', methods: ['POST'])]
    public function notify(Entite $entite, EvaluationDossier $dossier, Request $request, EvaluationResultNotifier $notifier): Response
    {
        $this->checkAccess($entite, $dossier, true);
        if (!$this->isCsrfTokenValid('evaluation_notify_'.$dossier->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException('Jeton invalide.');
        if (!$dossier->getPublishedAt()) throw $this->createNotFoundException('Bilan non publié.');
        $sent = $notifier->notify($dossier);
        $this->addFlash($sent ? 'success' : 'warning', $sent ? 'Les résultats ont été transmis au stagiaire.' : 'Le mail n’a pas pu être transmis. Le bilan reste disponible dans l’espace stagiaire.');
        return $this->redirectToRoute($this->isGranted(TenantPermission::QCM_MANAGE, $entite) ? 'app_administrateur_evaluation_show' : 'app_formateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]);
    }

    private function checkAccess(Entite $entite, EvaluationDossier $dossier, bool $staffOnly = false): void
    {
        foreach ([$dossier->getEntite(), $dossier->getSession()?->getEntite(), $dossier->getInscription()?->getEntite(), $dossier->getTemplate()?->getEntite()] as $tenant) {
            if ($tenant?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        if ($this->isGranted(TenantPermission::QCM_MANAGE, $entite)) return;
        if ($this->isGranted(TenantPermission::FORMATEUR, $entite) && $dossier->getEvaluator()?->getId() === $user->getId()) return;
        if (!$staffOnly && $this->isGranted(TenantPermission::STAGIAIRE_QCM_MANAGE, $entite) && $dossier->getStagiaire()?->getId() === $user->getId()) return;
        throw $this->createAccessDeniedException();
    }

    private function document(Entite $entite, EvaluationDossier $dossier, PdfManager $pdf, OrganisationBranding $branding, bool $attestation): Response
    {
        $html = $this->renderView('evaluation/pdf/result.html.twig', [
            'record' => $dossier->getPublicationRecord(), 'dossier' => $dossier,
            'branding' => $branding->forEntity($entite), 'attestation' => $attestation,
        ]);
        $response = $pdf->createPortrait($html, ($attestation ? 'Attestation-reussite-' : 'Bilan-evaluation-').$dossier->getId());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
