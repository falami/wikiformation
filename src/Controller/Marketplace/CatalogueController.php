<?php
namespace App\Controller\Marketplace;
use App\Entity\Marketplace\{Offer,Access,Purchase};
use App\Service\Marketplace\{Policy,Payments,Snapshots};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,BinaryFileResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/cours-en-ligne',name:'app_market_')]
final class CatalogueController extends AbstractController {
 public function __construct(private EntityManagerInterface $em,private Policy $policy) {}
 #[Route('',name:'catalogue',methods:['GET'])]
 public function catalogue(Request $r): Response {
  $qb=$this->em->createQueryBuilder()->select('o','c','s')->from(Offer::class,'o')->join('o.course','c')->join('o.seller','s')->where('o.published = true');
  $qb->andWhere($this->getUser()?'(o.membersVisible = true OR o.publicVisible = true)':'o.publicVisible = true');
  $q=mb_substr(trim($r->query->getString('q')),0,100);if ($q) $qb->andWhere('LOWER(c.titre) LIKE :q')->setParameter('q','%'.mb_strtolower($q).'%');
  $page=max(1,$r->query->getInt('page',1));$offers=$qb->orderBy('o.publishedAt','DESC')->setFirstResult(($page-1)*24)->setMaxResults(25)->getQuery()->getResult();
  return $this->render('marketplace/catalogue.html.twig',['offers'=>array_slice($offers,0,24),'next'=>count($offers)>24,'page'=>$page,'q'=>$q,'settings'=>$this->policy->settings()]);
 }
 #[Route('/cours/{id}',name:'show',methods:['GET'])]
 public function show(Offer $offer): Response {
  $this->visible($offer);return $this->render('marketplace/show.html.twig',['offer'=>$offer,'settings'=>$this->policy->settings()]);
 }
 #[Route('/panier/ajouter/{id}',name:'cart_add',methods:['POST'])]
 #[IsGranted('ROLE_USER')]
 public function add(Offer $offer,Request $r): Response {
  $this->visible($offer);$this->csrf($r,'cart');
  $cart=$r->getSession()->get('market_cart',[]);$cart[]=$offer->id;$cart=array_slice(array_values(array_unique($cart)),0,30);$r->getSession()->set('market_cart',$cart);
  return $this->redirectToRoute('app_market_cart');
 }
 #[Route('/panier/retirer/{id}',name:'cart_remove',methods:['POST'])]
 #[IsGranted('ROLE_USER')]
 public function remove(int $id,Request $r): Response {
  $this->csrf($r,'cart');$r->getSession()->set('market_cart',array_values(array_diff($r->getSession()->get('market_cart',[]),[$id])));return $this->redirectToRoute('app_market_cart');
 }
 #[Route('/panier',name:'cart',methods:['GET'])]
 #[IsGranted('ROLE_USER')]
 public function cart(Request $r): Response {
  $groups=[];$availableIds=[];foreach ($r->getSession()->get('market_cart',[]) as $id) {
   $offer=$this->em->find(Offer::class,$id);if (!$offer || !$offer->published || (!$offer->publicVisible && !$offer->membersVisible)) continue;
   if ($this->em->getRepository(Access::class)->findOneBy(['buyer'=>$this->getUser(),'course'=>$offer->course])) continue;
   $availableIds[]=$id;$sid=$offer->seller->id;$groups[$sid]['seller']=$offer->seller;$groups[$sid]['offers'][]=$offer;$groups[$sid]['total']=($groups[$sid]['total']??0)+$offer->priceCents;
  }
  $r->getSession()->set('market_cart',$availableIds);
  return $this->render('marketplace/cart.html.twig',['groups'=>$groups,'settings'=>$this->policy->settings()]);
 }
 #[Route('/payer/{seller}',name:'checkout',methods:['POST'])]
 #[IsGranted('ROLE_USER')]
 public function checkout(int $seller,Request $r,Payments $payments): Response {
  $this->csrf($r,'checkout');
  if ($r->request->getString('terms_version')!==$this->policy->settings()->termsVersion || !$r->request->getBoolean('terms') || !$r->request->getBoolean('immediate') || !$r->request->getBoolean('withdrawal')) { $this->addFlash('danger','Confirmez les conditions et votre choix d’accès immédiat.');return $this->redirectToRoute('app_market_cart'); }
  $ids=[];foreach ($r->getSession()->get('market_cart',[]) as $id) { $offer=$this->em->find(Offer::class,$id);if ($offer && $offer->seller->id===$seller) $ids[]=$id; }
  try { return $this->redirect($payments->checkout($this->getUser(),$ids,['billing'=>$r->request->all('billing'),'termsAccepted'=>true,'immediateAccess'=>true,'withdrawalAcknowledged'=>true])); }
  catch (\DomainException $e) { $this->addFlash('danger',$e->getMessage()); }
  catch (\Throwable $e) { $this->addFlash('danger','Le paiement est momentanément indisponible. Aucun accès ne sera activé sans confirmation du paiement.'); }
  return $this->redirectToRoute('app_market_cart');
 }
 #[Route('/bibliotheque',name:'library',methods:['GET'])]
 #[IsGranted('ROLE_USER')]
 public function library(): Response {
  return $this->render('marketplace/library.html.twig',['accesses'=>$this->em->getRepository(Access::class)->findBy(['buyer'=>$this->getUser()],['createdAt'=>'DESC']),'purchases'=>$this->em->getRepository(Purchase::class)->findBy(['buyer'=>$this->getUser()],['createdAt'=>'DESC'],100)]);
 }
 #[Route('/achat/{id}',name:'purchase',methods:['GET'])]
 #[IsGranted('ROLE_USER')]
 public function purchase(Purchase $purchase,Payments $payments): Response {
  if ($purchase->buyer!==$this->getUser()) throw $this->createNotFoundException();
  try { $payments->sync($purchase); } catch (\Throwable $e) { $this->addFlash('warning','La confirmation du paiement est en cours. Vous pouvez revenir sur cette page.'); }
  $response=$this->render('marketplace/purchase.html.twig',['purchase'=>$purchase,'invoices'=>$this->em->getRepository(\App\Entity\Marketplace\Invoice::class)->findBy(['purchase'=>$purchase,'kind'=>['sale','sale_credit']],['id'=>'ASC'])]);$response->headers->set('Cache-Control','private, no-store');return $response;
 }
 #[Route('/achat/{id}/reprendre',name:'resume',methods:['POST'])]
 #[IsGranted('ROLE_USER')]
 public function resume(Purchase $purchase,Request $r,Payments $payments): Response {
  if ($purchase->buyer!==$this->getUser()) throw $this->createNotFoundException();
  $this->csrf($r,'resume_'.$purchase->id);
  try { return $this->redirect($payments->resume($purchase)); } catch (\Throwable $e) { $this->addFlash('warning','Le paiement ne peut pas être repris. Vérifiez son état dans vos achats.');return $this->redirectToRoute('app_market_purchase',['id'=>$purchase->id]); }
 }
 #[Route('/apprendre/{id}',name:'learn',methods:['GET','POST'])]
 #[IsGranted('ROLE_USER')]
 public function learn(Access $access,Request $r): Response {
  $this->access($access);
  if ($r->isMethod('POST')) {
   $this->csrf($r,'progress_'.$access->id);$node=$r->request->getInt('node');
   if (!in_array($node,array_column($access->snapshot['nodes'],'id'),true)) throw $this->createNotFoundException();
   $access->completed=array_values(array_unique([...$access->completed,$node]));$this->em->flush();return $this->redirectToRoute('app_market_learn',['id'=>$access->id]);
  }
  $response=$this->render('marketplace/learn.html.twig',['access'=>$access]);$response->headers->set('Cache-Control','private, no-store');return $response;
 }
 #[Route('/media/{id}/{key}',name:'media',methods:['GET'],requirements:['key'=>'[a-f0-9]{64}'])]
 #[IsGranted('ROLE_USER')]
 public function media(Access $access,string $key,Snapshots $snapshots): Response {
  $this->access($access);$file=null;
  foreach ($access->snapshot['nodes'] as $node) foreach ($node['blocks'] as $block) if (($block['file']['key']??'')===$key) $file=$block['file'];
  if (!$file || !is_file($snapshots->media($key))) throw $this->createNotFoundException();
  $response=new BinaryFileResponse($snapshots->media($key));$response->headers->set('Content-Type',$file['mime']);$response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Content-Type-Options','nosniff');$response->setContentDisposition('inline',$file['name']);return $response;
 }
 #[Route('/mes-donnees',name:'data_export',methods:['GET'])]
 #[IsGranted('ROLE_USER')]
 public function dataExport(): Response {
  $user=$this->getUser();$data=['exportedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM),'email'=>$user->getEmail(),'purchases'=>[],'learning'=>[]];
  foreach ($this->em->getRepository(Purchase::class)->findBy(['buyer'=>$user]) as $p) $data['purchases'][]=['reference'=>$p->id,'createdAt'=>$p->createdAt?->format(DATE_ATOM),'status'=>$p->status,'totalCents'=>$p->totalCents,'refundedCents'=>$p->refundedCents,'consent'=>$p->consent,'courses'=>array_map(fn($i)=>['title'=>$i['title'],'gross'=>$i['gross']],$p->items)];
  foreach ($this->em->getRepository(Access::class)->findBy(['buyer'=>$user]) as $a) $data['learning'][]=['title'=>$a->snapshot['title'],'grantedAt'=>$a->createdAt?->format(DATE_ATOM),'completedChapters'=>$a->completed,'suspended'=>$a->suspended,'paid'=>$a->purchase!==null];
  $response=$this->json($data);$response->headers->set('Cache-Control','private, no-store');$response->headers->set('Content-Disposition','attachment; filename="mes-donnees-elearning.json"');return $response;
 }
 #[Route('/conditions' ,name:'terms',methods:['GET'])]
 public function terms(): Response { return $this->render('marketplace/terms.html.twig',['settings'=>$this->policy->settings()]); }
 private function visible(Offer $o): void { if (!$o->published || (!$o->publicVisible && !($this->getUser() && $o->membersVisible))) throw $this->createNotFoundException(); }
 private function access(Access $a): void { if ($a->buyer!==$this->getUser() || $a->suspended) throw $this->createNotFoundException(); }
 private function csrf(Request $r,string $id): void { if (!$this->isCsrfTokenValid($id,$r->request->getString('_token'))) throw $this->createAccessDeniedException('Rechargez la page.'); }
}
