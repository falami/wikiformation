<?php

declare(strict_types=1);

namespace App\Controller\Entreprise;

use App\Entity\{Entite, Entreprise, HabilitationDossier};
use App\Security\Permission\TenantPermission as P;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

use App\Controller\Shared\Habilitation\AbstractHabilitationController;

#[\App\Security\Attribute\RequiresModule('habilitation_electrique')]
#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationController extends AbstractHabilitationController
{
    protected const SPACE = 'entreprise';

    #[Route('/{id}/entreprise', name: 'company_title', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function companyTitle(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request,
        \App\Service\Entreprise\EntreprisePortalContext $portal, \App\Service\Entreprise\EntrepriseHabilitations $documents): Response
    {
        $this->denyAccessUnlessGranted(P::HABILITATION_ENTREPRISE, $entite);
        $entreprise = $portal->resolve($this->getUser(), $entite, true);
        if (!$documents->available($dossier, $entite, $entreprise) || !$documents->current($dossier, 'avis')) throw $this->createNotFoundException();
        // Even an administrator uses the employer's limited, independent copy here.
        $response = $this->editDossier($entite, $dossier, $request, ['admin'=>false, 'trainer'=>false, 'company'=>true, 'student'=>false], $entreprise, $portal->representedCompanies($this->getUser(), $entite));
        return $response;
    }
}
