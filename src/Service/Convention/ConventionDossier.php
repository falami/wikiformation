<?php
declare(strict_types=1);
namespace App\Service\Convention;

use App\Entity\{ConventionContrat, SessionParticipantAccess, SatisfactionAssignment};
use App\Enum\StatusSession;
use App\Service\Attendance\{AttendancePdfData, IndividualAttendanceSheets};
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse};
use Twig\Environment;

/** Documents restricted to the convention roster, never the whole company/session. */
final class ConventionDossier
{
    public function __construct(private EntityManagerInterface $em, private ConventionDocument $document,
        private AttendancePdfData $attendance, private IndividualAttendanceSheets $sheets,
        private PdfManager $pdf, private Environment $twig) {}

    public function inventory(ConventionContrat $c): array
    {
        $session = $c->getSession();
        $tenant = $c->getEntite()?->getId();
        if (!$tenant || !$session || $session->getEntite()?->getId() !== $tenant
            || !$c->getEntreprise() || $c->getEntreprise()->getEntite()?->getId() !== $tenant) {
            throw new \DomainException('Une session et une entreprise du même organisme doivent être rattachées à la convention.');
        }
        $people = [];
        foreach ($c->getInscriptions() as $i) {
            if ($i->getEntite()?->getId() !== $tenant || $i->getSession()?->getId() !== $session->getId()
                || $i->getEntreprise()?->getId() !== $c->getEntreprise()->getId()) {
                throw new \DomainException('Une inscription de la convention ne correspond pas à son entreprise ou à sa session.');
            }
            if ($u = $i->getStagiaire()) $people['user:'.$u->getId()] = trim($u->getPrenom().' '.$u->getNom());
        }
        $accesses = $this->em->getRepository(SessionParticipantAccess::class)->findBy(['convention'=>$c,'session'=>$session,'entite'=>$c->getEntite()]);
        foreach ($accesses as $a) if ($a->isGuest() && $a->isActive()) $people['guest:'.$a->getId()] = $a->getDisplayName();
        $assignments = [];
        foreach ($this->em->getRepository(SatisfactionAssignment::class)->findBy(['session'=>$session,'entite'=>$c->getEntite()]) as $a) {
            $key = $a->getStagiaire() ? 'user:'.$a->getStagiaire()->getId() : 'guest:'.$a->getParticipantAccess()?->getId();
            if (isset($people[$key]) && $a->getAttempt()?->getSubmittedAt() && $a->getAttempt()->getEntite()?->getId() === $tenant) $assignments[$key][] = $a;
        }
        $warnings = [];
        if (!$c->isSigned()) $warnings[] = 'La convention n’est pas encore signée : le PDF sera un aperçu non signé.';
        if (!$people) $warnings[] = 'Aucun participant identifié dans cette convention.';
        if ($c->getEffectifTotal() > count($people)) $warnings[] = 'Certains participants prévus ne sont pas encore rattachés à un compte ou à un accès invité.';
        if (!$session->isSousTraitance()) foreach ($people as $key=>$name) if (!isset($assignments[$key])) $warnings[] = 'Appréciation non disponible : '.$name.'.';
        return ['people'=>$people,'accesses'=>$accesses,'assignments'=>$assignments,'warnings'=>$warnings,
            'finished'=>$session->isTerminee() && $session->getStatus() !== StatusSession::CANCELED];
    }

    /** @return array<string,string> Filename => PDF bytes. */
    public function documents(ConventionContrat $c, Request $request): array
    {
        $inventory = $this->inventory($c);
        $files = ['Convention-'.$c->getId().'.pdf'=>$this->bytes($this->document->response($c))];
        if (!$c->getSession()->isSousTraitance()) {
            $data = $this->attendance->build($c->getSession(), $request);
            $grouped = [];
            foreach ($this->sheets->build($c->getSession(), $data['meta']['joursList'], $data['linesByDate'], $inventory['accesses']) as $sheet) {
                $key = $sheet['id'];
                if (isset($inventory['people'][$key])) $grouped[$key][] = $sheet;
            }
            foreach ($grouped as $key=>$sheets) {
                $filename = 'Emargements-'.$this->filename($inventory['people'][$key]).'-'.str_replace(':','-', $key).'.pdf';
                $files[$filename] = $this->bytes($this->pdf->createLandscape($this->twig->render('pdf/emargement_sheet.html.twig', $data+['individualSheets'=>$sheets]), 'emargements'));
            }
            foreach ($inventory['assignments'] as $key=>$assignments) foreach ($assignments as $assignment) {
                $files['Appreciation-'.$this->filename($inventory['people'][$key]).'-'.$assignment->getId().'.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/convention_appreciation.html.twig', [
                    'convention'=>$c,'name'=>$inventory['people'][$key], 'assignment'=>$assignment, 'answers'=>$this->answers($assignment),
                ]));
            }
        }
        // Include an explicit inventory: an unsigned/absent document is never presented as complete.
        $files['Inventaire.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/convention_dossier_inventory.html.twig', ['convention'=>$c,'inventory'=>$inventory,'filenames'=>array_keys($files)]));
        return $files;
    }

    public function archive(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dossier-');
        if ($path === false) throw new \RuntimeException('Impossible de créer le dossier.');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Impossible de créer l’archive.');
            foreach ($files as $name=>$bytes) $zip->addFromString($name, $bytes);
            if (!$zip->close()) throw new \RuntimeException('Impossible de finaliser l’archive.');
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new \RuntimeException('Impossible de lire l’archive.');
            return $bytes;
        } finally { @unlink($path); }
    }

    private function answers(SatisfactionAssignment $assignment): array
    {
        $values = $assignment->getAttempt()->getAnswers();
        $rows = [];
        foreach ($assignment->getTemplate()?->getChapters() ?? [] as $chapter) foreach ($chapter->getQuestions() as $q) {
            $value = $values[$q->getId()] ?? null;
            unset($values[$q->getId()]);
            $choices = [];
            foreach ($q->getChoices() ?? [] as $choice) {
                if (is_string($choice)) $choices[$choice] = $choice;
                elseif (is_array($choice)) $choices[(string)($choice['value'] ?? $choice['label'] ?? '')] = (string)($choice['label'] ?? $choice['value'] ?? '');
            }
            if ($value === null) $text = 'Non renseigné';
            elseif ($q->getType()->value === 'yes_no') $text = $value ? 'Oui' : 'Non';
            else {
                $parts = [];
                foreach (is_array($value) ? $value : [$value] as $part) {
                    if ($q->getType()->value === 'multi_formations' && is_numeric($part)) {
                        $formation = $this->em->getRepository(\App\Entity\Formation::class)->findOneBy(['id'=>(int)$part,'entite'=>$assignment->getEntite()]);
                        $parts[] = $formation?->getTitre() ?? 'Formation archivée';
                    } else $parts[] = is_scalar($part) ? ($choices[(string)$part] ?? (string)$part) : json_encode($part, JSON_UNESCAPED_UNICODE);
                }
                $text = implode(', ', $parts);
                if ($q->getType()->value === 'stars') $text .= ' / '.($q->getMaxStars() ?? 5);
            }
            $rows[] = ['chapter'=>$chapter->getTitre(),'question'=>$q->getLibelle(),'value'=>$text];
        }
        // Retain historical answers even if a question has since been removed from its template.
        foreach ($values as $key=>$value) $rows[] = ['chapter'=>'Réponses archivées','question'=>'Question '.$key,'value'=>is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)];
        return $rows;
    }

    private function bytes(Response $response): string
    {
        $bytes = $response instanceof BinaryFileResponse ? file_get_contents($response->getFile()->getPathname()) : $response->getContent();
        if ($bytes === false || $bytes === '') throw new \RuntimeException('Un document ne peut pas être lu.');
        return $bytes;
    }
    private function filename(string $name): string { return preg_replace('/[^\pL\pN_-]+/u','-', $name) ?: 'participant'; }
}
