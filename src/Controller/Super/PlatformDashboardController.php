<?php
declare(strict_types=1);
namespace App\Controller\Super;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Platform\Cost;
use App\Service\Platform\{Overview, StripeRevenue};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/super/{entite}/pilotage', name: 'app_super_platform_')]
final class PlatformDashboardController extends AbstractController
{
    #[Route('', name:'dashboard', methods:['GET'])]
    public function index(#[MapEntity(id:'entite')] Entite $entite, Request $request, Overview $overview): Response
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $filters=['from'=>$request->query->getString('from',$now->format('Y').'-01'),
            'to'=>$request->query->getString('to',$now->format('Y-m')),
            'entity'=>$request->query->getInt('entity'), 'activity'=>$request->query->getString('activity','all')];
        try { $data=$overview->build(...array_values($filters)); }
        catch (\InvalidArgumentException $e) { throw $this->createNotFoundException($e->getMessage()); }
        if ($request->query->getString('format')==='csv') {
            $response = new StreamedResponse(static function() use ($data): void {
                $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Date','Entité','Activité','Référence','HT EUR connu','TTC EUR net','Frais Stripe EUR connus','État'],';','"','');
                foreach ($data['rows'] as $r) {
                    $safe=static fn(string $v): string => preg_match('/^[=+@\-\t\r]/',$v) ? "'".$v : $v;
                    fputcsv($out,[$r['date']->format('Y-m-d'),$safe($r['name']),Overview::ACTIVITIES[$r['activity']],$safe($r['label']),
                        $r['ht']===null?'':number_format($r['ht']/100,2,',',''),number_format($r['ttc']/100,2,',',''),
                        $r['fees']===null?'':number_format($r['fees']/100,2,',',''),$r['state']],';','"','');
                }
                fclose($out);
            });
            $response->headers->set('Content-Type','text/csv; charset=UTF-8');
            $response->headers->set('Content-Disposition','attachment; filename="pilotage-plateforme.csv"');
        } else $response=$this->render('super/platform/dashboard.html.twig',compact('entite','data','filters')+['activities'=>Overview::ACTIVITIES,'categories'=>Overview::COSTS]);
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }

    #[Route('/synchroniser', name:'sync', methods:['POST'])]
    public function sync(#[MapEntity(id:'entite')] Entite $entite, Request $request, StripeRevenue $stripe): Response
    {
        if (!$this->isCsrfTokenValid('platform_sync',$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try { $this->addFlash('success',$stripe->sync().' factures d’abonnement rapprochées avec Stripe.'); }
        catch (\Throwable $e) { $this->addFlash('warning','Rapprochement incomplet. Vérifiez la connexion Stripe et relancez ; les éléments déjà importés sont conservés sans doublon.'); }
        return $this->redirectToRoute('app_super_platform_dashboard',['entite'=>$entite->getId()]);
    }

    #[Route('/cout', name:'cost', methods:['POST'])]
    public function cost(#[MapEntity(id:'entite')] Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('platform_cost',$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try {
            $p=$request->request; $label=trim($p->getString('label')); $reference=trim($p->getString('reference')); $category=$p->getString('category');
            if (!$label || mb_strlen($label)>255 || mb_strlen($reference)>255 || !isset(Overview::COSTS[$category])) throw new \InvalidArgumentException('Libellé, référence ou catégorie invalide.');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$p->getString('date'),new \DateTimeZone('Europe/Paris'));
            if (!$date || $date->format('Y-m-d')!==$p->getString('date')) throw new \InvalidArgumentException('Date invalide.');
            $cost=new Cost(); $cost->label=$label; $cost->reference=$reference; $cost->category=$category; $cost->date=$date;
            $cost->ht=self::cents($p->getString('ht')); $cost->ttc=self::cents($p->getString('ttc'));
            if ($cost->ttc<$cost->ht) throw new \InvalidArgumentException('Le TTC doit être supérieur ou égal au HT.');
            $id=$p->getInt('entity'); $cost->entite=$id ? $em->find(Entite::class,$id) : null;
            if ($id && !$cost->entite) throw new \InvalidArgumentException('Entité inconnue.');
            $cost->activity=$p->getString('activity','shared');
            if ($cost->activity !== 'shared' && (!isset(Overview::ACTIVITIES[$cost->activity]) || $cost->activity==='all')) throw new \InvalidArgumentException('Activité invalide.');
            $cost->estimated=$p->getBoolean('estimated'); $cost->author=$this->getUser();
            $em->persist($cost); $em->flush(); $this->addFlash('success','Coût enregistré.');
        } catch (\InvalidArgumentException $e) { $this->addFlash('danger',$e->getMessage()); }
        return $this->redirectToRoute('app_super_platform_dashboard',['entite'=>$entite->getId()]);
    }

    #[Route('/cout/{id}/archiver', name:'cost_archive', methods:['POST'], requirements:['id'=>'\d+'])]
    public function archive(#[MapEntity(id:'entite')] Entite $entite, #[MapEntity(id:'id')] Cost $cost, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('platform_archive_'.$cost->id,$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $cost->archived=true; $em->flush();
        $this->addFlash('success','Coût retiré des calculs et conservé dans l’historique.');
        return $this->redirectToRoute('app_super_platform_dashboard',['entite'=>$entite->getId()]);
    }

    public static function cents(string $value): int
    {
        $value=str_replace(',','.',trim($value));
        if (!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D',$value,$m)) throw new \InvalidArgumentException('Montant invalide (deux décimales maximum).');
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
}
