<?php
namespace App\Controller\Marketplace;
use App\Entity\Marketplace\{Settings,Purchase,Offer};
use App\Service\Marketplace\Policy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/super/marketplace',name:'app_market_admin_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdminController extends AbstractController {
 #[Route('',name:'index',methods:['GET','POST'])]
 public function index(Request $r,EntityManagerInterface $em,Policy $policy): Response {
  $settings=$policy->settings();
  if ($r->isMethod('POST')) {
   if (!$this->isCsrfTokenValid('market-settings',$r->request->getString('_token'))) throw $this->createAccessDeniedException();
   $rate=str_replace(',','.',$r->request->getString('commission'));$fees=$r->request->getString('fees');
   if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/D',$rate) || (float)$rate>100 || !in_array($fees,['seller','platform'],true)) $this->addFlash('danger','Le taux doit être compris entre 0 et 100 %.');
   else {
    $oldRate=$settings->commissionBps;$oldFees=$settings->feesPayer;
    $settings->commissionBps=(int)round((float)$rate*100);$settings->feesPayer=$fees;$settings->legalApproved=$r->request->getBoolean('legal');
    $terms=trim($r->request->getString('terms'));$privacy=trim($r->request->getString('privacy'));
    if ($oldRate!==$settings->commissionBps || $oldFees!==$fees || $settings->terms!==$terms || $settings->privacy!==$privacy) $settings->termsVersion=(new \DateTimeImmutable())->format('YmdHis.u');
    $settings->terms=$terms;$settings->privacy=$privacy;
    $settings->enabled=$r->request->getBoolean('enabled') && $settings->legalApproved && mb_strlen($terms)>100 && mb_strlen($privacy)>100;
    $em->persist($settings);$em->flush();$this->addFlash('success',$settings->enabled?'Ventes activées.':'Réglages enregistrés. Ventes désactivées tant que les conditions de lancement ne sont pas remplies.');
   }
  }
  $totals=['gross'=>0,'commission'=>0,'fees'=>0,'transferred'=>0,'refunds'=>0,'ownSalesHt'=>0];
  foreach ($em->getRepository(Purchase::class)->findAll() as $p) {
   if (!$p->paidAt) continue;
   $totals['ownSalesHt']+=$p->platformRevenueHtCents();
   $totals['gross']+=$p->totalCents;$totals['refunds']+=$p->refundedCents;$totals['fees']+=$p->stripeFeeCents;$totals['transferred']+=$p->transferredCents;
   if ($p->status!=='disputed') $totals['commission']+=max(0,$p->commissionCents-(int)ceil($p->commissionCents*$p->refundedCents/max(1,$p->totalCents)));
  }
  $sales=$em->getRepository(Purchase::class)->findBy([],['createdAt'=>'DESC'],100);$offers=$em->getRepository(Offer::class)->findBy(['published'=>true]);
  return $this->render('marketplace/admin.html.twig',compact('settings','totals','sales','offers'));
 }
 #[Route('/studios',name:'studios',methods:['GET'])]
 public function studios(EntityManagerInterface $em): Response {
  return $this->render('marketplace/studio_spaces.html.twig',['entities'=>$em->getRepository(\App\Entity\Entite::class)->findBy([],['nom'=>'ASC'])]);
 }
 #[Route('/offre/{id}/retirer',name:'unpublish',methods:['POST'])]
 public function unpublish(Offer $offer,Request $r,EntityManagerInterface $em): Response {
  if (!$this->isCsrfTokenValid('moderate_'.$offer->id,$r->request->getString('_token'))) throw $this->createAccessDeniedException();
  $offer->published=false;$em->flush();$this->addFlash('success','Offre retirée du catalogue. Les droits acquis sont conservés.');return $this->redirectToRoute('app_market_admin_index');
 }
}
