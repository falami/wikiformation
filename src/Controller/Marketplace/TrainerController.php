<?php
namespace App\Controller\Marketplace;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Offer,Access,Purchase};
use App\Form\Administrateur\ElearningCourseType;
use App\Service\Marketplace\{Policy,Snapshots,Payments,BillingRules,SellerPaymentPolicy};
use App\Service\Slug\UniqueSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/formateur/{entite}/studio',name:'app_market_trainer_')]
#[IsGranted('ROLE_USER')]
final class TrainerController extends AbstractController {
 public function __construct(private Policy $policy,private EntityManagerInterface $em,private Snapshots $snapshots) {}
 #[Route('',name:'index',methods:['GET'])]
 public function index(Entite $entite): Response {
  $this->policy->trainer($this->getUser(),$entite);
  return $this->render('marketplace/studio.html.twig',['entite'=>$entite,'courses'=>$this->em->getRepository(ElearningCourse::class)->findBy(['entite'=>$entite,'createur'=>$this->getUser()],['id'=>'DESC']),'seller'=>$this->policy->seller($this->getUser()),'platformSeller'=>$this->policy->platformSeller($this->getUser()),'settings'=>$this->policy->settings()]);
 }
 #[Route('/cours/nouveau',name:'new',methods:['GET','POST'])]
 #[Route('/cours/{id}/modifier',name:'edit',methods:['GET','POST'],requirements:['id'=>'\d+'])]
 public function edit(Entite $entite,Request $r,UniqueSlugger $slugger,?ElearningCourse $course=null): Response {
  $this->policy->trainer($this->getUser(),$entite);
  if ($course) $this->policy->owns($this->getUser(),$entite,$course);
  $course ??= (new ElearningCourse())->setCreateur($this->getUser())->setEntite($entite)->setIsPublic(false)->setIsPublished(false);
  $form=$this->createForm(ElearningCourseType::class,$course); $form->remove('prixCents')->remove('isPublic'); $form->handleRequest($r);
  if ($form->isSubmitted() && $form->isValid()) {
   $course->setSlug($slugger->uniqueElearningCourseSlug($entite->getId(),$course->getTitre(),$course->getId()));
   $this->em->persist($course);$this->em->flush();
   return $this->redirectToRoute('app_administrateur_elearning_content_index',['entite'=>$entite->getId(),'course'=>$course->getId()]);
  }
  return $this->render('marketplace/form.html.twig',['entite'=>$entite,'title'=>'Préparez votre prochain cours','form'=>$form->createView()]);
 }
 #[Route('/cours/{id}/diffuser',name:'offer',methods:['GET','POST'])]
 public function offer(Entite $entite,ElearningCourse $course,Request $r): Response {
  $this->policy->owns($this->getUser(),$entite,$course);
  $seller=$this->policy->seller($this->getUser());$settings=$this->policy->settings();$platformSeller=$this->policy->platformSeller($this->getUser());
  $offer=$this->em->getRepository(Offer::class)->findOneBy(['course'=>$course]) ?? new Offer();
  if ($r->isMethod('POST')) {
   $this->denyCsrf($r,'offer_'.$course->getId());
   try {
    $price=trim($r->request->getString('price'));$tax=$r->request->getInt('tax');
    if (!preg_match('/^\d{1,5}([.,]\d{1,2})?$/D',$price) || !in_array($tax,[0,550,1000,2000],true)) throw new \DomainException('Vérifiez le prix TTC et le taux de TVA.');
    $cents=(int)round((float)str_replace(',','.',$price)*100);
    $published=$r->request->getBoolean('published');
    if ($published && ($cents<50 || !$seller->termsAcceptedAt || $seller->termsVersion!==$settings->termsVersion || !$r->request->getBoolean('preserve'))) throw new \DomainException('Complétez votre profil vendeur et confirmez le maintien des accès achetés. Prix minimum : 0,50 €.');
    if ($published && !$platformSeller) SellerPaymentPolicy::assertAccepted($this->em,$seller,$settings);
    if ($published && !$r->request->getBoolean('members') && !$r->request->getBoolean('public')) throw new \DomainException('Choisissez au moins une visibilité.');
    if ($published && $tax===0 && !$seller->taxExemption) throw new \DomainException('Renseignez le motif d’exonération de TVA dans votre profil.');
    $snapshot=$published ? $this->snapshots->capture($course) : $offer->snapshot;
    $offer->course=$course;$offer->seller=$seller;$offer->priceCents=$cents;$offer->taxBps=$tax;$offer->membersVisible=$r->request->getBoolean('members');$offer->publicVisible=$r->request->getBoolean('public');$offer->published=$published;$offer->snapshot=$snapshot;
    if ($published) $offer->publishedAt=new \DateTimeImmutable();
    $this->em->persist($offer);$this->em->flush();$this->addFlash('success','Diffusion enregistrée. Les achats existants conservent leur version.');
   } catch (\DomainException $e) { $this->addFlash('danger',$e->getMessage()); }
  }
  return $this->render('marketplace/offer.html.twig',compact('entite','course','offer','seller','settings','platformSeller'));
 }
 #[Route('/cours/{id}/affecter',name:'assign',methods:['GET','POST'])]
 public function assign(Entite $entite,ElearningCourse $course,Request $r): Response {
  $this->policy->owns($this->getUser(),$entite,$course);$learners=$this->policy->learners($entite);
  if ($r->isMethod('POST')) {
   $this->denyCsrf($r,'assign_'.$course->getId());
   $allowed=[];foreach ($learners as $m) $allowed[$m->getUtilisateur()->getId()]=$m->getUtilisateur();
   $ids=$r->request->all('learners');
   if (!$ids || count($ids)>1000 || array_diff($ids,array_keys($allowed))) throw $this->createAccessDeniedException('Sélection de stagiaires invalide.');
   try {
    $snapshot=$this->snapshots->capture($course,false);$count=0;
    foreach (array_unique($ids) as $id) {
     if ($this->em->getRepository(Access::class)->findOneBy(['buyer'=>$allowed[$id],'course'=>$course])) continue;
     $access=new Access();$access->buyer=$allowed[$id];$access->course=$course;$access->entite=$entite;$access->assignedBy=$this->getUser();$access->snapshot=$snapshot;$access->createdAt=new \DateTimeImmutable();$this->em->persist($access);++$count;
    }
    $this->em->flush();$this->addFlash('success',$count.' accès gratuit(s) ajouté(s).');
   } catch (\DomainException $e) { $this->addFlash('danger',$e->getMessage()); }
  }
  $sessions=[];$learnerSessions=[];$owned=[];
  foreach ($this->em->getRepository(Access::class)->findBy(['course'=>$course]) as $access) $owned[$access->buyer->getId()]=true;
  foreach ($this->em->getRepository(\App\Entity\Session::class)->findBy(['entite'=>$entite],['id'=>'DESC']) as $session) {
   if (!$session->hasFormateurUtilisateur($this->getUser()) || $session->getStatus()===\App\Enum\StatusSession::CANCELED) continue;
   $sessions[]=$session;
   foreach ($session->getInscriptions() as $inscription) {
    if (!$inscription->getStagiaire() || in_array($inscription->getStatus(),[\App\Enum\StatusInscription::ANNULE,\App\Enum\StatusInscription::ABSENT],true)) continue;
    $learnerSessions[$inscription->getStagiaire()->getId()][]=$session->getId();
   }
  }
  return $this->render('marketplace/assign.html.twig',compact('entite','course','learners','sessions','learnerSessions','owned'));
 }
 #[Route('/vendeur',name:'seller',methods:['GET','POST'])]
 public function seller(Entite $entite,Request $r): Response {
  $this->policy->trainer($this->getUser(),$entite);$seller=$this->policy->seller($this->getUser());$settings=$this->policy->settings();$platformSeller=$this->policy->platformSeller($this->getUser());
  if ($r->isMethod('POST')) {
   $this->denyCsrf($r,'seller');
   $data=[];foreach (['legalName','registration','address','email','taxNumber','taxExemption','country','postalCode'] as $key) $data[$key]=trim($r->request->getString($key));
   if (!$data['legalName'] || !$data['registration'] || !$data['address'] || !filter_var($data['email'],FILTER_VALIDATE_EMAIL) || max(array_map('mb_strlen',$data))>255 || mb_strlen($data['registration'])>60 || mb_strlen($data['taxNumber'])>80 || !$r->request->getBoolean('accept')) $this->addFlash('danger','Complétez les coordonnées professionnelles et acceptez les conditions vendeur.');
   else {
    try {
     $candidate=clone $seller;foreach ($data as $key=>$value) $candidate->$key=$value;
     $identity=BillingRules::seller($candidate);
     if (!$platformSeller && !$r->request->getBoolean('mandate')) throw new \DomainException('Acceptez le mandat pour activer la facturation automatique.');
     if (!$platformSeller && !hash_equals(SellerPaymentPolicy::snapshot($settings)['sha256'],$r->request->getString('payment_policy_digest'))) throw new \DomainException('Les conditions de frais de paiement ont changé. Relisez-les puis confirmez votre accord.');
     foreach ($data as $key=>$value) $seller->$key=$value;
     $seller->termsAcceptedAt=new \DateTimeImmutable();$seller->termsVersion=$settings->termsVersion;
     if (!$platformSeller) $seller->mandates[]=['version'=>BillingRules::VERSION,'text'=>BillingRules::mandateText(),'acceptedAt'=>$seller->termsAcceptedAt->format(DATE_ATOM),'user'=>$this->getUser()->getId(),'seller'=>$identity,'revokedAt'=>null];
     if (!$platformSeller) $this->em->persist(SellerPaymentPolicy::acceptance($seller,$settings,$entite));
     $this->em->flush();$this->addFlash('success','Profil et accord de facturation enregistrés.');
    } catch (\DomainException $e) { $this->addFlash('danger',$e->getMessage()); }
   }
  }
  $sales=$this->em->getRepository(Purchase::class)->findBy(['seller'=>$seller],['createdAt'=>'DESC'],100);
  return $this->render('marketplace/seller.html.twig',compact('entite','seller','settings','sales','platformSeller')+['mandateText'=>BillingRules::mandateText(),'sellerPaymentPolicy'=>SellerPaymentPolicy::snapshot($settings)]);
 }
 #[Route('/vendeur/revoquer-mandat',name:'revoke_mandate',methods:['POST'])]
 public function revokeMandate(Entite $entite,Request $r): Response {
  $this->policy->trainer($this->getUser(),$entite);$this->denyCsrf($r,'revoke_mandate');
  $seller=$this->policy->seller($this->getUser());
  if ($seller->mandates) $seller->mandates[array_key_last($seller->mandates)]['revokedAt']=(new \DateTimeImmutable())->format(DATE_ATOM);
  foreach ($this->em->getRepository(Offer::class)->findBy(['seller'=>$seller]) as $offer) $offer->published=false;
  $this->em->flush();$this->addFlash('success','Mandat révoqué et offres retirées. Les commandes déjà conclues restent traitées.');
  return $this->redirectToRoute('app_market_trainer_seller',['entite'=>$entite->getId()]);
 }
 #[Route('/stripe',name:'connect',methods:['POST'])]
 public function connect(Entite $entite,Request $r,Payments $payments): Response {
  $this->policy->trainer($this->getUser(),$entite);$this->denyCsrf($r,'connect');
  try { return $this->redirect($payments->onboard($this->policy->seller($this->getUser()),$entite)); }
  catch (\Throwable $e) { $this->addFlash('danger','La connexion Stripe est indisponible. Réessayez ou contactez la plateforme.');return $this->redirectToRoute('app_market_trainer_seller',['entite'=>$entite->getId()]); }
 }
 private function denyCsrf(Request $r,string $id): void { if (!$this->isCsrfTokenValid($id,$r->request->getString('_token'))) throw $this->createAccessDeniedException('Rechargez la page.'); }
}
