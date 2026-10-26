<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{Entite, Formateur, TrainerCharter, TrainerCharterVersion, TrainerCharterSignature, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\TrainerCharter\CharterManager;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class TrainerCharterController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private CharterManager $manager, private PdfManager $pdf) {}
    private function admin(Entite $entite): void { $this->denyAccessUnlessGranted(TenantPermission::FORMATEUR_MANAGE,$entite); }
    private function own(Entite $entite): Formateur {
        $this->denyAccessUnlessGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE,$entite);
        return $this->em->getRepository(Formateur::class)->findOneBy(['entite'=>$entite,'utilisateur'=>$this->getUser()]) ?? throw $this->createNotFoundException();
    }
    private function version(Entite $entite, int $id): TrainerCharterVersion {
        $v=$this->em->find(TrainerCharterVersion::class,$id);
        if (!$v || $v->charter->entite->getId()!==$entite->getId()) throw $this->createNotFoundException();
        return $v;
    }
    private function csrf(Request $request, string $id): void { if (!$this->isCsrfTokenValid($id,$request->request->get('_token'))) throw $this->createAccessDeniedException(); }
    private function response(string $template, array $data): Response { $r=$this->render($template,$data);$r->headers->set('Cache-Control','private, no-store');return $r; }
    private function trainerName(Formateur $t): string { return trim($t->getUtilisateur()?->getPrenom().' '.$t->getUtilisateur()?->getNom()); }

    #[Route('/administrateur/{entite}/formateur/chartes',name:'app_charter_admin',methods:['GET'])]
    public function index(Entite $entite): Response {
        $this->admin($entite);
        $charters=$this->em->getRepository(TrainerCharter::class)->findBy(['entite'=>$entite],['id'=>'DESC']);
        $rows=[];
        foreach ($charters as $c) $rows[]=['charter'=>$c,'versions'=>$this->em->getRepository(TrainerCharterVersion::class)->findBy(['charter'=>$c],['number'=>'DESC']),'latest'=>$this->manager->latest($c)];
        return $this->response('administrateur/formateur/charte/admin.html.twig',compact('entite','rows'));
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/supprimer',name:'app_charter_delete',requirements:['id'=>'\\d+'],methods:['POST'])]
    public function delete(Entite $entite, int $id, Request $request): Response {
        $this->admin($entite);
        $this->csrf($request, 'charter_delete_'.$id);
        $charter = $this->em->getRepository(TrainerCharter::class)->findOneBy(['id'=>$id, 'entite'=>$entite]);
        if (!$charter) throw $this->createNotFoundException();
        try {
            $this->em->wrapInTransaction(function () use ($charter): void {
                $versions = $this->em->getRepository(TrainerCharterVersion::class)->findBy(['charter'=>$charter]);
                foreach ($versions as $version) {
                    $signatures = $this->em->getRepository(TrainerCharterSignature::class)->findBy(['version'=>$version]);
                    foreach ($signatures as $signature) {
                        $this->em->lock($signature, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                        $this->em->refresh($signature);
                        if ($signature->signedAt || $signature->pdfDigest || $signature->pdfBytes() !== '') {
                            throw new \DomainException('Cette charte possède un exemplaire signé : elle est conservée avec ses signatures et ne peut pas être supprimée.');
                        }
                        $this->em->remove($signature);
                    }
                    $this->em->remove($version);
                }
                $this->em->remove($charter);
            });
            $this->addFlash('success', 'La charte, ses versions et ses demandes non signées ont été supprimées.');
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }
        return $this->redirectToRoute('app_charter_admin', ['entite'=>$entite->getId()]);
    }
    #[Route('/administrateur/{entite}/formateur/chartes/version/{id}/supprimer', name:'app_charter_version_delete', requirements:['id'=>'\d+'], methods:['POST'])]
    public function deleteDraft(Entite $entite, int $id, Request $request): Response {
        $this->admin($entite);
        $this->csrf($request, 'charter_version_delete_'.$id);
        $version = $this->em->find(TrainerCharterVersion::class, $id);
        if (!$version || $version->charter->entite->getId() !== $entite->getId()) throw $this->createNotFoundException();
        try {
            $this->em->wrapInTransaction(function () use ($version): void {
                $this->em->lock($version, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($version);
                if ($version->publishedAt) throw new \DomainException('Une version publiée ne peut pas être supprimée.');
                $signatures = $this->em->getRepository(TrainerCharterSignature::class)->findBy(['version'=>$version]);
                foreach ($signatures as $signature) {
                    $this->em->lock($signature, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    $this->em->refresh($signature);
                    if ($signature->signedAt || $signature->pdfDigest || $signature->pdfBytes() !== '') {
                        throw new \DomainException('Une version signée ne peut pas être supprimée.');
                    }
                    $this->em->remove($signature);
                }
                $this->em->remove($version);
            });
            $this->addFlash('success', 'Le brouillon a été supprimé. Les autres versions sont conservées.');
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }
        return $this->redirectToRoute('app_charter_admin', ['entite'=>$entite->getId()]);
    }

    #[Route('/administrateur/{entite}/formateur/chartes/nouvelle',name:'app_charter_new',methods:['POST'])]
    public function create(Entite $entite, Request $request): Response {
        $this->admin($entite);$this->csrf($request,'charter_new_'.$entite->getId());
        $c=new TrainerCharter();$c->entite=$entite;
        $v=new TrainerCharterVersion();$v->charter=$c;$v->title=$c->title;$v->chapters=CharterManager::starter();$v->effectiveAt=new \DateTimeImmutable('today');$v->authorName=$this->getUser()->getUserIdentifier();
        $this->em->persist($c);$this->em->persist($v);$this->em->flush();
        return $this->redirectToRoute('app_charter_edit',['entite'=>$entite->getId(),'id'=>$v->id]);
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/editer',name:'app_charter_edit',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function edit(Entite $entite,int $id,Request $request): Response {
        $this->admin($entite);$v=$this->version($entite,$id);
        if ($v->publishedAt) throw new ConflictHttpException('Version publiée : créez une nouvelle version pour la modifier.');
        $error=null;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'charter_edit_'.$id);
            if ($request->request->getInt('revision')!==$v->revision) throw new ConflictHttpException('Le brouillon a changé. Rechargez la page.');
            try {
                $title=trim($request->request->getString('title'));$note=trim($request->request->getString('changeNote'));
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$request->request->getString('effectiveAt'));
                if (!$title || mb_strlen($title)>180 || mb_strlen($note)>1000 || !$date || $date->format('Y-m-d')!==$request->request->getString('effectiveAt')) throw new \InvalidArgumentException('Vérifiez le titre, la date d’application et la note de version.');
                $chapters=json_decode($request->request->getString('chapters'),true,32,JSON_THROW_ON_ERROR);
                if (!is_array($chapters)) throw new \InvalidArgumentException('Contenu invalide.');
                $v->chapters=$this->manager->normalize($chapters);$v->title=$title;$v->effectiveAt=$date;$v->changeNote=$note;
                $v->charter->title=$title;
                if ($request->request->get('action')==='publish') {
                    $latest=$this->manager->latest($v->charter);
                    if ($latest && $latest->number >= $v->number) throw new \InvalidArgumentException('Une version plus récente est déjà publiée.');
                    $v->issuer=['name'=>$entite->getNom()];
                    $v->publishedAt=new \DateTimeImmutable();$v->digest=$v->hash();
                }
                $this->em->flush();
                $this->addFlash('success',$v->publishedAt?'Version publiée et figée. Sélectionnez les formateurs à solliciter.':'Brouillon enregistré.');
                return $this->redirectToRoute($v->publishedAt?'app_charter_tracking':'app_charter_edit',['entite'=>$entite->getId(),'id'=>$id]);
            } catch (\InvalidArgumentException|\JsonException $e) { $error=$e->getMessage(); }
        }
        return $this->response('administrateur/formateur/charte/edit.html.twig',['entite'=>$entite,'v'=>$v,'error'=>$error]);
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/dupliquer',name:'app_charter_duplicate',requirements:['id'=>'\d+'],methods:['POST'])]
    public function duplicate(Entite $entite,int $id,Request $request): Response {
        $this->admin($entite);$this->csrf($request,'charter_duplicate_'.$id);$source=$this->version($entite,$id);
        $last=$this->em->getRepository(TrainerCharterVersion::class)->findOneBy(['charter'=>$source->charter],['number'=>'DESC']);
        $v=new TrainerCharterVersion();$v->charter=$source->charter;$v->number=$last->number+1;$v->title=$source->title;$v->chapters=$source->chapters;$v->effectiveAt=new \DateTimeImmutable('today');$v->authorName=$this->getUser()->getUserIdentifier();
        $this->em->persist($v);$this->em->flush();
        return $this->redirectToRoute('app_charter_edit',['entite'=>$entite->getId(),'id'=>$v->id]);
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/suivi',name:'app_charter_tracking',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function tracking(Entite $entite,int $id,Request $request): Response {
        $this->admin($entite);$v=$this->version($entite,$id);
        if (!$v->publishedAt) throw $this->createNotFoundException();
        $trainers=$this->em->getRepository(Formateur::class)->findBy(['entite'=>$entite]);
        $current=$this->manager->latest($v->charter)?->id===$v->id;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'charter_request_'.$id);
            if (!$current) throw new ConflictHttpException('Demandez la dernière version publiée.');
            $ids=$request->request->all('trainers');$valid=array_map(fn($t)=>(string)$t->getId(),$trainers);
            if (!$ids || array_filter($ids,fn($x)=>!is_scalar($x)) || array_diff($ids,$valid)) throw $this->createAccessDeniedException('Sélection de formateurs invalide.');
            $count=0;
            foreach ($trainers as $t) {
                if (!in_array((string)$t->getId(),$ids,true) || !$t->getUtilisateur()) continue;
                if ($this->em->getRepository(TrainerCharterSignature::class)->findOneBy(['version'=>$v,'trainer'=>$t])) continue;
                $s=new TrainerCharterSignature();$s->version=$v;$s->trainer=$t;$s->requestedBy=$this->getUser()->getUserIdentifier();$this->em->persist($s);++$count;
            }
            $this->em->flush();$this->addFlash('success',$count.' nouvelle(s) demande(s) disponible(s) dans les espaces formateurs. Les demandes existantes sont conservées.');
            return $this->redirectToRoute('app_charter_tracking',['entite'=>$entite->getId(),'id'=>$id]);
        }
        $rows=[];$signed=0;$pending=0;
        foreach ($trainers as $t) {
            $s=$this->em->getRepository(TrainerCharterSignature::class)->findOneBy(['version'=>$v,'trainer'=>$t]);
            $older=$this->em->getRepository(TrainerCharterSignature::class)->createQueryBuilder('s')->join('s.version','v')->where('s.trainer = :t')->andWhere('v.charter = :c')->andWhere('s.signedAt IS NOT NULL')->andWhere('v.number < :n')->setParameter('t',$t)->setParameter('c',$v->charter)->setParameter('n',$v->number)->orderBy('v.number','DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            $status=$s?->signedAt?'signed':($s?'pending':($older?'outdated':'missing'));
            $signed+=(int)($status==='signed');$pending+=(int)($status==='pending');
            $rows[]=['trainer'=>$t,'name'=>$this->trainerName($t),'signature'=>$s,'older'=>$older,'status'=>$status];
        }
        return $this->response('administrateur/formateur/charte/tracking.html.twig',compact('entite','v','rows','signed','pending','current'));
    }
    #[Route('/formateur/{entite}/chartes',name:'app_charter_mine',methods:['GET'])]
    public function mine(Entite $entite): Response {
        $t=$this->own($entite);$items=[];
        foreach ($this->manager->forTrainer($entite,$t) as $s) $items[]=['signature'=>$s,'current'=>$this->manager->latest($s->version->charter)?->id===$s->version->id];
        return $this->response('administrateur/formateur/charte/mine.html.twig',['entite'=>$entite, 'items'=>$items, 'available'=>$this->manager->available($entite, $t)]);
    }
    #[Route('/formateur/{entite}/chartes/version/{id}/ouvrir',name:'app_charter_open',requirements:['id'=>'\\d+'],methods:['POST'])]
    public function openPublished(Entite $entite, int $id, Request $request): Response {
        $trainer = $this->own($entite);
        $this->csrf($request, 'charter_open_'.$id);
        $version = $this->version($entite, $id);
        if (!$version->publishedAt || $this->manager->latest($version->charter)?->id !== $version->id) throw $this->createNotFoundException();
        $signature = $this->em->getRepository(TrainerCharterSignature::class)->findOneBy(['version'=>$version, 'trainer'=>$trainer]);
        if (!$signature) {
            $signature = new TrainerCharterSignature();
            $signature->version = $version; $signature->trainer = $trainer;
            $signature->requestedBy = $this->getUser()->getUserIdentifier();
            $this->em->persist($signature);
            try { $this->em->flush(); }
            catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { throw new ConflictHttpException('Cette charte vient d’être ouverte. Revenez à vos chartes pour continuer.'); }
        }
        return $this->redirectToRoute('app_charter_sign', ['entite'=>$entite->getId(), 'id'=>$signature->id]);
    }
    #[Route('/formateur/{entite}/chartes/{id}',name:'app_charter_sign',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function sign(Entite $entite,int $id,Request $request): Response {
        $t=$this->own($entite);$s=$this->em->find(TrainerCharterSignature::class,$id);
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        if (!$s || $s->trainer->getId()!==$t->getId() || $s->version->charter->entite->getId()!==$entite->getId()) throw $this->createNotFoundException();
        $v=$s->version;$current=$this->manager->latest($v->charter)?->id===$v->id;$error=null;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'charter_sign_'.$id);
            if (!$current || $s->signedAt || !hash_equals($v->digest??'', $v->hash()) || !hash_equals($v->digest??'', $request->request->getString('digest'))) throw new ConflictHttpException('Cette demande a changé ou est déjà signée. Rechargez la page.');
            $name=trim($request->request->getString('signerName'));
            if (!$request->request->getBoolean('consent') || mb_strlen($name)<3 || mb_strlen($name)>180) $error='Saisissez votre nom complet et confirmez votre engagement.';
            else {
                $s->signedAt=new \DateTimeImmutable();
                $s->evidence=['name'=>$name,'accountName'=>$this->trainerName($t),'email'=>$user->getUserIdentifier(),'userId'=>$user->getId(),'ip'=>$request->getClientIp(),'userAgent'=>mb_substr($request->headers->get('User-Agent',''),0,500),'digest'=>$v->digest,'consent'=>'J’ai lu cette version et je m’engage à respecter ses dispositions.','method'=>'Acceptation électronique depuis le compte authentifié avec saisie du nom et consentement explicite.'];
                $pdf=$this->document($v,$s);$s->signedPdf=$pdf->getContent();$s->pdfDigest=hash('sha256',$s->signedPdf);
                $this->em->flush();$this->addFlash('success','Votre charte est signée. Votre exemplaire est conservé dans cet espace.');
                return $this->redirectToRoute('app_charter_sign',['entite'=>$entite->getId(),'id'=>$id]);
            }
        }
        return $this->response('administrateur/formateur/charte/sign.html.twig',compact('entite','s','v','current','error'));
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/export',name:'app_charter_export',requirements:['id'=>'\d+'],methods:['GET'])]
    public function export(Entite $entite,int $id): Response {
        $this->admin($entite);$v=$this->version($entite,$id);
        $signatures=$this->em->getRepository(TrainerCharterSignature::class)->findBy(['version'=>$v]);
        $path=tempnam(sys_get_temp_dir(),'charter-');$zip=new \ZipArchive();
        try {
            if ($zip->open($path,\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Archive indisponible.');
            $zip->addFromString('charte-v'.$v->number.'.pdf',$this->document($v)->getContent());
            $manifest=['version'=>$v->payload(),'digest'=>$v->digest,'publishedAt'=>$v->publishedAt?->format(DATE_ATOM),'signatures'=>[]];
            foreach ($signatures as $s) {
                if ($s->trainer->getEntite()?->getId()!==$entite->getId()) continue;
                $entry=['trainerId'=>$s->trainer->getId(),'name'=>$this->trainerName($s->trainer),'requestedAt'=>$s->requestedAt->format(DATE_ATOM),'signedAt'=>$s->signedAt?->format(DATE_ATOM),'evidence'=>$s->evidence,'pdfDigest'=>$s->pdfDigest];
                if ($s->signedAt) {
                    $bytes=$s->pdfBytes();if (!hash_equals($s->pdfDigest??'',hash('sha256',$bytes))) throw new ConflictHttpException('Intégrité du PDF non vérifiée.');
                    $entry['file']='signatures/formateur-'.$s->trainer->getId().'.pdf';$zip->addFromString($entry['file'],$bytes);
                }
                $manifest['signatures'][]=$entry;
            }
            $zip->addFromString('preuves.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $zip->close();$bytes=file_get_contents($path);
        } finally { if (is_file($path)) unlink($path); }
        return new Response($bytes,200,['Content-Type'=>'application/zip','Content-Disposition'=>'attachment; filename="Chartes-v'.$v->number.'.zip"','Cache-Control'=>'private, no-store']);
    }
    private function document(TrainerCharterVersion $v, ?TrainerCharterSignature $s=null): Response {
        return $this->pdf->createPortrait($this->renderView('administrateur/formateur/charte/pdf.html.twig',['v'=>$v,'s'=>$s]),'Charte-v'.$v->number.($s?'-formateur-'.$s->trainer->getId():''));
    }
    #[Route('/administrateur/{entite}/formateur/chartes/{id}/pdf',name:'app_charter_version_pdf',requirements:['id'=>'\d+'],methods:['GET'])]
    public function versionPdf(Entite $entite,int $id): Response { $this->admin($entite);$r=$this->document($this->version($entite,$id));$r->headers->set('Cache-Control','private, no-store');return $r; }
    #[Route('/charte/{entite}/signature/{id}/pdf',name:'app_charter_signed_pdf',requirements:['id'=>'\d+'],methods:['GET'])]
    public function signedPdf(Entite $entite,int $id): Response {
        $s=$this->em->find(TrainerCharterSignature::class,$id);
        if (!$s || $s->version->charter->entite->getId()!==$entite->getId() || $s->trainer->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
        if (!$this->isGranted(TenantPermission::FORMATEUR_MANAGE,$entite) && $this->own($entite)->getId()!==$s->trainer->getId()) throw $this->createAccessDeniedException();
        if (!$s->signedAt) throw $this->createNotFoundException();
        $bytes=$s->pdfBytes();if (!hash_equals($s->pdfDigest??'',hash('sha256',$bytes))) throw new ConflictHttpException('Le contrôle d’intégrité du document a échoué.');
        return new Response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="Charte-v'.$s->version->number.'-formateur-'.$s->trainer->getId().'.pdf"','Cache-Control'=>'private, no-store']);
    }
}
