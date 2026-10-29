<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{TrainerApplication,RecruitmentPlacement,Entite};
use App\Service\Recruitment\{Applications,Placement};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RecruitmentController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em,private Applications $applications) {}
    #[Route('/recrutement',name:'app_recruitment',methods:['GET','POST'])]
    public function apply(Request $r, #[Autowire(service:'limiter.recruitment')] RateLimiterFactory $limiter): Response
    {
        $error=null;
        if($r->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('recruitment',$r->request->getString('_token'))) throw $this->createAccessDeniedException('Formulaire expiré, rechargez la page.');
            if(!$limiter->create($r->getClientIp() ?? 'unknown')->consume()->isAccepted()) return new Response('Trop de tentatives. Réessayez dans une heure.',429);
            if($r->request->getString('website')!=='') return $this->redirectToRoute('app_recruitment_thanks');
            $a=null;
            try {
                $a=$this->applications->parse($r); $this->applications->upload($a,$r);
                $this->em->persist($a);$this->em->flush();
                return $this->redirectToRoute('app_recruitment_thanks');
            } catch(\InvalidArgumentException $e) { if($a) $this->applications->cleanup($a); $error=$e->getMessage(); }
            catch(\Throwable $e) { if($a) $this->applications->cleanup($a); throw $e; }
        }
        return $this->render('recruitment/public.html.twig',['error'=>$error,'values'=>$r->isMethod('POST')?$r->request->all():[], 'subjects'=>$this->applications->subjects(),'departments'=>$this->applications->departments()],new Response('', $error?422:200, ['Cache-Control'=>'private, no-store']));
    }
    #[Route('/recrutement/merci',name:'app_recruitment_thanks',methods:['GET'])]
    public function thanks(): Response { return $this->render('recruitment/thanks.html.twig'); }

    #[Route('/super/{entite}/recrutement',name:'app_super_recruitment',methods:['GET'])]
    public function index(Entite $entite,Request $r): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $q=mb_substr(trim($r->query->getString('q')),0,100);$subject=mb_substr(trim($r->query->getString('subject')),0,180);
        $department=$r->query->getString('department');$status=$r->query->getString('status');
        $basis=$r->query->getString('basis')==='hourly'?'hourly':'daily';
        $max=$r->query->getString('max');$page=max(1,$r->query->getInt('page',1));
        $qb=$this->em->createQueryBuilder()->select('a')->from(TrainerApplication::class,'a')->orderBy('a.createdAt','DESC')->addOrderBy('a.id','DESC');
        if($q!=='') $qb->andWhere('a.firstName LIKE :q OR a.lastName LIKE :q OR a.email LIKE :q')->setParameter('q','%'.$q.'%');
        if(in_array($status,['new','shortlisted','accepted','archived'],true)) $qb->andWhere('a.status = :status')->setParameter('status',$status);
        // JSON arrays contain canonical department codes and specialty labels.
        if($department && in_array($department,array_column($this->applications->departments(),'value'),true)) $qb->andWhere('a.nationwide = true OR a.departments LIKE :department')->setParameter('department','%"'.$department.'"%');
        if($subject!=='') $qb->andWhere('a.subjects LIKE :subject')->setParameter('subject','%'.addcslashes($subject,'%_').'%');
        if(is_numeric(str_replace(',','.',$max)) && (float)str_replace(',','.',$max)>=0) $qb->andWhere('a.'.($basis==='hourly'?'hourlyRate':'dailyRate').' <= :max')->setParameter('max',(int)round((float)str_replace(',','.',$max)*100));
        $filteredCount=(int)(clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $totalCount=$this->em->getRepository(TrainerApplication::class)->count([]);
        $rows=$qb->setFirstResult(($page-1)*30)->setMaxResults(31)->getQuery()->getResult();
        return $this->render('recruitment/index.html.twig',compact('entite','q','subject','department','status','basis','max','page','filteredCount','totalCount')+['applications'=>array_slice($rows,0,30),'more'=>count($rows)>30,'departments'=>$this->applications->departments(),'subjects'=>$this->applications->subjects()]);
    }
    #[Route('/super/{entite}/recrutement/{id}',name:'app_super_recruitment_show',methods:['GET','POST'],requirements:['id'=>'\d+'])]
    public function show(Entite $entite,TrainerApplication $application,Request $r,Placement $placement): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');$error=null;
        if($r->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('application_'.$application->id,$r->request->getString('_token'))) throw $this->createAccessDeniedException();
            try {
                if($r->request->getInt('version')!==$application->version) throw new \InvalidArgumentException('Candidature modifiée ailleurs. Rechargez la page.');
                if($r->request->getString('action')==='transfer') {
                    $ids=$r->request->all('entities');
                    $entities=$this->em->getRepository(Entite::class)->findBy(['id'=>$ids]);
                    if(count($entities)!==count(array_unique($ids))) throw new \InvalidArgumentException('Sélection d’entités invalide.');
                    $count=$placement->transfer($application,$entities,$this->getUser(),$r->request->getBoolean('confirmExisting'));
                    $this->addFlash('success',$count.' affectation(s) ajoutée(s). Les profils existants ont été conservés. Le compte peut utiliser « Mot de passe oublié » pour définir son mot de passe.');
                } else {
                    $status=$r->request->getString('status');
                    if(!in_array($status,['new','shortlisted','accepted','archived'],true)) throw new \InvalidArgumentException('Statut invalide.');
                    $notes=$r->request->getString('notes');
                    if(mb_strlen($notes)>10000) throw new \InvalidArgumentException('Notes trop longues.');
                    $application->status=$status;$application->notes=$notes;$this->em->flush();
                }
                return $this->redirectToRoute('app_super_recruitment_show',['entite'=>$entite->getId(),'id'=>$application->id]);
            } catch(\InvalidArgumentException $e) { $error=$e->getMessage(); if(!$this->em->isOpen()) { $this->addFlash('warning',$error); return $this->redirectToRoute('app_super_recruitment_show',['entite'=>$entite->getId(),'id'=>$application->id]); } }
        }
        return $this->render('recruitment/show.html.twig',compact('entite','application','error')+['departments'=>$this->applications->departments(),'entities'=>$this->em->getRepository(Entite::class)->findBy([],['nom'=>'ASC']),'placements'=>$this->em->getRepository(RecruitmentPlacement::class)->findBy(['application'=>$application])]);
    }
    #[Route('/super/{entite}/recrutement/{id}/fichier/{kind}',name:'app_super_recruitment_file',methods:['GET'],requirements:['id'=>'\d+','kind'=>'cv|photo|rcPro|qualiopi'])]
    public function fileDownload(Entite $entite,TrainerApplication $application,string $kind): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $name=match($kind) {'cv'=>$application->cvFilename,'photo'=>$application->photoFilename,default=>$application->details['documents'][$kind]['filename'] ?? null};
        if(!$name || !is_file($this->applications->directory().'/'.basename($name))) throw $this->createNotFoundException();
        $r=$this->file($this->applications->directory().'/'.basename($name),match($kind) {'cv'=>'CV.pdf','photo'=>'portrait.jpg','rcPro'=>'RC-Pro.pdf',default=>'Qualiopi.pdf'},$kind==='photo'?'inline':'attachment');
        $r->headers->set('Cache-Control','private, no-store');$r->headers->set('X-Content-Type-Options','nosniff');return $r;
    }
}
