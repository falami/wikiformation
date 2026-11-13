<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Entite, Entreprise, HabilitationDossier};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

use App\Controller\Shared\Habilitation\AbstractHabilitationController;

#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationController extends AbstractHabilitationController
{
    protected const SPACE = 'stagiaire';

    #[Route('/stagiaire', name: 'student_index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Entreprise\EntreprisePortalContext $portal): Response
    {
        return $this->listDossiers($entite, $request, $portal);
    }

    #[Route('/stagiaire/{id}', name: 'student_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $rights = $this->access($entite, $dossier);
        if (!$rights['student'] || !$dossier->getActive() || $dossier->getDeleted()) throw $this->createAccessDeniedException();
        $rights = ['admin'=>false, 'trainer'=>false, 'company'=>false, 'student'=>true];
        return $this->editDossier($entite, $dossier, $request, $rights);
    }
}
