<?php
// src/Controller/Formateur/EmargementPdfController.php
declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{Emargement, Entite, Session, Utilisateur};
use App\Enum\DemiJournee;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;


#[IsGranted(TenantPermission::FORMATEUR_EMARGEMENT_PDF_MANAGE, subject: 'entite')]
class EmargementPdfController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}
    #[Route(
        '/formateur/{entite}/emargement/pdf',
        name: 'app_formateur_emargement_pdf',
        methods: ['GET'],
        requirements: ['entite' => '\d+']
    )]
    public function pdf(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        PdfManager $pdf,
        \App\Service\Attendance\IndividualAttendanceSheets $individualSheets,
        \App\Service\Attendance\AttendancePdfData $attendanceData
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // --- Paramètres : session=ID
        $sessionId = (int) $request->query->get('session', 0);
        if ($sessionId <= 0) {
            throw $this->createNotFoundException('Paramètre "session" manquant ou invalide.');
        }

        /** @var Session|null $session */
        $session = $em->getRepository(Session::class)->find($sessionId);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException('Session introuvable.');
        if (!$session->hasFormateurUtilisateur($user) && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException('Accès refusé à cette session.');
        }
        $data = $attendanceData->build($session, $request);
        $meta = $data['meta'];
        $joursList = $meta['joursList'];
        $linesByDate = $data['linesByDate'];

        $individual = $request->query->get('format') === 'individual';
        $sheets = $individual ? $individualSheets->build($session, $joursList, $linesByDate, $em->getRepository(\App\Entity\SessionParticipantAccess::class)->findBy(['session'=>$session, 'entite'=>$entite])) : null;
        if ($individual && !$sheets) throw $this->createNotFoundException('Aucun stagiaire à inclure dans les feuilles individuelles.');

        // --- Rendu HTML
        $html = $this->renderView('pdf/emargement_sheet.html.twig', [
            'meta'        => $meta,
            'individualSheets' => $sheets,
            'linesByDate' => $linesByDate,
            'preferences' => $entite->getPreferences(),
        ]);

        $filename = sprintf(
            $individual ? 'EMARGEMENTS_SEPARES_%s_%s' : 'EMARGEMENT_%s_%s',
            $meta['code'] ?: 'session',
            date('Ymd')
        );

        return $pdf->createLandscape($html, $filename);
    }


    private function isEntiteAdmin(Entite $entite): bool
    {
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
        return $ue?->isTenantAdmin() ?? false; // TENANT_ADMIN ou TENANT_DIRIGEANT
    }
}
