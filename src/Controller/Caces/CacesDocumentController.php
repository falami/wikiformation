<?php
declare(strict_types=1);
namespace App\Controller\Caces;
use App\Entity\{Entite,CacesAttempt};
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\{Response,StreamedResponse};

#[Route('/caces/{entite}',name:'app_caces_',requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::ACCESS,subject:'entite')]
final class CacesDocumentController extends AbstractController
{
    #[Route('/registre.csv',name:'export',methods:['GET'])]
    public function export(Entite $entite,EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ADMIN,$entite);
        $records=$em->getRepository(CacesAttempt::class)->findBy(['entite'=>$entite],['id'=>'ASC']);
        return new StreamedResponse(function()use($records){$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
            $write=static function(array $row)use($out){fputcsv($out,array_map(static fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$row),';','"','');};
            $write(['Dossier','Créateur session','Session','Début session','Fin session','Entreprise / financement','Lieu test','Nom','Prénom','Naissance','Formateur session','Recommandation','Catégorie','Date théorie','Testeur théorie','Note théorie','Résultat théorie','Date pratique','N° certificat','Testeur pratique','Note pratique','Résultat pratique','Engin','Complément formation','Durée complément (h)','Candidats session','CACES obtenus session','Durée session (h)','Extension','Date obtention','Fin validité']);
            $counts=[];foreach($records as $a)if($a->getCertificate())$counts[$a->getExam()->getSession()->getId()]=($counts[$a->getExam()->getSession()->getId()]??0)+1;
            foreach($records as $a){$e=$a->getExam();$s=$e->getSession();$p=$a->getProfile();$t=$a->getTheoryResult();$r=$a->getPracticeResult();$c=$a->getCertificate();$name=static fn($u)=>$u?trim($u->getPrenom().' '.$u->getNom()):'';
                $write([$a->getId(),$name($s->getCreateur()),$s->getCode(),$s->getDateDebut()?->format('Y-m-d'),$s->getDateFin()?->format('Y-m-d'),$p['company']??$a->getInscription()->getModeFinancement()->value,$e->getLocation(),$a->getLearner()->getNom(),$a->getLearner()->getPrenom(),$p['birthDate']??'',$name($s->getFormateur()?->getUtilisateur()),$e->getRecommendation(),$e->getCategory(),isset($t['externalEvidence'])?'Dispense (CACES initial : '.$t['anchorAt'].')':$a->getSubmittedAt()?->format('Y-m-d'),$name($e->getTheoryTester()),$t['score']??'',isset($t['passed'])?($t['passed']?'Reçu':'Ajourné'):'',$a->getPracticeAt()?->format('Y-m-d'),$c['number']??'',($a->getSignatures()['practiceTester']['name']??$name($e->getPracticeTester())),$r['score']??'',isset($r['passed'])?($r['passed']?'Reçu':'Ajourné'):'',$r['equipment']??'',$r['remediation']??'',$r['remediationHours']??'',count($s->getInscriptions()),$counts[$s->getId()]??0,$s->getDureeFormationHeures(),($t['extension']??false)?'Oui':'Non',$c['obtainedAt']??'',$c['expiresAt']??'']);
            }fclose($out);
        },200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="registre-caces.csv"','Cache-Control'=>'private, no-store']);
    }
    #[Route('/dossiers/{id}/documents/{type}',name:'document',requirements:['id'=>'\d+','type'=>'synthese|attestation|carte|echec|global'],methods:['GET'])]
    public function document(Entite $entite,CacesAttempt $attempt,string $type,UtilisateurEntiteManager $uem,EntityManagerInterface $em): Response
    {
        $exam=$attempt->getExam();
        if($attempt->getEntite()?->getId()!==$entite->getId() || $exam->getEntite()?->getId()!==$entite->getId() || $exam->getSession()?->getEntite()?->getId()!==$entite->getId())throw $this->createNotFoundException();
        $user=$this->getUser()?->getId();
        if(!$this->isGranted(TenantPermission::ADMIN,$entite) && !in_array($user,[$exam->getPracticeTester()->getId(),$exam->getTheoryTester()->getId(),$attempt->getLearner()->getId()],true))throw $this->createAccessDeniedException();
        if ($type === 'global') {
            $records = $em->createQueryBuilder()->select('a','e','s')->from(CacesAttempt::class,'a')->join('a.exam','e')->join('e.session','s')
                ->where('a.entite = :tenant AND e.entite = :tenant AND s.entite = :tenant AND a.learner = :learner AND e.recommendation = :recommendation')
                ->setParameter('tenant',$entite)->setParameter('learner',$attempt->getLearner())->setParameter('recommendation',$exam->getRecommendation())
                ->orderBy('e.category','ASC')->addOrderBy('a.id','DESC')->getQuery()->getResult();
            // Each category retains its own access check, including across sessions.
            foreach ($records as $record) {
                $test = $record->getExam();
                if (!$this->isGranted(TenantPermission::ADMIN,$entite) && !in_array($user,[$test->getPracticeTester()?->getId(),$test->getTheoryTester()?->getId(),$record->getLearner()->getId()],true)) throw $this->createAccessDeniedException();
            }
            $response = $this->render('caces/recommendation_document.html.twig',['entite'=>$entite,'dossier'=>$attempt,'records'=>$records,'utilisateurEntite'=>$uem->getUserEntiteLink($entite)]);
            $response->headers->set('Cache-Control','private, no-store');
            return $response;
        }
        if(in_array($type,['attestation','carte'],true)&&!$attempt->getCertificate())throw $this->createNotFoundException('Certification non émise.');
        if($type==='echec'&&!(($attempt->getTheoryResult()&&!$attempt->getTheoryResult()['passed'])||($attempt->getPracticeResult()&&!$attempt->getPracticeResult()['passed'])))throw $this->createNotFoundException();
        $sessionAttempts=$em->createQueryBuilder()->select('a','e')->from(CacesAttempt::class,'a')->join('a.exam','e')->where('a.entite = :tenant AND e.entite = :tenant AND e.session = :session AND a.learner = :learner')->setParameter('tenant',$entite)->setParameter('session',$exam->getSession())->setParameter('learner',$attempt->getLearner())->orderBy('e.recommendation','ASC')->addOrderBy('e.category','ASC')->getQuery()->getResult();
        $cardCertificates = [];
        foreach ($sessionAttempts as $candidate) {
            if ($candidate->getExam()->getRecommendation() === $exam->getRecommendation() && $candidate->getCertificate()) {
                $cardCertificates[] = $candidate;
            }
        }
        $cardCategories = \App\Service\Caces\CacesRules::CATEGORIES[$exam->getRecommendation()] ?? [$exam->getCategory()];
        $cardFamily = \App\Service\Caces\CacesRules::FAMILIES[$exam->getRecommendation()] ?? 'Certification';
        $r=$this->render('caces/document.html.twig',['entite'=>$entite,'dossier'=>$attempt,'type'=>$type,'sessionAttempts'=>$sessionAttempts,'cardCertificates'=>$cardCertificates,'cardCategories'=>$cardCategories,'cardFamily'=>$cardFamily,'utilisateurEntite'=>$uem->getUserEntiteLink($entite)]);$r->headers->set('Cache-Control','private, no-store');return $r;
    }
}
