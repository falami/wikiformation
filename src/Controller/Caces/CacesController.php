<?php

declare(strict_types=1);
namespace App\Controller\Caces;
use App\Entity\{CacesAttempt,CacesExam,CacesTemplate,Entite,Session,Inscription,Formateur,Utilisateur,UtilisateurEntite};
use App\Security\Permission\TenantPermission;
use App\Service\Caces\{CacesLibrary,CacesRules,CacesWorkflow};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/caces/{entite}',name:'app_caces_',requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::ACCESS,subject:'entite')]
final class CacesController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em,private CacesLibrary $library,private CacesRules $rules,private CacesWorkflow $workflow,private UtilisateurEntiteManager $uem) {}
    private function admin(Entite $e): void { $this->denyAccessUnlessGranted(TenantPermission::ADMIN,$e); }
    private function token(Request $r,string $id): void { if (!$this->isCsrfTokenValid($id,$r->request->getString('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.'); }
    private function page(string $name,Entite $entite,array $data=[]): Response
    {
        $r=$this->render('caces/'.$name.'.html.twig',$data+['entite'=>$entite,'families'=>CacesRules::FAMILIES,'categories'=>CacesRules::CATEGORIES,'isAdmin'=>$this->isGranted(TenantPermission::ADMIN,$entite),'utilisateurEntite'=>$this->uem->getUserEntiteLink($entite)]);
        $r->headers->set('Cache-Control','private, no-store'); return $r;
    }
    private function scoped(Entite $e,CacesAttempt $a): void
    {
        if ($a->getEntite()?->getId()!==$e->getId() || $a->getExam()?->getEntite()?->getId()!==$e->getId() || $a->getExam()?->getSession()?->getEntite()?->getId()!==$e->getId() || $a->getInscription()?->getEntite()?->getId()!==$e->getId()) throw $this->createNotFoundException();
    }
    private function examiner(Entite $e,CacesAttempt $a): void
    {
        $this->scoped($e,$a);
        if (!$this->isGranted(TenantPermission::ADMIN,$e) && $a->getExam()->getPracticeTester()?->getId()!==$this->getUser()?->getId() && $a->getExam()->getTheoryTester()?->getId()!==$this->getUser()?->getId()) throw $this->createAccessDeniedException();
    }
    #[Route('',name:'index',methods:['GET'])]
    public function index(Entite $entite,Request $request): Response
    {
        $admin=$this->isGranted(TenantPermission::ADMIN,$entite);
        if (!$admin && !$this->isGranted('ENTITE_FORMATEUR', $entite)) {
            return $this->redirectToRoute('app_caces_learner', ['entite'=>$entite->getId()]);
        }
        $q=$this->em->createQueryBuilder()->select('a','e','u','s')->from(CacesAttempt::class,'a')->join('a.exam','e')->join('a.learner','u')->join('e.session','s')->where('a.entite = :e AND e.entite = :e AND s.entite = :e')->setParameter('e',$entite);
        if (!$admin) $q->andWhere('a.learner = :u OR e.practiceTester = :u OR e.theoryTester = :u')->setParameter('u',$this->getUser());
        $search=mb_substr(trim($request->query->getString('q')),0,100);
        if ($search) $q->andWhere('u.nom LIKE :q OR u.prenom LIKE :q OR s.code LIKE :q')->setParameter('q','%'.$search.'%');
        $attempts=$q->orderBy('a.id','DESC')->setMaxResults(200)->getQuery()->getResult();
        return $this->page('index',$entite,['catalog'=>$admin?$this->library->catalog($entite):[],'attempts'=>$attempts,'search'=>$search,'protectedKeys'=>$admin?$this->library->protectedKeys():[]]);
    }
    #[Route('/mes-tests',name:'learner',methods:['GET'])]
    public function learner(Entite $entite): Response
    {
        $attempts = $this->em->createQueryBuilder()->select('a','e','s')
            ->from(CacesAttempt::class,'a')->join('a.exam','e')->join('e.session','s')
            ->where('a.entite = :entite AND e.entite = :entite AND s.entite = :entite')
            ->andWhere('a.learner = :learner AND a.theorySource IS NULL')
            ->setParameter('entite',$entite)->setParameter('learner',$this->getUser())
            ->orderBy('a.id','DESC')->getQuery()->getResult();
        return $this->page('learner',$entite,['attempts'=>$attempts]);
    }

    #[Route('/modeles/nouveau',name:'model',methods:['GET','POST'])]
    public function model(Entite $entite,Request $r): Response
    {
        $this->admin($entite);$errors=[];$values=['title'=>'','recommendation'=>'489','category'=>'','kind'=>'theory','versionLabel'=>'V1','schema'=>['durationMinutes'=>60,'themes'=>[]]];
        if ($r->isMethod('GET') && $r->query->getString('kind') === 'practice') {
            $values['kind']='practice';
            $rec=$r->query->getString('recommendation','489');
            if(isset(CacesRules::FAMILIES[$rec])) $values['recommendation']=$rec;
            $category=$r->query->getString('category');
            if(in_array($category,CacesRules::CATEGORIES[$values['recommendation']],true)) $values['category']=$category;
        }
        if ($r->isMethod('GET') && $r->query->getString('copy')) { $values=$this->library->find($entite,$r->query->getString('copy')); $values['title'].=' — nouvelle version'; }
        if ($r->isMethod('POST')) {
            $this->token($r,'caces_model_'.$entite->getId());
            foreach (['title','recommendation','category','kind','versionLabel'] as $f) $values[$f]=trim($r->request->getString($f));
            try {
                $raw=$r->request->getString('schema'); if(strlen($raw)>12000000) throw new \InvalidArgumentException('Le modèle est trop volumineux.');
                $values['schema']=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                if(!is_array($values['schema'])) throw new \InvalidArgumentException('Modèle invalide.');
                if(!$values['title'] || mb_strlen($values['title'])>180 || !$values['versionLabel'] || mb_strlen($values['versionLabel'])>80 || !isset(CacesRules::FAMILIES[$values['recommendation']])) throw new \InvalidArgumentException('Complétez le nom, la version et la recommandation.');
                if ($values['kind']==='practice' && !in_array($values['category'],CacesRules::CATEGORIES[$values['recommendation']],true)) throw new \InvalidArgumentException('Choisissez une catégorie valide.');
                // Source references may only refer to the bundled, access-controlled grids.
                $source=$values['schema']['source'] ?? null;
                if ($source) { $original=$this->library->find($entite,(string)($source['key'] ?? '')); $values['schema']['source']=$original['schema']['source']; }
                $publishing=$r->request->getString('saveMode')!=='draft';
                $schema=$this->rules->validate($values['schema'],$values['kind'],$publishing);
                if($r->request->getBoolean('global') && $this->isGranted('ROLE_SUPER_ADMIN') && $r->request->getString('supersedes')) {
                    $previous=$this->library->find($entite,$r->request->getString('supersedes'));
                    if($previous['recommendation']!==$values['recommendation'] || $previous['kind']!==$values['kind'] || (str_starts_with($previous['key'],'custom-') && !($previous['global']??false))) throw new \InvalidArgumentException('La version remplacée doit appartenir à la bibliothèque commune et à la même recommandation.');
                    $schema['supersedes']=$previous['key'];
                }
                $t=(new CacesTemplate())->setEntite($r->request->getBoolean('global') && $this->isGranted('ROLE_SUPER_ADMIN') ? null : $entite)->setTitle($values['title'])->setRecommendation($values['recommendation'])->setCategory($values['kind']==='practice'?$values['category']:'')->setKind($values['kind'])->setVersionLabel($values['versionLabel'])->setSchema($schema)->setStatus($publishing?'active':'draft');
                $this->em->persist($t);$this->em->flush();$this->addFlash('success',$publishing?'Nouvelle version publiée. Les sessions existantes conservent leur modèle.':'Brouillon enregistré. Il ne sera pas proposé dans les tirages au sort.');
                return $this->redirectToRoute('app_caces_index',['entite'=>$entite->getId()]);
            } catch (\InvalidArgumentException|\JsonException $e) { $errors[]=$e->getMessage(); }
        }
        return $this->page('model',$entite,compact('values','errors'));
    }
    #[Route('/modeles/{id}/archiver',name:'archive',methods:['POST'])]
    public function archive(Entite $entite,CacesTemplate $template,Request $r): Response
    {
        $this->admin($entite);$this->token($r,'caces_archive_'.$template->getId());
        if ($template->getEntite()?->getId()!==$entite->getId() && !($template->getEntite()===null && $this->isGranted('ROLE_SUPER_ADMIN'))) throw $this->createNotFoundException();
        $template->setStatus('archived');$this->em->flush();return $this->redirectToRoute('app_caces_index',['entite'=>$entite->getId()]);
    }
    #[Route('/modeles/{id}/supprimer',name:'delete',methods:['POST'])]
    public function delete(Entite $entite,CacesTemplate $template,Request $r): Response
    {
        $this->admin($entite);
        $this->token($r,'caces_delete_'.$template->getId());
        if ($template->getEntite()?->getId()!==$entite->getId() && !($template->getEntite()===null && $this->isGranted('ROLE_SUPER_ADMIN'))) throw $this->createNotFoundException();
        if (isset($this->library->protectedKeys()['custom-'.$template->getId()])) {
            $this->addFlash('warning','Cette grille est utilisée par une évaluation ou une autre version. Vous pouvez l’archiver, mais pas la supprimer.');
        } else {
            $this->em->remove($template);
            $this->em->flush();
            $this->addFlash('success','La grille inutilisée a été supprimée.');
        }
        return $this->redirectToRoute('app_caces_index',['entite'=>$entite->getId()]);
    }

    private function trainers(Entite $entite): array
    {
        $trainers=[];
        foreach($this->em->getRepository(Formateur::class)->findBy(['entite'=>$entite]) as $t) {
            $u=$t->getUtilisateur(); $membership=$this->em->getRepository(UtilisateurEntite::class)->findOneBy(['entite'=>$entite,'utilisateur'=>$u]);
            if($u && $membership?->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) $trainers[$u->getId()]=$u;
        }
        return $trainers;
    }
    #[Route('/sessions/{id}/testeur-pratique',name:'practice_tester',methods:['POST'])]
    public function practiceTester(Entite $entite,Session $session,Request $r): Response
    {
        $this->admin($entite);
        if($session->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
        $this->token($r,'caces_tester_'.$session->getId());
        $tester=$this->trainers($entite)[$r->request->getInt('practiceTester')]??null;
        if(!$tester) throw $this->createAccessDeniedException('Choisissez un formateur actif de cet organisme.');
        $examId=$r->request->getInt('exam');
        $this->em->wrapInTransaction(function()use($entite,$session,$tester,$examId){
            $this->em->lock($session,LockMode::PESSIMISTIC_WRITE);
            foreach($this->em->getRepository(CacesExam::class)->findBy(['entite'=>$entite,'session'=>$session]) as $exam) {
                if($examId && $exam->getId()!==$examId) continue;
                $this->em->lock($exam,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($exam);
                if($exam->getPracticeTester()->getId()===$tester->getId()) continue;
                // Signed results retain their actual examiner; only pending work changes hands.
                foreach($this->em->getRepository(CacesAttempt::class)->findBy(['exam'=>$exam]) as $attempt) {
                    $this->em->lock($attempt,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt);
                    if($attempt->getPracticeAt()) {
                        $result=$attempt->getPracticeResult();
                        $result['testerName']??=$attempt->getSignatures()['practiceTester']['name']??$this->fullName($exam->getPracticeTester());
                        $result['testerId']??=$exam->getPracticeTester()->getId();
                        $attempt->setPracticeResult($result);
                    }
                }
                $snapshot=$exam->getPracticeSnapshot();
                $snapshot['testerChanges'][]=['from'=>$exam->getPracticeTester()->getId(),'to'=>$tester->getId(),'by'=>$this->getUser()->getId(),'at'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
                $exam->setPracticeSnapshot($snapshot)->setPracticeTester($tester);
            }
        });
        $this->addFlash('success','Affectation du testeur pratique mise à jour. Les évaluations déjà signées sont conservées.');
        return $this->redirectToRoute('app_caces_index',['entite'=>$entite->getId()]);
    }
    #[Route('/sessions/nouvelle',name:'assign',methods:['GET','POST'])]
    public function assign(Entite $entite,Request $r): Response
    {
        $this->admin($entite); $errors=[];$catalog=$this->library->catalog($entite);
        $sessions=$this->em->getRepository(Session::class)->findBy(['entite'=>$entite],['id'=>'DESC']);
        $trainers=$this->trainers($entite);
        $values=$r->isMethod('POST')?$r->request->all():['session'=>$r->query->getString('session'),'recommendation'=>'489'];
        if($r->isMethod('POST')) {
            $this->token($r,'caces_assign_'.$entite->getId());
            try {
                $session=$this->em->getRepository(Session::class)->findOneBy(['id'=>$r->request->getInt('session'),'entite'=>$entite]);
                $rec=$r->request->getString('recommendation');
                $theoryTester=$trainers[$r->request->getInt('theoryTester')]??null;
                if(!$session || !$theoryTester || !isset(CacesRules::CATEGORIES[$rec])) throw new \InvalidArgumentException('Choisissez une session, une recommandation et un testeur théorique actif.');
                $rows=$r->request->all('practices');
                // Compatibility with existing integrations posting a single category.
                if(!$rows) $rows=[['category'=>$r->request->getString('category'),'tester'=>$r->request->getInt('practiceTester'),'template'=>$r->request->getString('practice')]];
                if(count($rows)>count(CacesRules::CATEGORIES[$rec])) throw new \InvalidArgumentException('Trop d’épreuves pour cette recommandation.');
                $practices=[];
                foreach($rows as $row) {
                    if(!is_array($row) || !is_string($row['category']??null) || !is_scalar($row['tester']??null) || !is_string($row['template']??null)) throw new \InvalidArgumentException('Épreuve pratique invalide.');
                    $category=$row['category'];$tester=$trainers[(int)$row['tester']]??null;
                    if(!$tester || !in_array($category,CacesRules::CATEGORIES[$rec],true)) throw new \InvalidArgumentException('Chaque épreuve doit avoir une catégorie valide et un testeur actif.');
                    if(isset($practices[$category])) throw new \InvalidArgumentException('Une catégorie ne peut être ajoutée qu’une seule fois.');
                    $practice=$this->library->find($entite,$row['template']);
                    if($practice['kind']!=='practice' || $practice['recommendation']!==$rec || $practice['category']!==$category || ($practice['status']??'active')!=='active') throw new \InvalidArgumentException('Chaque grille pratique doit correspondre à sa catégorie et être publiée.');
                    $this->rules->validate($practice['schema'],'practice');
                    $selected=$row['learners']??[];
                    if(!is_array($selected) || array_filter($selected,fn($id)=>!is_scalar($id)||!ctype_digit((string)$id))) throw new \InvalidArgumentException('Sélection de stagiaires invalide.');
                    $practices[$category]=['tester'=>$tester,'template'=>$practice,'learners'=>array_map('intval',$selected)];
                }
                $pool=array_values(array_filter($catalog,fn($t)=>$t['recommendation']===$rec && $t['kind']==='theory' && ($t['status']??'active')==='active' && ($r->request->getString('pool')==='custom' ? str_starts_with($t['key'],'custom-') && !($t['global']??false) : (!str_starts_with($t['key'],'custom-') || ($t['global']??false)))));
                $location=trim($r->request->getString('location'));if(!$location || mb_strlen($location)>255) throw new \InvalidArgumentException('Indiquez le lieu du test.');
                // DBAL keeps the manager usable after a validation rollback.
                $this->em->getConnection()->transactional(function() use($session,$entite,$rec,$theoryTester,$pool,$practices,$location) {
                    $this->em->lock($session,LockMode::PESSIMISTIC_WRITE);
                    $existingExams=$this->em->getRepository(CacesExam::class)->findBy(['entite'=>$entite,'session'=>$session,'recommendation'=>$rec],['id'=>'ASC']);
                    $sources=[];
                    foreach($existingExams as $existing) {
                        if(isset($practices[$existing->getCategory()])) throw new \InvalidArgumentException('La catégorie '.$existing->getCategory().' est déjà affectée à cette session.');
                        foreach($this->em->getRepository(CacesAttempt::class)->findBy(['exam'=>$existing]) as $a) {
                            if($a->getCertificate()) throw new \InvalidArgumentException('Des certificats ont déjà été émis. Préparez une nouvelle session pour une extension.');
                            $sources[$a->getLearner()->getId()]??=$a->getTheoryAttempt();
                        }
                    }
                    $existing=$existingExams[0]??null;
                    if($existing && $existing->getTheoryTester()->getId()!==$theoryTester->getId()) throw new \InvalidArgumentException('Conservez le testeur théorique déjà affecté à cette session et recommandation.');
                    if(!$existing && !$pool) throw new \InvalidArgumentException('Aucun modèle théorique actif dans cette bibliothèque.');
                    $theory=$existing?->getTheorySnapshot()??$pool[random_int(0,count($pool)-1)];
                    $this->rules->validate($theory['schema'],'theory');
                    $registrations=[];
                    foreach($this->em->getRepository(Inscription::class)->findBy(['entite'=>$entite,'session'=>$session]) as $i) {
                        if(!in_array($i->getStatus(),[\App\Enum\StatusInscription::ANNULE,\App\Enum\StatusInscription::ABSENT],true)) $registrations[$i->getId()]=$i;
                    }
                    if(!$registrations) throw new \InvalidArgumentException('Cette session ne comporte aucun stagiaire éligible.');
                    foreach($practices as $p) if(array_diff($p['learners'],array_keys($registrations))) throw new \InvalidArgumentException('Un stagiaire sélectionné ne fait pas partie des inscrits éligibles de cette session.');
                    foreach($practices as $category=>$p) {
                        $exam=(new CacesExam())->setEntite($entite)->setSession($session)->setCreateur($this->getUser())->setTheoryTester($theoryTester)->setPracticeTester($p['tester'])->setRecommendation($rec)->setCategory((string)$category)->setLocation($location)->setTheorySnapshot($theory)->setPracticeSnapshot($p['template']);
                        $this->em->persist($exam);
                        foreach($registrations as $id=>$i) {
                            if($p['learners'] && !in_array($id,$p['learners'],true)) continue;
                            $a=(new CacesAttempt())->setEntite($entite)->setExam($exam)->setInscription($i)->setLearner($i->getStagiaire());
                            $learnerId=$i->getStagiaire()->getId();
                            if(isset($sources[$learnerId])) $a->setTheorySource($sources[$learnerId]);
                            else $sources[$learnerId]=$a;
                            $this->em->persist($a);
                        }
                    }
                    $this->em->flush();
                });
                $this->addFlash('success',count($practices).' épreuve(s) pratique(s) créée(s). Une seule théorie par stagiaire, commune aux catégories de cette recommandation.');
                return $this->redirectToRoute('app_caces_index',['entite'=>$entite->getId()]);
            }catch(\InvalidArgumentException $e){$errors[]=$e->getMessage();}
        }
        $registrationsBySession=[];
        foreach($this->em->getRepository(Inscription::class)->findBy(['entite'=>$entite]) as $i) {
            if($i->getSession()?->getEntite()?->getId()===$entite->getId() && !in_array($i->getStatus(),[\App\Enum\StatusInscription::ANNULE,\App\Enum\StatusInscription::ABSENT],true)) $registrationsBySession[$i->getSession()->getId()][]=['id'=>$i->getId(),'name'=>$this->fullName($i->getStagiaire())];
        }
        return $this->page('assign',$entite,compact('sessions','trainers','catalog','errors','values','registrationsBySession'));
    }
    #[Route('/dossiers/{id}/theorie',name:'theory',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function theory(Entite $entite,CacesAttempt $attempt,Request $r, \App\Service\Caces\CacesProfilePrefill $profilePrefill): Response
    {
        $this->scoped($entite,$attempt);
        if($attempt->getLearner()?->getId()!==$this->getUser()?->getId()) throw $this->createAccessDeniedException();
        if ($attempt->getTheorySource()) return $this->redirectToRoute('app_caces_theory',['entite'=>$entite->getId(),'id'=>$attempt->getTheoryAttempt()->getId()],307);
        $error=null;
        if($r->isMethod('POST')) {
            $this->token($r,'caces_theory_'.$attempt->getId());
            try {
                $this->em->wrapInTransaction(function() use($attempt,$r) {
                    $this->em->lock($attempt,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt);$now=new \DateTimeImmutable();
                    $action=$r->request->getString('action');
                    if($action==='start' && !$attempt->getStartedAt() && !$attempt->getSubmittedAt()) $attempt->setStartedAt($now);
                    elseif(in_array($action,['save','submit'],true)) $this->workflow->saveAnswers($attempt,$r->request->all('answers'),$now,$action==='submit');
                    elseif($action==='profile') {
                        $profile=[]; foreach(['birthDate','birthPlace','address','phone','drivingExperience','trainingExperience','company'] as $key) { $v=trim($r->request->getString($key)); if(mb_strlen($v)>2000) throw new \InvalidArgumentException('Réponse trop longue.');$profile[$key]=$v; }
                        if(in_array('', $profile, true)) throw new \InvalidArgumentException('Complétez tous les champs de la fiche de synthèse.');
                        $birth=\DateTimeImmutable::createFromFormat('!Y-m-d',$profile['birthDate']);
                        if(!$birth || $birth->format('Y-m-d')!==$profile['birthDate'] || $birth>new \DateTimeImmutable('today')) throw new \InvalidArgumentException('La date de naissance est invalide.');
                        $certified=$this->em->createQueryBuilder()->select('a')->from(CacesAttempt::class,'a')->where('a.theorySource = :source')->setParameter('source',$attempt)->getQuery()->getResult();
                        if($attempt->getCertificate() || array_filter($certified,fn($a)=>(bool)$a->getCertificate())) throw new \InvalidArgumentException('Le dossier certifié est verrouillé.');
                        $attempt->setProfile($profile);
                        $sig=$this->signature($r,'signature');$signatures=$attempt->getSignatures();$signatures['learner']=['image'=>$sig,'name'=>$this->fullName($attempt->getLearner()),'at'=>$now->format(DATE_ATOM)];$attempt->setSignatures($signatures);
                    }
                });
                if($r->headers->get('X-Requested-With')==='XMLHttpRequest') return $this->json(['saved'=>true,'submitted'=>(bool)$attempt->getSubmittedAt(),'serverNow'=>time(),'deadline'=>$this->workflow->deadline($attempt)?->getTimestamp()],200,['Cache-Control'=>'no-store']);
                return $this->redirectToRoute('app_caces_theory',['entite'=>$entite->getId(),'id'=>$attempt->getId()]);
            }catch(\InvalidArgumentException|\DomainException $e){$error=$e->getMessage();if($r->headers->get('X-Requested-With')==='XMLHttpRequest')return $this->json(['error'=>$error],422);$this->addFlash('danger',$error);return $this->redirectToRoute('app_caces_theory',['entite'=>$entite->getId(),'id'=>$attempt->getId()]);}
        }
        // Expiration is enforced by the server, including after a disconnected browser returns.
        $this->em->wrapInTransaction(function() use($attempt){$this->em->refresh($attempt->getTheoryAttempt(),LockMode::PESSIMISTIC_WRITE);$this->em->lock($attempt,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt);$this->workflow->expire($attempt,new \DateTimeImmutable());});
        $schema=$attempt->getExam()->getTheorySnapshot()['schema'];foreach($schema['themes'] as &$theme)foreach($theme['questions'] as &$q)unset($q['correct']);unset($theme,$q);
        $view=['id'=>$attempt->getId(),'startedAt'=>$attempt->getStartedAt(),'submittedAt'=>$attempt->getSubmittedAt(),'answers'=>$attempt->getAnswers(),'result'=>$attempt->getTheoryResult(),'profile'=>$profilePrefill->profileFor($attempt),'prefilled'=>$attempt->getProfile()===[],'signed'=>isset($attempt->getSignatures()['learner']),'deadline'=>$this->workflow->deadline($attempt)?->getTimestamp(),'serverNow'=>time(),'recommendation'=>$attempt->getExam()->getRecommendation(),'category'=>$attempt->getExam()->getCategory(),'schema'=>$schema];
        return $this->page('theory',$entite,['test'=>$view,'error'=>$error]);
    }
    #[Route('/dossiers/{id}/pratique/brouillon', name: 'practice_draft', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function practiceDraft(Entite $entite, CacesAttempt $attempt, Request $r): Response
    {
        $this->examiner($entite, $attempt);
        $this->token($r, 'caces_dossier_'.$attempt->getId());
        try {
            $this->em->getConnection()->transactional(function () use ($attempt, $r) {
                $this->em->refresh($attempt->getExam(), LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($attempt, LockMode::PESSIMISTIC_WRITE);
                if ($attempt->getExam()->getPracticeTester()->getId() !== $this->getUser()->getId()) throw $this->createAccessDeniedException();
                if ($attempt->getPracticeAt() || $attempt->getCertificate()) throw new \DomainException('Cette évaluation est déjà validée.');
                if ($r->request->getInt('revision') !== ($attempt->getPracticeDraft()['revision'] ?? 0)) throw new \DomainException('Le dossier a été modifié dans une autre page. Rechargez avant de poursuivre.');
                $values = [];
                $scores = $r->request->all('scores');
                foreach ($attempt->getExam()->getPracticeSnapshot()['schema']['themes'] as $theme) {
                    foreach ($theme['questions'] as $q) {
                        $value = $scores[$q['id']] ?? '';
                        if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || (int)$value > $q['points']))) throw new \InvalidArgumentException('Note invalide.');
                        $values['scores['.$q['id'].']'] = $value;
                    }
                }
                foreach (['equipment'=>255, 'practiceDate'=>10, 'remediationHours'=>5] as $name=>$max) {
                    $value = $r->request->getString($name);
                    if (mb_strlen($value) > $max) throw new \InvalidArgumentException('Valeur trop longue.');
                    $values[$name] = $value;
                }
                foreach ($r->request->all('remediationThemes') as $theme) {
                    if (!is_string($theme) || strlen($theme)>100) throw new \InvalidArgumentException('Thème invalide.');
                    $values['remediationThemes[]:'.$theme] = true;
                }
                $attempt->setPracticeDraft(['values'=>$values, 'at'=>(int)round(microtime(true)*1000), 'revision'=>($attempt->getPracticeDraft()['revision'] ?? 0)+1]);
                $this->em->flush();
            });
            return $this->json(['saved'=>true, 'revision'=>$attempt->getPracticeDraft()['revision']], 200, ['Cache-Control'=>'no-store']);
        } catch (\DomainException $e) { return $this->json(['error'=>$e->getMessage()], 409); }
        catch (\InvalidArgumentException $e) { return $this->json(['error'=>$e->getMessage()], 422); }
    }

    #[Route('/dossiers/{id}',name:'show',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function show(Entite $entite,CacesAttempt $attempt,Request $r): Response
    {
        $this->examiner($entite,$attempt);$error=null;
        if($r->isMethod('POST')) {
            $this->token($r,'caces_dossier_'.$attempt->getId());
            try {
                $this->em->wrapInTransaction(function() use($entite,$attempt,$r) {
                    // Serialize issuance with category creation and tester reassignment.
                    if($r->request->getString('action')==='certify') $this->em->lock($attempt->getExam()->getSession(),LockMode::PESSIMISTIC_WRITE);
                    $this->em->lock($attempt->getExam(),LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt->getExam());
                    $this->em->refresh($attempt->getTheoryAttempt(),LockMode::PESSIMISTIC_WRITE);$this->em->lock($attempt,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt);$this->workflow->expire($attempt,new \DateTimeImmutable());
                    $action=$r->request->getString('action');
                    if($attempt->getCertificate()) throw new \InvalidArgumentException('Ce dossier certifié est verrouillé.');
                    if($action==='practice') {
                        if($attempt->getExam()->getPracticeTester()->getId()!==$this->getUser()->getId()) throw $this->createAccessDeniedException('Seul le testeur pratique affecté peut signer.');
                        if($attempt->getPracticeAt()) throw new \InvalidArgumentException('La pratique a déjà été signée.');
                        $duration=filter_var($r->request->getString('durationSeconds'),FILTER_VALIDATE_INT);if($duration===false || $duration<1 || $duration>86400) throw new \InvalidArgumentException('Renseignez la durée réelle en secondes.');
                        $equipment=trim($r->request->getString('equipment'));if(!$equipment || mb_strlen($equipment)>255) throw new \InvalidArgumentException('Précisez la marque et le modèle de l’engin.');
                        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$r->request->getString('practiceDate'));if(!$date || $date->format('Y-m-d')!==$r->request->getString('practiceDate') || $date>new \DateTimeImmutable('today')) throw new \InvalidArgumentException('Date de pratique invalide.');
                        $scores=$r->request->all('scores');$result=$this->rules->grade($attempt->getExam()->getPracticeSnapshot()['schema'],$scores,true,$duration);
                        $remediationThemes=$this->rules->remediationThemes($attempt->getExam()->getPracticeSnapshot()['schema'],$scores,$r->request->all('remediationThemes'));
                        $result+=['testerId'=>$this->getUser()->getId(),'testerName'=>$this->fullName($this->getUser()),'scores'=>$scores,'equipment'=>$equipment,'remediationThemes'=>$remediationThemes,'remediation'=>implode("\n",$remediationThemes),'remediationHours'=>max(0,min(500,$r->request->getInt('remediationHours')))];
                        $sig=$this->signature($r,'signature');$signatures=$attempt->getSignatures();$signatures['practiceTester']=['image'=>$sig,'name'=>$this->fullName($this->getUser()),'at'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
                        $attempt->setPracticeResult($result)->setPracticeAt($date)->setSignatures($signatures);
                    } elseif($action==='theorySign') {
                        if($attempt->getExam()->getTheoryTester()->getId()!==$this->getUser()->getId() || !$attempt->getSubmittedAt()) throw $this->createAccessDeniedException();
                        $signatures=$attempt->getSignatures();if(isset($signatures['theoryTester']))throw new \InvalidArgumentException('La théorie est déjà signée.');
                        $result=$attempt->getTheoryResult();
                        $themes=[];
                        foreach($r->request->all('remediationThemes') as $index) {
                            if(!is_string($index) || !ctype_digit($index) || !isset($result['themes'][(int)$index]) || (($result['score'] ?? 100) >= 70 && $result['themes'][(int)$index]['passed'])) throw new \InvalidArgumentException('Seuls les thèmes théoriques dont le seuil n’est pas atteint peuvent être sélectionnés.');
                            $themes[(int)$index]=$result['themes'][(int)$index]['title'];
                        }
                        $hours=str_replace(',', '.', $r->request->getString('remediationHours','0'));
                        if(!is_numeric($hours) || (float)$hours<0 || (float)$hours>500 || fmod((float)$hours,0.5)!==0.0) throw new \InvalidArgumentException('Saisissez une durée entre 0 et 500 heures, par demi-heure.');
                        $result['remediationThemes']=array_values($themes);
                        $result['remediationDurationSeconds']=(int)round((float)$hours*3600);
                        $attempt->getTheoryAttempt()->setTheoryResult($result);
                        $signatures=array_intersect_key($signatures,array_flip(['learner','theoryTester'])) + array_diff_key($attempt->getTheoryAttempt()->getSignatures(),array_flip(['learner','theoryTester']));
                        $signatures['theoryTester']=['image'=>$this->signature($r,'signature'),'name'=>$this->fullName($this->getUser()),'at'=>(new \DateTimeImmutable())->format(DATE_ATOM)];$attempt->getTheoryAttempt()->setSignatures($signatures);
                    } elseif($action==='reuse') {
                        $this->admin($entite);
                        if($attempt->getTheorySource()) throw new \InvalidArgumentException('Gérez la dispense depuis le dossier théorique commun.');
                        if($attempt->getStartedAt() || $attempt->getSubmittedAt()) throw new \InvalidArgumentException('Le test a déjà commencé.');
                        $previous=$this->em->getRepository(CacesAttempt::class)->findOneBy(['id'=>$r->request->getInt('previous'),'entite'=>$entite,'learner'=>$attempt->getLearner()]);
                        if(!$previous || !$previous->getSubmittedAt() || !($previous->getTheoryResult()['passed']??false) || $previous->getExam()->getRecommendation()!==$attempt->getExam()->getRecommendation()) throw new \InvalidArgumentException('Le dossier doit concerner ce stagiaire et la même recommandation avec une théorie réussie.');
                        $sameSession=$previous->getExam()->getSession()->getId()===$attempt->getExam()->getSession()->getId();
                        if(!$sameSession && (!$previous->getCertificate() || new \DateTimeImmutable($previous->getCertificate()['anchorAt']) <= (new \DateTimeImmutable('today'))->modify('-1 year'))) throw new \InvalidArgumentException('Une extension nécessite un CACES obtenu depuis moins d’un an.');
                        $result=$previous->getTheoryResult();$result['reusedFrom']=$previous->getId();$result['extension']=!$sameSession;
                        if(!$sameSession){$result['anchorAt']=$previous->getCertificate()['anchorAt'];$result['extensionDeadline']=(new \DateTimeImmutable($previous->getCertificate()['anchorAt']))->modify('+1 year')->format('Y-m-d');}
                        $signatures=$attempt->getSignatures();if(isset($previous->getSignatures()['theoryTester']))$signatures['theoryTester']=$previous->getSignatures()['theoryTester'];
                        $attempt->setTheoryResult($result)->setSubmittedAt($previous->getSubmittedAt())->setSignatures($signatures);
                    } elseif($action==='externalReuse') {
                        $this->admin($entite);
                        if($attempt->getTheorySource()) throw new \InvalidArgumentException('Gérez la dispense depuis le dossier théorique commun.');
                        if($attempt->getStartedAt() || $attempt->getSubmittedAt()) throw new \InvalidArgumentException('Le test a déjà commencé.');
                        $raw=$r->request->getString('initialDate');$initial=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
                        $reference=trim($r->request->getString('certificateReference'));$issuer=trim($r->request->getString('certificateIssuer'));
                        if(!$initial || $initial->format('Y-m-d')!==$raw || $initial>new \DateTimeImmutable('today') || $initial <= (new \DateTimeImmutable('today'))->modify('-1 year') || !$reference || !$issuer || mb_strlen($reference)>180 || mb_strlen($issuer)>180 || !$r->request->getBoolean('evidenceChecked')) throw new \InvalidArgumentException('Vérifiez le CACES initial de la même recommandation, son numéro, son émetteur et sa date d’obtention depuis moins d’un an.');
                        $attempt->setSubmittedAt($initial)->setTheoryResult(['score'=>null,'passed'=>true,'themes'=>[],'reasons'=>[],'extension'=>true,'anchorAt'=>$raw,'extensionDeadline'=>$initial->modify('+1 year')->format('Y-m-d'),'externalEvidence'=>['reference'=>$reference,'issuer'=>$issuer,'checkedBy'=>$this->fullName($this->getUser()),'checkedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM)]]);
                    } elseif($action==='certify') {
                        $this->admin($entite);
                        if(!($attempt->getTheoryResult()['passed']??false) || !($attempt->getPracticeResult()['passed']??false)) throw new \InvalidArgumentException('La théorie et la pratique doivent être réussies.');
                        foreach(['learner','theoryTester','practiceTester'] as $s) if(empty($attempt->getSignatures()[$s])) throw new \InvalidArgumentException('Les trois signatures sont nécessaires.');
                        if(empty($attempt->getProfile()['birthDate']))throw new \InvalidArgumentException('Le stagiaire doit compléter sa fiche de synthèse.');
                        // Serialize issuance within the tenant; sequence and shared expiry cannot race.
                        $this->em->lock($entite,LockMode::PESSIMISTIC_WRITE);
                        if(($attempt->getTheoryResult()['extension']??false) && $attempt->getPracticeAt()->format('Y-m-d') < $attempt->getTheoryResult()['anchorAt']) throw new \InvalidArgumentException('La pratique d’une extension ne peut pas précéder le CACES initial.');
                        if(isset($attempt->getTheoryResult()['extensionDeadline']) && $attempt->getPracticeAt()->format('Y-m-d') >= $attempt->getTheoryResult()['extensionDeadline']) throw new \InvalidArgumentException('La pratique a été passée après le délai de dispense théorique.');
                        $exam=$attempt->getExam();$anchor=isset($attempt->getTheoryResult()['anchorAt'])?new \DateTimeImmutable($attempt->getTheoryResult()['anchorAt']):null;
                        $siblings=$this->em->getRepository(CacesAttempt::class)->findBy(['entite'=>$entite,'learner'=>$attempt->getLearner()]);
                        foreach($siblings as $s) if($s->getExam()->getSession()->getId()===$exam->getSession()->getId() && $s->getExam()->getRecommendation()===$exam->getRecommendation() && (!$s->getSubmittedAt() || !$s->getPracticeAt())) throw new \InvalidArgumentException('Finalisez les tests des autres catégories de cette session avant d’émettre les cartes : leur date commune de validité en dépend.');
                        foreach($siblings as $s) if($s->getExam()->getSession()->getId()===$exam->getSession()->getId() && $s->getExam()->getRecommendation()===$exam->getRecommendation() && ($s->getTheoryResult()['passed']??false) && ($s->getPracticeResult()['passed']??false)) {
                            $d=max($s->getSubmittedAt(),$s->getPracticeAt());if(!$anchor || $d<$anchor)$anchor=$d;
                        }
                        $dates=$this->rules->dates($exam->getRecommendation(),$attempt->getSubmittedAt(),$attempt->getPracticeAt(),$anchor);

                        // ID is a durable, unique sequence within the archive, including concurrent sessions.
                        $number=substr($dates['obtainedAt'],0,4).'.'.$exam->getRecommendation().'.C'.$exam->getCategory().'.'.str_pad((string)$attempt->getId(),3,'0',STR_PAD_LEFT);
                        $attempt->setCertificate($dates+['number'=>$number,'issuedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM),'issuedBy'=>$this->fullName($this->getUser()),'learner'=>$this->fullName($attempt->getLearner()),'practiceTester'=>$attempt->getPracticeResult()['testerName']??$attempt->getSignatures()['practiceTester']['name']??$this->fullName($exam->getPracticeTester())]);
                    } else throw new \InvalidArgumentException('Action inconnue.');
                });
                return $this->redirectToRoute('app_caces_show',['entite'=>$entite->getId(),'id'=>$attempt->getId()]);
            } catch(\InvalidArgumentException $e){$this->addFlash('danger',$e->getMessage());return $this->redirectToRoute('app_caces_show',['entite'=>$entite->getId(),'id'=>$attempt->getId()]);}
        }
        $this->em->wrapInTransaction(function()use($attempt){$this->em->refresh($attempt->getTheoryAttempt(),LockMode::PESSIMISTIC_WRITE);$this->em->lock($attempt,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($attempt);$this->workflow->expire($attempt,new \DateTimeImmutable());});
        $sessionAttempts=$this->em->createQueryBuilder()->select('a','e')->from(CacesAttempt::class,'a')->join('a.exam','e')->where('a.entite = :tenant AND e.entite = :tenant AND e.session = :session AND a.learner = :learner')->setParameter('tenant',$entite)->setParameter('session',$attempt->getExam()->getSession())->setParameter('learner',$attempt->getLearner())->orderBy('e.category','ASC')->getQuery()->getResult();
        return $this->page('show',$entite,['sessionAttempts'=>$sessionAttempts,'dossier'=>$attempt,'error'=>$error,'trainers'=>$this->isGranted(TenantPermission::ADMIN,$entite)?$this->trainers($entite):[]]);
    }
    #[Route('/dossiers/{id}/page/{page}',name:'source',requirements:['id'=>'\d+','page'=>'\d+'],methods:['GET'])]
    public function source(Entite $entite,CacesAttempt $attempt,int $page): Response
    {
        $this->scoped($entite,$attempt);
        if($attempt->getLearner()->getId()===$this->getUser()->getId()) { if(!$attempt->getStartedAt() || $attempt->getSubmittedAt() || new \DateTimeImmutable()>=$this->workflow->deadline($attempt))throw $this->createAccessDeniedException(); }
        else $this->examiner($entite,$attempt);
        $key=$attempt->getExam()->getTheorySnapshot()['schema']['source']['key']??'';
        try{$path=$this->library->image($key,$page);}catch(\InvalidArgumentException){throw $this->createNotFoundException();}
        return new Response(file_get_contents($path),200,['Content-Type'=>'image/png','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    #[Route('/modeles/{key}/page/{page}',name:'model_source',requirements:['page'=>'\\d+'],methods:['GET'])]
    public function modelSource(Entite $entite,string $key,int $page): Response
    {
        $this->admin($entite);
        try{$item=$this->library->find($entite,$key);$path=$this->library->image($item['schema']['source']['key']??'', $page);}catch(\InvalidArgumentException){throw $this->createNotFoundException();}
        return new Response(file_get_contents($path),200,['Content-Type'=>'image/png','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    private function signature(Request $r,string $field): string
    {
        $value=$r->request->getString($field);
        if(strlen($value)>300000 || !preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~D',$value,$m) || !($bytes=base64_decode($m[1],true)) || !($info=@getimagesizefromstring($bytes)) || $info[2]!==IMAGETYPE_PNG) throw new \InvalidArgumentException('Veuillez apposer votre signature.');
        return $value;
    }
    private function fullName(Utilisateur $u): string { return trim($u->getPrenom().' '.$u->getNom()); }
}
