<?php

declare(strict_types=1);
namespace App\Service\Convention;

use App\Entity\{Inscription, SatisfactionAssignment};
use App\Service\Attendance\{AttendancePdfData, IndividualAttendanceSheets};
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class EnterpriseLearnerDocuments
{
    public function __construct(private EntityManagerInterface $em, private AttendancePdfData $attendance,
        private IndividualAttendanceSheets $sheets, private PdfManager $pdf, private Environment $twig,
        private ConventionDossier $dossier) {}

    /** The caller must authorize the inscription before generating its documents. */
    public function build(Inscription $inscription, Request $request): array
    {
        $session = $inscription->getSession();
        $tenant = $inscription->getEntite();
        $person = $inscription->getStagiaire();
        $context = ['entite'=>$tenant, 'inscription'=>$inscription, 'session'=>$session,
            'stagiaire'=>$person, 'formation'=>$session->getFormation(), 'entreprise'=>$inscription->getEntreprise()];
        $files = ['Convocation.pdf'=>$this->pdf->outputPortrait($this->twig->render('pdf/convocation.html.twig', $context))];
        if ($inscription->getAttestation()) {
            $files['Attestation.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/attestation.html.twig', $context + ['attestation'=>$inscription->getAttestation()]));
        }
        $data = $this->attendance->build($session, $request);
        $sheets = array_values(array_filter($this->sheets->build($session, $data['meta']['joursList'], $data['linesByDate']),
            static fn(array $sheet): bool => $sheet['id'] === 'user:'.$person->getId()));
        if ($sheets) $files['Emargements.pdf'] = $this->pdf->createLandscapeBytes($this->twig->render('pdf/emargement_sheet.html.twig', $data + ['individualSheets'=>$sheets]));
        $reference = ['entite'=>$tenant, 'numero'=>'—', 'session'=>$session,
            'intituleFormationEffectif'=>$session->getFormation()?->getTitre() ?? $session->getFormationIntituleLibre()];
        foreach ($this->em->getRepository(SatisfactionAssignment::class)->findBy(['entite'=>$tenant,'session'=>$session,'stagiaire'=>$person]) as $assignment) {
            if (!$assignment->getAttempt()?->getSubmittedAt() || $assignment->getAttempt()->getEntite()?->getId() !== $tenant->getId()) continue;
            $files['Satisfaction-'.$assignment->getId().'.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/convention_appreciation.html.twig', [
                'convention'=>$reference,'name'=>trim($person->getPrenom().' '.$person->getNom()),
                'assignment'=>$assignment,'answers'=>$this->dossier->answers($assignment),
            ]));
        }
        return $files;
    }
}
