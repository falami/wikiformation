<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{Entite,Formateur,TrainerProfile,TrainerDiploma};
use App\Service\TrainerProfile\{CalendarSync,Availability};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
final class TrainerProfileController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private CalendarSync $calendar, #[Autowire('%kernel.project_dir%/var/storage/trainer-diplomas')] private string $directory) {}
    private function own(Entite $entite): Formateur {
        $this->denyAccessUnlessGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE,$entite);
        return $this->em->getRepository(Formateur::class)->findOneBy(['entite'=>$entite,'utilisateur'=>$this->getUser()]) ?? throw $this->createNotFoundException('Profil formateur introuvable.');
    }
    private function profile(Formateur $trainer): TrainerProfile {
        $profile=$this->em->getRepository(TrainerProfile::class)->findOneBy(['formateur'=>$trainer]);
        if (!$profile) { $profile=new TrainerProfile(); $profile->formateur=$trainer; }
        return $profile;
    }
    #[Route('/formateur/{entite}/profil',name:'app_formateur_profile',methods:['GET','POST'])]
    public function edit(Entite $entite, Request $request): Response {
        $trainer=$this->own($entite); $profile=$this->profile($trainer);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('trainer_profile_'.$trainer->getId(),$request->request->get('_token'))) throw $this->createAccessDeniedException();
            try {
                $action=$request->request->getString('action');
                if ($action==='save') {
                    $subjects=[];
                    foreach ($request->request->all('subjects') as $subject) {
                        if (!is_string($subject) || mb_strlen(trim($subject))>100) throw new \InvalidArgumentException('Chaque matière doit faire moins de 100 caractères.');
                        $subject=trim(preg_replace('/\s+/u',' ',$subject)); if ($subject!=='') $subjects[mb_strtolower($subject)]=$subject;
                    }
                    if(count($subjects)>50) throw new \InvalidArgumentException('Sélectionnez au maximum 50 matières.');
                    $days=array_values(array_intersect([1,2,3,4,5,6,7],array_map('intval',$request->request->all('days'))));
                    $url=trim($request->request->getString('calendarUrl'));
                    $encrypted=$url!==''?$this->calendar->encrypt($url):null;
                    $profile->subjects=array_values($subjects);$profile->workingDays=$days;$profile->declaredAvailable=$request->request->getBoolean('declaredAvailable');
                    if ($request->request->getBoolean('disconnect') || $encrypted) { $profile->calendarSecret=$encrypted; $profile->busy=[];$profile->syncedAt=null;$profile->coveredUntil=null;$profile->syncError=null; }
                    if ($encrypted) $this->calendar->sync($profile);
                } elseif($action==='sync') {
                    if (!$profile->attemptedAt || $profile->attemptedAt < new \DateTimeImmutable('-1 minute')) $this->calendar->sync($profile);
                } elseif($action==='block') {
                    $start=$request->request->getString('start');$end=$request->request->getString('end');$period=$request->request->getString('period');
                    foreach([$start,$end] as $value) { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Choisissez des dates valides.'); }
                    if($end<$start || !in_array($period,['DAY','AM','PM'],true) || count($profile->unavailable)>=100) throw new \InvalidArgumentException('Vérifiez la période (100 indisponibilités maximum).');
                    $profile->unavailable[]=['id'=>bin2hex(random_bytes(8)),'start'=>$start,'end'=>$end,'period'=>$period];
                } elseif($action==='unblock') {
                    $profile->unavailable=array_values(array_filter($profile->unavailable,fn($b)=>$b['id']!==$request->request->getString('block')));
                } elseif($action==='upload') {
                    $file=$request->files->get('diploma');$title=trim($request->request->getString('title'));
                    $mime=$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid()?$file->getMimeType():null;$ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime??'']??null;
                    if(!$file || !$ext || $file->getSize()>10000000 || $title==='' || mb_strlen($title)>180) throw new \InvalidArgumentException('Ajoutez un titre et un PDF, JPG ou PNG de 10 Mo maximum.');
                    $diploma=new TrainerDiploma();$diploma->formateur=$trainer;$diploma->title=$title;$diploma->mime=$mime;$diploma->filename=bin2hex(random_bytes(24)).'.'.$ext;$diploma->originalName=mb_substr(basename($file->getClientOriginalName()),0,255);
                    if(!is_dir($this->directory)) mkdir($this->directory,0700,true);
                    $file->move($this->directory,$diploma->filename);
                    try { $this->em->persist($diploma);$this->em->flush(); } catch(\Throwable $e) { unlink($this->directory.'/'.$diploma->filename);throw $e; }
                } elseif($action==='deleteDiploma') {
                    $diploma=$this->em->find(TrainerDiploma::class,$request->request->getInt('diplomaId'));
                    if(!$diploma || $diploma->formateur->getId()!==$trainer->getId()) throw $this->createNotFoundException();
                    $path=$this->directory.'/'.$diploma->filename;$this->em->remove($diploma);$this->em->flush();if(is_file($path)) unlink($path);
                } else throw new \InvalidArgumentException('Action invalide.');
                $this->em->persist($profile);$this->em->flush();
                $this->addFlash($profile->syncError?'warning':'success',$profile->syncError ?? 'Votre profil a été mis à jour.');
                return $this->redirectToRoute('app_formateur_profile',['entite'=>$entite->getId()]);
            } catch (\InvalidArgumentException $e) { $this->addFlash('danger',$e->getMessage()); }
        }
        return $this->render('formateur/profile/edit.html.twig',['entite'=>$entite,'trainer'=>$trainer,'profile'=>$profile,'subjects'=>array_values(array_unique(array_merge($this->subjects($entite),$profile->subjects))),'diplomas'=>$this->em->getRepository(TrainerDiploma::class)->findBy(['formateur'=>$trainer],['uploadedAt'=>'DESC'])]);
    }
    private function subjects(Entite $entite): array {
        $subjects=['Bureautique','Excel','Word','PowerPoint','Google Sheets','Management','Communication','Habilitation électrique','Prévention et sécurité'];
        foreach($this->em->createQueryBuilder()->select('p')->from(TrainerProfile::class,'p')->join('p.formateur','f')->where('f.entite = :e')->setParameter('e',$entite)->getQuery()->getResult() as $p) $subjects=array_merge($subjects,$p->subjects);
        $subjects=array_values(array_unique($subjects));natcasesort($subjects);return $subjects;
    }
    #[Route('/formateur/{entite}/diplomes/{id}',name:'app_trainer_diploma_download',methods:['GET'])]
    public function diploma(Entite $entite,TrainerDiploma $diploma): Response {
        if($diploma->formateur->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
        if(!$this->isGranted(TenantPermission::FORMATEUR_MANAGE,$entite) && $this->own($entite)->getId()!==$diploma->formateur->getId()) throw $this->createAccessDeniedException();
        $response=$this->file($this->directory.'/'.basename($diploma->filename),$diploma->originalName);$response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Content-Type-Options','nosniff');return $response;
    }
    #[Route('/administrateur/{entite}/formateurs/disponibilites',name:'app_admin_trainer_availability',methods:['GET'])]
    #[IsGranted(TenantPermission::PLANNING_FORMATEUR_MANAGE,subject:'entite')]
    public function planning(Entite $entite,Request $request,Availability $availability): Response {
        $start=$this->week($request);$subject=mb_strtolower(trim($request->query->getString('subject')));$rows=[];
        $trainers=$this->em->getRepository(Formateur::class)->findBy(['entite'=>$entite]);
        $rawSelected = $request->query->all()['trainers'] ?? [];
        $selectedTrainers = array_values(array_unique(array_map('intval', array_filter(is_array($rawSelected) ? $rawSelected : [], static fn($id) => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0))));
        foreach($trainers as $trainer) {
            if ($selectedTrainers && !in_array($trainer->getId(), $selectedTrainers, true)) continue;
            $profile=$this->profile($trainer);
            if($subject!=='' && !in_array($subject,array_map('mb_strtolower',$profile->subjects),true)) continue;
            $rows[]=['trainer'=>$trainer,'profile'=>$profile];
        }
        $page=max(1,$request->query->getInt('page',1));$total=count($rows);$page=min($page,max(1,(int)ceil($total/25)));$rows=array_slice($rows,($page-1)*25,25);
        foreach($rows as &$row) { $row['days']=$availability->days($row['trainer'],$row['profile'],$start);$row['diplomas']=$this->em->getRepository(TrainerDiploma::class)->findBy(['formateur'=>$row['trainer']]); } unset($row);
        return $this->render('formateur/profile/planning.html.twig',['entite'=>$entite,'rows'=>$rows,'start'=>$start,'trainers'=>$trainers,'selectedTrainers'=>$selectedTrainers,'subjects'=>$this->subjects($entite),'subject'=>$request->query->getString('subject'),'page'=>$page,'total'=>$total]);
    }
    private function week(Request $request): \DateTimeImmutable {
        $value=$request->query->getString('date',(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Paris')))->format('Y-m-d'));
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Paris'));
        if(!$date || $date->format('Y-m-d')!==$value) throw $this->createNotFoundException('Date invalide.');
        return $date->modify('monday this week');
    }
    #[Route('/administrateur/{entite}/formateurs/disponibilites/{id}/sync',name:'app_admin_trainer_sync',methods:['POST'])]
    #[IsGranted(TenantPermission::PLANNING_FORMATEUR_MANAGE,subject:'entite')]
    public function sync(Entite $entite,Formateur $trainer,Request $request,Availability $availability): Response {
        if($trainer->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
        if(!$this->isCsrfTokenValid('availability_'.$entite->getId(),$request->request->get('_token'))) throw $this->createAccessDeniedException();
        $profile=$this->profile($trainer);
        if($profile->calendarSecret && (!$profile->attemptedAt || $profile->attemptedAt<new \DateTimeImmutable('-15 minutes'))) { $this->calendar->sync($profile);$this->em->flush(); }
        return $this->json(['days'=>$availability->days($trainer,$profile,$this->week($request)),'status'=>$profile->syncError ?? ($profile->syncedAt?'Synchronisé le '.$profile->syncedAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m H:i'):'Agenda non connecté')]);
    }
}
