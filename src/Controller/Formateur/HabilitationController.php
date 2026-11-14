<?php

declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{Entite, Entreprise, HabilitationDossier};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

use App\Controller\Shared\Habilitation\AbstractHabilitationController;

#[\App\Security\Attribute\RequiresModule('habilitation_electrique')]
#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationController extends AbstractHabilitationController
{
    protected const SPACE = 'formateur';

    #[Route('/formateur', name: 'trainer_index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Entreprise\EntreprisePortalContext $portal): Response
    {
        return $this->listDossiers($entite, $request, $portal);
    }

    #[Route('/formateur/{id}', name: 'trainer_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $rights = $this->access($entite, $dossier);
        if (!$rights['trainer'] || !$dossier->getActive() || $dossier->getDeleted()) throw $this->createAccessDeniedException();
        $rights = ['admin'=>false, 'trainer'=>true, 'company'=>false, 'student'=>false];
        return $this->editDossier($entite, $dossier, $request, $rights);
    }
}
