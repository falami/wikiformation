<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{Entite,Utilisateur};
use App\Service\Notification\NotificationCenter;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications/{entite}', name:'app_notifications_', requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::ACCESS, subject:'entite')]
final class NotificationController extends AbstractController
{
    #[Route('', name:'index', methods:['GET'])]
    public function index(Entite $entite,Request $request,NotificationCenter $center): Response
    {
        $spaces=$center->spaces($entite); $space=$request->query->getString('space',array_key_first($spaces) ?? '');
        if (!isset($spaces[$space])) throw $this->createAccessDeniedException();
        return $this->render('notification/index.html.twig',['entite'=>$entite,'spaces'=>$spaces,'space'=>$space]);
    }
    #[Route('/feed', name:'feed', methods:['GET'])]
    public function feed(Entite $entite,Request $request,NotificationCenter $center): JsonResponse
    {
        $spaces=$center->spaces($entite); $space=$request->query->getString('space',array_key_first($spaces) ?? '');
        /** @var Utilisateur $user */ $user=$this->getUser();
        $items=$center->items($entite,$user,$space);
        $counts=['unread'=>0,'action'=>0,'info'=>0,'archived'=>0];
        foreach ($items as $i) { if ($i['archived']) { ++$counts['archived']; continue; } ++$counts[$i['state']]; if (!$i['read']) ++$counts['unread']; }
        $state=$request->query->getString('state','all'); $category=$request->query->getString('category'); $search=mb_strtolower(trim($request->query->getString('q')));
        $filtered=array_values(array_filter($items,static fn($i)=>($state==='archived'?$i['archived']:!$i['archived']) && ($state!=='unread'||!$i['read']) && (!in_array($state,['action','info'],true)||$i['state']===$state) && (!$category||$i['category']===$category) && (!$search||str_contains(mb_strtolower($i['title'].' '.$i['detail']),$search))));
        $page=max(1,$request->query->getInt('page',1)); $limit=min(50,max(1,$request->query->getInt('limit',25)));
        $response=$this->json(['items'=>array_slice($filtered,($page-1)*$limit,$limit),'total'=>count($filtered),'counts'=>$counts,'page'=>$page,'pages'=>(int)ceil(count($filtered)/$limit),'space'=>$space]);
        $response->headers->set('Cache-Control','private, no-store'); return $response;
    }
    #[Route('/state', name:'state', methods:['POST'])]
    public function state(Entite $entite,Request $request,NotificationCenter $center,EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('notifications_'.$entite->getId(),$request->headers->get('X-CSRF-Token'))) return $this->json(['error'=>'Session expirée. Rechargez la page.'],403);
        $data=$request->toArray(); $action=$data['action']??'';
        if (!in_array($action,['read','unread','archive','restore','read_all'],true)) return $this->json(['error'=>'Action invalide'],400);
        /** @var Utilisateur $user */ $user=$this->getUser();
        $items=$center->items($entite,$user,(string)($data['space']??''));
        $requested=$data['keys'] ?? null;
        if ($requested!==null && (!in_array($action,['read','archive'],true) || !is_array($requested) || !$requested || count($requested)>50 || count(array_filter($requested,'is_string'))!==count($requested))) return $this->json(['error'=>'Sélection invalide'],400);
        $available=array_column($items,'key');
        $keys=$requested!==null ? array_values(array_unique($requested)) : ($action==='read_all'?$available:[$data['key']??'']);
        if (array_diff($keys,$available)) return $this->json(['error'=>'Notification indisponible'],404);
        $connection=$em->getConnection();
        $connection->transactional(function($db) use($keys,$action,$entite,$user) {
            foreach($keys as $key) {
                $field=in_array($action,['archive','restore'],true)?'archived':'read_at';
                $value=match($action) {'archive'=>1,'restore'=>0,'unread'=>null,default=>(new \DateTimeImmutable())->format('Y-m-d H:i:s')};
                $update=$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
                    ? ' ON CONFLICT (entite_id, utilisateur_id, item_key) DO UPDATE SET '.$field.' = excluded.'.$field
                    : ' ON DUPLICATE KEY UPDATE '.$field.' = VALUES('.$field.')';
                $db->executeStatement('INSERT INTO notification_receipt (entite_id, utilisateur_id, item_key, '.$field.') VALUES (?, ?, ?, ?)'.$update,[$entite->getId(),$user->getId(),$key,$value]);
            }
        });
        return $this->json(['ok'=>true]);
    }
}
