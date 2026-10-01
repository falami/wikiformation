<?php

declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\Entite;
use App\Security\Permission\TenantPermission;
use App\Service\Pdf\BlankTrainingDocumentPdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlankTrainingDocumentController extends AbstractController
{
    #[Route('/formateur/{entite}/documents/modeles/{type}', name: 'app_formateur_documents_modele', methods: ['GET'], requirements: ['entite' => '\d+', 'type' => 'emargement|appreciation-formateur|appreciation-stagiaire'])]
    public function download(Entite $entite, string $type, BlankTrainingDocumentPdf $documents): Response
    {
        if (!$this->isGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE, $entite)
            && !$this->isGranted(TenantPermission::FORMATEUR_MANAGE, $entite)) {
            throw $this->createAccessDeniedException();
        }

        return $documents->download($entite, $type);
    }
}
