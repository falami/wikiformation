<?php
declare(strict_types=1);
namespace App\Service\DocumentStudio;
use App\Entity\{Entite,ConventionContrat,ContratFormateur,Devis,Facture,Inscription,Formation,Session,QcmAssignment};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class Preview
{
    public function __construct(private EntityManagerInterface $em, private Environment $twig,
        private \App\Service\Pdf\ContratFormateurDocument $trainer,
        private \App\Service\Attendance\AttendancePdfData $attendance,
        private \App\Service\Convention\ConventionDossier $dossier) {}
    private function entity(string $kind): string {
        return match($kind) {
            'convention_contrat','convention_appreciation','convention_dossier_inventory'=>ConventionContrat::class,
            'contrat_formateur'=>ContratFormateur::class,'devis'=>Devis::class,'facture','facture_acquittee'=>Facture::class,
            'attestation','convocation'=>Inscription::class,'formation_programme_complet'=>Formation::class,
            'emargement_sheet'=>Session::class,'qcm_questionnaire','qcm_result'=>QcmAssignment::class,
            default=>throw new \DomainException('Document inconnu.'),
        };
    }
    public function sources(Entite $tenant,string $kind): array {
        $records=$this->em->getRepository($this->entity($kind))->findBy(['entite'=>$tenant],['id'=>'DESC'],30);
        return array_map(static function($r) {
            $label=method_exists($r,'getNumero')?$r->getNumero():(method_exists($r,'getCode')?$r->getCode():(method_exists($r,'getTitre')?$r->getTitre():'Dossier #'.$r->getId()));
            return ['id'=>$r->getId(),'label'=>$label ?: 'Dossier #'.$r->getId()];
        },$records);
    }
    /** Read-only: never generates a stored document, sends email or records consent. */
    public function source(Entite $tenant,string $kind,int $id,Request $request): array {
        $base=['entite'=>$tenant,'studioBypass'=>true];
        if (!$id) {
            $label=htmlspecialchars(Catalog::requireKind($kind)['label'],ENT_QUOTES);
            $html='<html><head><meta charset="utf-8"><style>@page{margin:32pt}body{font-family:DejaVu Sans;font-size:11pt;color:#233342}.card{padding:16pt;border:1pt solid #dce4ed;margin:16pt 0}.section-title{font-weight:bold;margin-bottom:10pt}</style></head><body><h1>'.$label.'</h1><p>APERÇU DE COMPOSITION - DONNÉES D’EXEMPLE</p><div class="card"><div class="section-title">Contenu du document d’origine</div><p>Les tableaux, les participants, les calculs et les signatures du document actuel seront conservés. Sélectionnez un dossier pour un aperçu avec vos données.</p></div>';
            foreach (Catalog::SECTIONS[$kind] ?? [] as $section) $html.='<div class="card"><div class="section-title">'.htmlspecialchars($section).'</div><p>Texte du modèle d’origine.</p></div>';
            return [$html.'</body></html>',$base];
        }
        $r=$this->em->getRepository($this->entity($kind))->findOneBy(['id'=>$id,'entite'=>$tenant]);
        if (!$r) throw new \DomainException('Ce dossier n’appartient pas à cet organisme.');
        $data=match($kind) {
            'convention_contrat'=>['convention'=>$r,'session'=>$r->getSession()],
            'contrat_formateur'=>$this->trainer->templateData($r),
            'devis'=>['devis'=>$r],
            'facture'=>['facture'=>$r],
            'facture_acquittee'=>$this->paidInvoice($r),
            'attestation','convocation'=>['inscription'=>$r,'stagiaire'=>$r->getStagiaire(),'session'=>$r->getSession(),'formation'=>$r->getSession()?->getFormation(),'attestation'=>$r->getAttestation(),'tauxAssiduite'=>$r->getTauxAssiduite()],
            'formation_programme_complet'=>$this->programme($r),
            'emargement_sheet'=>$this->attendance->build($r,$request)+['session'=>$r,'individualSheets'=>null,'preferences'=>$tenant->getPreferences()],
            'convention_dossier_inventory'=>['convention'=>$r,'inventory'=>$this->dossier->inventory($r),'filenames'=>[]],
            'convention_appreciation'=>$this->appreciation($r),
            'qcm_questionnaire','qcm_result'=>$this->qcm($r,$kind),
        };
        $context=$base+$data;
        return [$this->twig->render('pdf/'.$kind.'.html.twig',$context),$context];
    }
    private function paidInvoice(Facture $invoice): array {
        $payments=$invoice->getPaiements()->toArray();
        usort($payments,static fn($a,$b)=>$a->getDatePaiement()<=>$b->getDatePaiement());
        $paid=array_sum(array_map(static fn($p)=>$p->getMontantCents(),$payments));
        if ($paid < $invoice->getTtcTotalCents()) throw new \DomainException('Choisissez une facture intégralement réglée pour prévisualiser son acquittement.');
        return ['facture'=>$invoice,'paidCents'=>$paid,'remainingCents'=>0,'datePaiement'=>$payments ? end($payments)->getDatePaiement() : null];
    }
    private function programme(Formation $formation): array {
        $chapters=array_values(array_filter($formation->getContentNodes()->toArray(),static fn($n)=>!$n->getParent() && $n->isPublished()));
        usort($chapters,static fn($a,$b)=>[$a->getPosition(),$a->getId()]<=>[$b->getPosition(),$b->getId()]);
        return ['formation'=>$formation,'chapitres'=>$chapters];
    }
    private function appreciation(ConventionContrat $r): array {
        $inventory=$this->dossier->inventory($r);
        foreach($inventory['assignments'] as $key=>$assignments) foreach($assignments as $assignment) {
            return ['convention'=>$r,'session'=>$r->getSession(),'stagiaire'=>$assignment->getStagiaire(),'name'=>$inventory['people'][$key],'assignment'=>$assignment,'answers'=>$this->dossier->answers($assignment)];
        }
        throw new \DomainException('Aucune appréciation soumise pour ce dossier. Choisissez un autre dossier ou l’aperçu de composition.');
    }
    private function qcm(QcmAssignment $r,string $kind): array {
        $attempt=$r->getAttempt();
        $data=['session'=>$r->getSession(),'qcm'=>$r->getQcm(),'phase'=>$r->getPhase()->value,'questions'=>$r->getQcm()->getQuestions(),'generatedAt'=>new \DateTimeImmutable(),'assignment'=>$r,'a'=>$r,'inscription'=>$r->getInscription(),'stagiaire'=>$r->getInscription()?->getStagiaire()];
        if ($kind==='qcm_questionnaire') return $data;
        if (!$attempt) throw new \DomainException('Ce QCM n’a pas encore de résultat. Choisissez un autre dossier.');
        $items=[];
        foreach($r->getQcm()->getQuestions() as $q) {
            $selected=[];foreach($attempt->getAnswers() as $a) if($a->getQuestion()?->getId()===$q->getId())$selected=$a->getSelectedOptionIds();
            $correct=$q->getCorrectOptionIds();sort($selected);sort($correct);
            $items[]=['question'=>$q,'choices'=>$q->getOptions(),'selected'=>$selected,'correctIds'=>$correct,'isCorrect'=>$selected===$correct];
        }
        return $data+['attempt'=>$attempt,'items'=>$items,'scorePoints'=>$attempt->getScorePoints(),'maxPoints'=>$attempt->getMaxPoints(),'pct'=>(int)round($attempt->getScorePercent())];
    }
}
