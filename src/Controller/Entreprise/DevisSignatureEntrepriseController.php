<?php

declare(strict_types=1);
namespace App\Controller\Entreprise;

use App\Entity\{Devis, Entite};
use App\Security\Permission\TenantPermission;
use App\Service\Devis\EntrepriseQuoteSignature;
use App\Service\Entreprise\EntreprisePortalContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/entreprise/{entite}/devis', name: 'app_entreprise_devis_', requirements: ['entite'=>'\d+', 'id'=>'\d+'])]
#[IsGranted(TenantPermission::ENTREPRISE_DOCUMENTS_MANAGE, subject: 'entite')]
final class DevisSignatureEntrepriseController extends AbstractController
{
    #[Route('/{id}/signature', name: 'review', methods: ['GET', 'POST'])]
    public function review(Entite $entite, Devis $devis, Request $request, EntreprisePortalContext $portal, EntrepriseQuoteSignature $signatures): Response
    {
        $company = $portal->resolve($this->getUser(), $entite);
        if ($devis->getEntite()?->getId() !== $entite->getId() || $devis->getEntrepriseDestinataire()?->getId() !== $company->getId()
            || $devis->getStatus() === \App\Enum\DevisStatus::DRAFT) throw $this->createNotFoundException();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('company_quote_'.$devis->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.');
            if (!$request->request->getBoolean('consent')) $this->addFlash('warning', 'Veuillez confirmer votre accord et votre habilitation.');
            else {
                try {
                    $signatures->sign($devis, $this->getUser(), (string)$request->request->get('signatureData', ''), $request->getSession()->get('quote_reviewed_'.$devis->getId()));
                    $request->getSession()->remove('quote_reviewed_'.$devis->getId());
                    $this->addFlash('success', 'Le devis a été signé et accepté pour votre entreprise.');
                } catch (\DomainException $error) { $this->addFlash('warning', $error->getMessage()); }
            }
            return $this->redirectToRoute('app_entreprise_devis_review', ['entite'=>$entite->getId(), 'entreprise'=>$company->getId(), 'id'=>$devis->getId()]);
        }
        if ($request->query->getBoolean('pdf')) {
            try { return $signatures->preview($devis, $request); }
            catch (\DomainException $error) { throw $this->createNotFoundException($error->getMessage()); }
        }
        $response = $this->render('entreprise/documents/devis_signature.html.twig', ['entite'=>$entite, 'entreprise'=>$company, 'devis'=>$devis,
            'unavailableReason'=>EntrepriseQuoteSignature::unavailableReason($devis), 'signature'=>$devis->getMeta()['enterprise_signature'] ?? null]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
