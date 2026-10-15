<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{Entite,Formateur,TrainerCv,TrainerProfile,TrainerDiploma,Inscription,Session};
use App\Security\Permission\TenantPermission;
use App\Service\TrainerProfile\CvImporter;
use App\Service\Avatar\FormateurAvatarResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
final class TrainerCvController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private CvImporter $importer, private FormateurAvatarResolver $avatars, #[Autowire('%kernel.project_dir%/var/storage/trainer-cv')] private string $directory) {}
    private function cv(Formateur $trainer): TrainerCv { $cv=$this->em->getRepository(TrainerCv::class)->findOneBy(['formateur'=>$trainer]);if(!$cv){$cv=new TrainerCv();$cv->formateur=$trainer;}return $cv; }
    private function check(Entite $entite, Formateur $trainer): void { if($trainer->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException(); }
    private function own(Entite $entite): Formateur {
        $this->denyAccessUnlessGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE,$entite);
        return $this->em->getRepository(Formateur::class)->findOneBy(['entite'=>$entite,'utilisateur'=>$this->getUser()])??throw $this->createNotFoundException();
    }
    #[Route('/formateur/{entite}/cv',name:'app_trainer_cv_edit',methods:['GET','POST'])]
    public function edit(Entite $entite, Request $request): Response {
        $trainer=$this->own($entite);$cv=$this->cv($trainer);
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('trainer_cv_'.$trainer->getId(),$request->request->get('_token'))) throw $this->createAccessDeniedException();
            if($request->request->getInt('version')!==$cv->version) throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Le CV a été modifié ailleurs. Rechargez la page.');
            try {
                $action=$request->request->getString('action');
                if($action==='save' || $action==='publish') {
                    $content=CvImporter::normalize($request->request->all('cv'));
                    if($action==='publish' && ($content['headline']==='' || $content['summary']==='')) throw new \InvalidArgumentException('Complétez le titre et la présentation avant de publier.');
                    $cv->content=$content;
                    if($action==='publish') {
                        $profile=$this->em->getRepository(TrainerProfile::class)->findOneBy(['formateur'=>$trainer]);
                        $cv->published=$content+['subjects'=>$profile?->subjects??[]];
                    }
                } elseif($action==='unpublish') { $cv->published=null;
                } elseif($action==='upload') {
                    $file=$request->files->get('cvFile');
                    if(!$file instanceof UploadedFile || !$file->isValid() || $file->getMimeType()!=='application/pdf' || $file->getSize()>10000000) throw new \InvalidArgumentException('Choisissez un PDF de 10 Mo maximum.');
                    $filename=bin2hex(random_bytes(24)).'.pdf';
                    if(!is_dir($this->directory)) mkdir($this->directory,0700,true);
                    $file->move($this->directory,$filename);$old=$cv->filename;
                    $cv->filename=$filename;$cv->originalName=mb_substr(basename($file->getClientOriginalName()),0,255);$cv->suggestion=null;
                    try {$this->em->persist($cv);$this->em->flush();}catch(\Throwable $e){unlink($this->directory.'/'.$filename);throw $e;}
                    if($old && is_file($this->directory.'/'.basename($old))) unlink($this->directory.'/'.basename($old));
                    if($request->request->getBoolean('analyze')) $cv->suggestion=$this->importer->import($this->directory.'/'.$filename);
                } elseif($action==='analyze') {
                    if(!$cv->filename) throw new \InvalidArgumentException('Déposez d’abord votre CV PDF.');
                    $cv->suggestion=$this->importer->import($this->directory.'/'.basename($cv->filename));
                } elseif($action==='accept') {
                    if(!$cv->suggestion) throw new \InvalidArgumentException('Aucune proposition à reprendre.');
                    $cv->content=CvImporter::normalize($cv->suggestion);$cv->suggestion=null;
                } elseif($action==='dismiss') { $cv->suggestion=null;
                } else throw new \InvalidArgumentException('Action inconnue.');
                $this->em->persist($cv);$this->em->flush();$this->addFlash('success','Votre CV a été mis à jour.');
                return $this->redirectToRoute('app_trainer_cv_edit',['entite'=>$entite->getId()]);
            } catch(\InvalidArgumentException|\RuntimeException $e) { $this->addFlash('warning',$e->getMessage()); }
        }
        return $this->render('formateur/cv/edit.html.twig',['entite'=>$entite,'trainer'=>$trainer,'cv'=>$cv,'fields'=>CvImporter::FIELDS]);
    }
    #[Route('/administrateur/{entite}/formateur/{id}/cv',name:'app_admin_trainer_cv',methods:['GET'])]
    public function admin(Entite $entite,Formateur $trainer): Response {
        $this->denyAccessUnlessGranted(TenantPermission::FORMATEUR_MANAGE,$entite);$this->check($entite,$trainer);
        return $this->show($entite,$trainer,true);
    }
    #[Route('/formateur/{entite}/cv/apercu',name:'app_trainer_cv_preview',methods:['GET'])]
    public function preview(Entite $entite): Response {return $this->show($entite,$this->own($entite),true);}
    private function show(Entite $entite,Formateur $trainer,bool $private): Response {
        $cv=$this->cv($trainer);$profile=$this->em->getRepository(TrainerProfile::class)->findOneBy(['formateur'=>$trainer]);
        $response=$this->render('formateur/cv/show.html.twig',['entite'=>$entite,'trainer'=>$trainer,'cv'=>$cv,'content'=>$private?$cv->content:($cv->published??[]),'subjects'=>$private?($profile?->subjects??[]):($cv->published['subjects']??[]),'fields'=>CvImporter::FIELDS,'private'=>$private,'photo'=>$this->avatars->publicPath($trainer),'diplomas'=>$private?$this->em->getRepository(TrainerDiploma::class)->findBy(['formateur'=>$trainer]):[]]);
        $response->headers->set('Cache-Control','private, no-store');return $response;
    }
    #[Route('/formateur/{entite}/{id}/cv/fichier',name:'app_trainer_cv_download',methods:['GET'])]
    public function download(Entite $entite,Formateur $trainer): Response {
        $this->check($entite,$trainer);
        if(!$this->isGranted(TenantPermission::FORMATEUR_MANAGE,$entite) && $this->own($entite)->getId()!==$trainer->getId()) throw $this->createAccessDeniedException();
        $cv=$this->cv($trainer);if(!$cv->filename || !is_file($this->directory.'/'.basename($cv->filename))) throw $this->createNotFoundException();
        $response=$this->file($this->directory.'/'.basename($cv->filename),$cv->originalName);$response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Content-Type-Options','nosniff');return $response;
    }
    #[Route('/stagiaire/{entite}/session/{id}/formateurs',name:'app_learner_trainer_cvs',methods:['GET'])]
    public function trainers(Entite $entite,Session $session): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        if($session->getEntite()?->getId()!==$entite->getId() || !$this->em->getRepository(Inscription::class)->findOneBy(['session'=>$session,'stagiaire'=>$this->getUser()])) throw $this->createAccessDeniedException();
        $rows=[];foreach($session->getFormateursEffectifs() as $trainer) { if($trainer->getEntite()?->getId()!==$entite->getId()) continue; $cv=$this->cv($trainer);if($cv->published) $rows[]=['trainer'=>$trainer,'cv'=>$cv]; }
        return $this->render('formateur/cv/list.html.twig',['entite'=>$entite,'session'=>$session,'rows'=>$rows]);
    }
    #[Route('/stagiaire/{entite}/session/{session}/formateur/{id}/cv',name:'app_learner_trainer_cv',methods:['GET'])]
    public function learner(Entite $entite,Session $session,Formateur $trainer): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');$this->check($entite,$trainer);
        if($session->getEntite()?->getId()!==$entite->getId() || !$this->em->getRepository(Inscription::class)->findOneBy(['session'=>$session,'stagiaire'=>$this->getUser()])) throw $this->createAccessDeniedException();
        $ids=array_map(fn($f)=>$f->getId(),$session->getFormateursEffectifs());
        if(!in_array($trainer->getId(),$ids,true) || !$this->cv($trainer)->published) throw $this->createNotFoundException();
        return $this->show($entite,$trainer,false);
    }
}
