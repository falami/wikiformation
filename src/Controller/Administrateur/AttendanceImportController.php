<?php
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Session, Inscription, Emargement, AuditLog};
use App\Enum\{DemiJournee, StatusInscription};
use App\Security\Permission\TenantPermission;
use App\Service\AssiduiteCalculator;
use App\Service\Attendance\PaperImport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse, ResponseHeaderBag};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/emargements/import', name:'app_admin_attendance_import_', requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::SESSION_MANAGE, subject:'entite')]
final class AttendanceImportController extends AbstractController
{
    #[Route('', name:'index', methods:['GET','POST'])]
    public function index(Entite $entite, Request $request, EntityManagerInterface $em, PaperImport $imports): Response
    {
        $sessions=$em->getRepository(Session::class)->findBy(['entite'=>$entite],['id'=>'DESC']);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('attendance_import_'.$entite->getId(),$request->request->get('_token'))) throw $this->createAccessDeniedException();
            $session=$em->getRepository(Session::class)->find($request->request->getInt('session'));
            if (!$session || $session->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
            try {
                $file=$request->files->get('file');
                if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) throw new \DomainException('Choisissez un document.');
                $token=$imports->prepare($file,$entite->getId(),$session->getId(),$this->getUser()->getId());
                return $this->redirectToRoute('app_admin_attendance_import_review',['entite'=>$entite->getId(),'token'=>$token]);
            } catch (\DomainException $e) { $this->addFlash('warning',$e->getMessage()); }
        }
        $history=$em->getRepository(AuditLog::class)->findBy(['entite'=>$entite,'event'=>'ATTENDANCE_PAPER_IMPORTED'],['id'=>'DESC'],30);
        return $this->render('administrateur/attendance_import/index.html.twig',compact('entite','sessions','history'));
    }

    #[Route('/{token}', name:'review', requirements:['token'=>'[a-f0-9]{32}'], methods:['GET','POST'])]
    public function review(Entite $entite, string $token, Request $request, EntityManagerInterface $em, PaperImport $imports, AssiduiteCalculator $calculator): Response
    {
        try { $manifest=$imports->read($entite->getId(),$token); } catch (\DomainException) { throw $this->createNotFoundException(); }
        $session=$em->getRepository(Session::class)->find($manifest['session']);
        if (!$session || $session->getEntite()?->getId()!==$entite->getId()) throw $this->createNotFoundException();
        $rows=[]; $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        foreach ($session->getInscriptions() as $ins) {
            if ($ins->getEntite()?->getId()!==$entite->getId() || !$ins->getStagiaire() || in_array($ins->getStatus(),[StatusInscription::ANNULE,StatusInscription::ABSENT],true)) continue;
            $online=$calculator->online($ins);
            foreach ($calculator->periods($ins) as $key=>$period) {
                if ($period['date']>$today || isset($online[$key]) || in_array($ins->getPresencesManuelles()[$key]['status'] ?? '',['paper','absent'],true)) continue;
                $rows[$ins->getId().'|'.$key]=['ins'=>$ins,'key'=>$key,'period'=>$period];
            }
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('attendance_import_'.$token,$request->request->get('_token'))) throw $this->createAccessDeniedException();
            $lock=fopen($imports->directory($entite->getId(),$token).'/lock','c');
            flock($lock,LOCK_EX);
            try {
                $submitted=json_decode($request->request->get('selections','[]'),true,flags:JSON_THROW_ON_ERROR);
                if (!$request->request->getBoolean('confirm') || !is_array($submitted) || !$submitted || count($submitted)>100) throw new \DomainException('Vérifiez et confirmez entre 1 et 100 signatures.');
                $prepared=[]; $zones=[];
                foreach ($submitted as $item) {
                    if (!is_array($item) || !isset($rows[$item['target'] ?? '']) || isset($prepared[$item['target']])) throw new \DomainException('Une correspondance est invalide ou déjà régularisée. Rechargez la page.');
                    $row=$rows[$item['target']];
                    $page=(int)($item['page'] ?? 0); $rect=$item['rect'] ?? [];
                    $zone=$page.':'.json_encode($rect);
                    if (isset($zones[$zone])) throw new \DomainException('Une même signature ne peut pas être attribuée à plusieurs créneaux.');
                    $zones[$zone]=true;
                    $image=$imports->crop($entite->getId(),$token,$page,is_array($rect)?$rect:[]);
                    $prepared[$item['target']]=compact('row','page','rect','image');
                }
                $em->wrapInTransaction(function () use ($prepared,$session,$entite,$em,$token,$manifest,$calculator) {
                    $evidence=[];
                    // Serialize imports for a session; recheck signatures within the transaction.
                    if (!$em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) $em->lock($session,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    foreach ($prepared as $target=>$entry) {
                        $ins=$entry['row']['ins']; $key=$entry['row']['key'];
                        $em->refresh($ins);
                        if (isset($calculator->online($ins)[$key]) || in_array($ins->getPresencesManuelles()[$key]['status'] ?? '',['paper','absent'],true)) throw new \DomainException('Un créneau vient d’être régularisé. Rechargez la page.');
                        [$date,$half]=explode(':',$key);
                        $signature=$em->getRepository(Emargement::class)->findOneBy(['session'=>$session,'entite'=>$entite,'utilisateur'=>$ins->getStagiaire(),'dateJour'=>new \DateTimeImmutable($date),'periode'=>DemiJournee::from($half)]);
                        if ($signature) {
                            $em->refresh($signature);
                            if ($signature->getSignedAt() || $signature->getSignatureDataUrl() || $signature->getSignaturePath()) throw new \DomainException('Une signature existe déjà sur ce créneau. Rechargez la page.');
                        }
                        if (!$signature) {
                            $signature=(new Emargement())->setSession($session)->setEntite($entite)->setCreateur($this->getUser())->setUtilisateur($ins->getStagiaire())->setRole('stagiaire')->setDateJour(new \DateTimeImmutable($date))->setPeriode(DemiJournee::from($half));
                            $em->persist($signature);
                        }
                        // Authentic crop only: no invented electronic signature time, IP or user agent.
                        $signature->setSignatureDataUrl($entry['image']);
                        $ins->enregistrerPresenceManuelle($key,'paper','Import papier '.$token.' · page '.$entry['page'],$this->getUser());
                        $calculator->computeForInscription($ins);
                        $evidence[]=['target'=>$target,'page'=>$entry['page'],'rect'=>$entry['rect']];
                    }
                    $em->persist((new AuditLog())->setEntite($entite)->setCreateur($this->getUser())->setActor($this->getUser())->setEvent('ATTENDANCE_PAPER_IMPORTED')->setCreatedAt(new \DateTimeImmutable())->setPayload(['token'=>$token,'session'=>$session->getId(),'name'=>$manifest['name'],'sha256'=>$manifest['sha256'],'signatures'=>$evidence]));
                });
                $this->addFlash('success',count($prepared).' émargement(s) papier régularisé(s). Le document original et les correspondances sont conservés.');
                return $this->redirectToRoute('app_administrateur_dashboard_index',['entite'=>$entite->getId()]);
            } catch (\DomainException|\JsonException $e) { $this->addFlash('warning',$e instanceof \JsonException?'Sélection invalide.':$e->getMessage()); }
            finally { flock($lock,LOCK_UN); fclose($lock); }
        }
        return $this->render('administrateur/attendance_import/review.html.twig',compact('entite','session','manifest','token','rows'));
    }

    #[Route('/{token}/document/{page}', name:'document', requirements:['token'=>'[a-f0-9]{32}','page'=>'\d+'], defaults:['page'=>0], methods:['GET'])]
    public function document(Entite $entite, string $token, int $page, PaperImport $imports, EntityManagerInterface $em): Response
    {
        try { $data=$imports->read($entite->getId(),$token); } catch (\DomainException) { throw $this->createNotFoundException(); }
        $session=$em->getRepository(Session::class)->find($data['session']);
        if (!$session || $session->getEntite()?->getId()!==$entite->getId() || $page>$data['pages']) throw $this->createNotFoundException();
        $response=new BinaryFileResponse($imports->directory($entite->getId(),$token).($page?'/view-'.$page.'.png':'/original.'.$data['extension']));
        $response->headers->set('Content-Type',$page?'image/png':($data['extension']==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $response->headers->set('Cache-Control','private, no-store'); $response->headers->set('X-Content-Type-Options','nosniff');
        if (!$page) $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,'emargements.'.$data['extension']);
        return $response;
    }
}
