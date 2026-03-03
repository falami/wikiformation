<?php

namespace App\Controller\Administrateur;

use App\Entity\{Inscription, ConventionContrat, Entite};
use App\Service\Pdf\PdfManager;
use App\Enum\StatusInscription;
use App\Service\AssiduiteCalculator;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Response, BinaryFileResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/pdf', name: 'app_administrateur_pdf_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DOCUMENT_PDF_MANAGE, subject: 'entite')]
class DocumentPdfController extends AbstractController
{
    public function __construct(private PdfManager $pdf) {}

    #[Route('/convocation/{id}', name: 'convocation', methods: ['GET'])]
    public function convocation(Entite $entite, Inscription $id): Response
    {
        $session   = $id->getSession();
        $formation = $session->getFormation();

        $path = $this->pdf->convocation([
            'inscription' => $id,
            'session'     => $session,
            'formation'   => $formation,
            'stagiaire'   => $id->getStagiaire(),
            'entite'      => $entite,
        ], sprintf('convocation-%d.pdf', $id->getId()));

        return new BinaryFileResponse($path);
    }

    /**
     * Attestation de fin de formation pour une inscription.
     * On vérifie le statut + assiduité avant de générer.
     */
    #[Route('/attestation/{id}', name: 'attestation', methods: ['GET'])]
    public function attestation(
        Entite $entite,
        Inscription $id,
        AssiduiteCalculator $assiduiteCalculator,
        EM $em
    ): Response {
        // 1. L’inscription doit être clôturée
        if ($id->getStatus() !== StatusInscription::TERMINE) {
            $this->addFlash(
                'warning',
                'Attention : l’inscription n’est pas clôturée. '
                    . 'Merci de la clôturer (calcul de l’assiduité, validation de la réussite) avant de générer l’attestation.'
            );

            return $this->redirectToRoute('app_administrateur_inscription_show', [
                'entite' => $entite->getId(),
                'id'     => $id->getId(),
            ]);
        }

        // 2. Calcul / mise à jour du taux d’assiduité si besoin
        $pct = $id->getTauxAssiduite();
        if ($pct === null) {
            $pct = $assiduiteCalculator->computeForInscription($id);
            $em->flush();
        }

        // 3. Message d’alerte si assiduité non complète
        if ($pct < 100) {
            $this->addFlash(
                'warning',
                sprintf(
                    'Attention : le taux d’assiduité de ce stagiaire est de %.1f%%. '
                        . 'Merci de vérifier que toutes les feuilles d’émargement sont bien saisies avant de transmettre l’attestation.',
                    $pct
                )
            );
        }

        // 4. Génération du PDF d’attestation
        $session   = $id->getSession();
        $formation = $session->getFormation();

        // Méthode à adapter selon ton PdfManager (nom, signature)
        $path = $this->pdf->attestation([
            'attestation' => $id->getAttestation(),
            'inscription' => $id,
            'session'     => $session,
            'formation'   => $formation,
            'stagiaire'   => $id->getStagiaire(),
            'entite'      => $entite,
            'tauxAssiduite' => $pct,
        ], sprintf('attestation-%d.pdf', $id->getId()));

        return new BinaryFileResponse($path);
    }

    #[Route('/convention/{id}', name: 'convention', methods: ['GET'])]
    public function convention(ConventionContrat $id): Response
    {
        return new BinaryFileResponse($id->getPdfPath());
    }

    // Feuille d’émargements synthèse d’une inscription
    #[Route('/emargements/inscription/{id}', name: 'emargements_inscription', methods: ['GET'])]
    public function emargementsInscription(Entite $entite, Inscription $id, EM $em): Response
    {
        $session = $id->getSession();
        $vars = [
            'inscription' => $id,
            'session'     => $session,
            'jours'       => $session->getJours(),
            'stagiaire'   => $id->getStagiaire(),
            'entite'      => $entite,
        ];
        $path = $this->pdf->feuilleEmargementsSynthese(
            $vars,
            sprintf('EMARGEMENTS_INSCRIPTION_%d.pdf', $id->getId())
        );

        return new BinaryFileResponse($path);
    }
}
