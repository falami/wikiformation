<?php
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Access,Purchase,Seller,Settings,Offer};
use App\Service\Marketplace\{Payments,Policy,StripeGateway};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class MarketplaceTest extends KernelTestCase {
 private $em; private array $env; private Utilisateur $buyer; private Seller $seller; private Offer $offer;
 protected function setUp(): void {
  $this->env=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];$_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
  self::bootKernel();$this->em=self::getContainer()->get('doctrine')->getManager();(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
  $this->buyer=(new Utilisateur())->setEmail('buyer@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Buyer');
  $trainer=(new Utilisateur())->setEmail('seller@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Seller');
  $entity=(new Entite())->setNom('Tenant A')->setPublic(false)->setCreateur($trainer);
  foreach([$this->buyer,$trainer,$entity] as $x)$this->em->persist($x);$this->em->flush();
  $this->buyer->setEntite($entity);$trainer->setEntite($entity);
  $course=(new ElearningCourse())->setEntite($entity)->setCreateur($trainer)->setTitre('Course')->setSlug('course');$this->em->persist($course);
  $this->seller=new Seller();$this->seller->user=$trainer;$this->seller->accountId='acct_test';$this->seller->termsAcceptedAt=new \DateTimeImmutable();$this->seller->termsVersion='2026-10-25';$this->em->persist($this->seller);
  $settings=new Settings();$settings->enabled=true;$settings->legalApproved=true;$settings->commissionBps=2000;$this->em->persist($settings);
  $this->offer=new Offer();$this->offer->seller=$this->seller;$this->offer->course=$course;$this->offer->published=true;$this->offer->membersVisible=true;$this->offer->priceCents=12000;$this->offer->taxBps=2000;$this->offer->snapshot=['title'=>'Original course','description'=>'Learn with a trainer','minutes'=>60,'capturedAt'=>'2026-10-25T10:00:00Z','nodes'=>[]];$this->em->persist($this->offer);$this->em->flush();
 }
 protected function tearDown(): void {parent::tearDown();foreach(['ENV','SERVER'] as $i=>$n){if($this->env[$i]===null)unset($GLOBALS['_'.$n]['DATABASE_URL']);else $GLOBALS['_'.$n]['DATABASE_URL']=$this->env[$i];}}
 private function payments(StripeGateway $stripe): Payments {return new Payments($this->em,$stripe,new Policy($this->em),self::getContainer()->get(UrlGeneratorInterface::class));}
 private function purchase(): Purchase {$p=new Purchase();$p->id=bin2hex(random_bytes(16));$p->buyer=$this->buyer;$p->seller=$this->seller;$p->createdAt=new \DateTimeImmutable('-20 days');$p->checkoutId='cs_test';$p->totalCents=12000;$p->commissionCents=2000;$p->items=[['course'=>$this->offer->course->getId(),'snapshot'=>$this->offer->snapshot]];$this->em->persist($p);$this->em->flush();return $p;}
 public function testMoneyAndDisabledSales(): void {
  self::assertSame(['net'=>10000,'tax'=>2000,'commission'=>2000],Payments::split(12000,2000,2000));
  $this->em->find(Settings::class,1)->enabled=false;$this->em->flush();$stripe=$this->createMock(StripeGateway::class);$stripe->expects(self::never())->method('checkout')->with(self::anything(),self::anything());
  $this->expectException(\DomainException::class);$this->payments($stripe)->checkout($this->buyer,[$this->offer->id],[]);
 }
 public function testReplaySnapshotFeesAndRefund(): void {
  $p=$this->purchase();$refund=0;$stripe=$this->createMock(StripeGateway::class);
  $stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>12000,'currency'=>'eur','payment_status'=>'paid','payment_intent'=>'pi_test']);
  $stripe->method('payment')->willReturnCallback(function() use(&$refund){return ['status'=>'succeeded','currency'=>'eur','amount_received'=>12000,'latest_charge'=>['id'=>'ch_test','created'=>time()-20*86400,'amount_refunded'=>$refund,'balance_transaction'=>['fee'=>210,'available_on'=>time()-86400]]];});
  $stripe->expects(self::once())->method('transfer')->with('acct_test',9790,'ch_test',self::anything())->willReturn('tr_test');
  $stripe->method('transferState')->willReturn(['amount'=>9790,'currency'=>'eur','destination'=>'acct_test','amount_reversed'=>0]);
  $stripe->expects(self::once())->method('reverse')->with('tr_test',9790,self::anything());
  $service=$this->payments($stripe);$service->settle($p);$service->settle($p);
  self::assertSame(9790,$p->sellerCents);self::assertSame(9790,$p->transferredCents);self::assertSame(1,$this->em->getRepository(Access::class)->count([]));
  $this->offer->snapshot=['title'=>'Changed'];$this->offer->published=false;$this->em->flush();$access=$this->em->getRepository(Access::class)->findOneBy(['buyer'=>$this->buyer]);self::assertSame('Original course',$access->snapshot['title']);
  $refund=12000;$service->settle($p);$service->settle($p);self::assertTrue($access->suspended);self::assertSame(0,$p->transferredCents);
 }
 public function testUnrecordedTransferIsRecoveredAfterFullRefundWithoutPayingTwice(): void {
  $p=$this->purchase();$stripe=$this->createMock(StripeGateway::class);
  $stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>12000,'currency'=>'eur','payment_status'=>'paid','payment_intent'=>'pi_test']);
  $stripe->method('payment')->willReturn(['status'=>'succeeded','currency'=>'eur','amount_received'=>12000,'latest_charge'=>['id'=>'ch_test','created'=>time()-20*86400,'amount_refunded'=>12000,'balance_transaction'=>['fee'=>210,'available_on'=>time()-86400]]]);
  $remote=['id'=>'tr_recovered','amount'=>9790,'currency'=>'eur','destination'=>'acct_test','source_transaction'=>'ch_test','amount_reversed'=>0];
  $stripe->method('findTransfer')->willReturnCallback(fn($key)=>str_ends_with($key,'-0')?$remote:null);
  $stripe->method('transferState')->willReturn($remote);
  $stripe->expects(self::never())->method('transfer');$stripe->expects(self::once())->method('reverse')->with('tr_recovered',9790,self::anything());
  $service=$this->payments($stripe);$service->settle($p);
  self::assertSame(0,$p->transferredCents);self::assertSame(9790,$p->payoutHistory[0]['reversed']);
 }
 public function testUnrecordedReversalIsRecoveredWithoutReversingTwice(): void {
  $p=$this->purchase();$p->transferredCents=9790;$p->payoutHistory=[['id'=>'tr_previous','amount'=>9790,'reversed'=>0]];$this->em->flush();
  $stripe=$this->createMock(StripeGateway::class);
  $stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>12000,'currency'=>'eur','payment_status'=>'paid','payment_intent'=>'pi_test']);
  $stripe->method('payment')->willReturn(['status'=>'succeeded','currency'=>'eur','amount_received'=>12000,'latest_charge'=>['id'=>'ch_test','created'=>time()-20*86400,'amount_refunded'=>12000,'balance_transaction'=>['fee'=>210,'available_on'=>time()-86400]]]);
  $stripe->method('transferState')->willReturn(['amount'=>9790,'currency'=>'eur','destination'=>'acct_test','amount_reversed'=>9790]);
  $stripe->expects(self::never())->method('transfer');$stripe->expects(self::never())->method('reverse');
  $this->payments($stripe)->settle($p);self::assertSame(0,$p->transferredCents);self::assertSame(9790,$p->payoutHistory[0]['reversed']);
 }
 public function testWonDisputeRestoresPaidAccess(): void {
  $p=$this->purchase();$state='under_review';$stripe=$this->createMock(StripeGateway::class);
  $stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>12000,'currency'=>'eur','payment_status'=>'paid','payment_intent'=>'pi_test']);
  $stripe->method('payment')->willReturnCallback(function() use(&$state){return ['status'=>'succeeded','currency'=>'eur','amount_received'=>12000,'latest_charge'=>['id'=>'ch_test','created'=>time(),'disputed'=>true,'_dispute_status'=>$state,'balance_transaction'=>['fee'=>210]]];});
  $service=$this->payments($stripe);$service->sync($p);self::assertTrue($this->em->getRepository(Access::class)->findOneBy([])->suspended);
  $state='won';$service->sync($p);self::assertSame('paid',$p->status);self::assertFalse($this->em->getRepository(Access::class)->findOneBy([])->suspended);
 }
 public function testTamperedPaymentNeverUnlocks(): void {
  $p=$this->purchase();$stripe=$this->createMock(StripeGateway::class);$stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>1,'currency'=>'eur']);
  try{$this->payments($stripe)->sync($p);self::fail('Expected mismatch');}catch(\DomainException){}self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_access'));
 }
 public function testTenantScopeAndPrivateCatalogue(): void {
  $policy=new Policy($this->em);$entity=$this->offer->course->getEntite();$trainer=$this->seller->user;
  $this->em->persist((new UtilisateurEntite())->setUtilisateur($trainer)->setEntite($entity)->setCreateur($trainer)->setRoles(['TENANT_FORMATEUR']));
  $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->buyer)->setEntite($entity)->setCreateur($trainer)->setRoles(['TENANT_STAGIAIRE']));$this->em->flush();
  $policy->owns($trainer,$entity,$this->offer->course);self::assertCount(1,$policy->learners($entity));
  $other=(new Entite())->setNom('Tenant B')->setPublic(false)->setCreateur($trainer);$this->em->persist($other);$this->em->flush();self::assertCount(0,$policy->learners($other));
  $client=self::getContainer()->get('test.client');$client->disableReboot();$client->request('GET','/cours-en-ligne');self::assertSame(200,$client->getResponse()->getStatusCode());self::assertStringNotContainsString('Original course',$client->getResponse()->getContent());
  try{$policy->owns($trainer,$other,$this->offer->course);self::fail('Cross tenant');}catch(\Symfony\Component\Security\Core\Exception\AccessDeniedException){}
 }
 public function testAllScreensAndPrivateAccess(): void {
  $trainer=$this->seller->user;$entity=$this->offer->course->getEntite();
  $this->em->persist((new UtilisateurEntite())->setUtilisateur($trainer)->setEntite($entity)->setCreateur($trainer)->setRoles(['TENANT_FORMATEUR']));
  $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entity)->setStatus('active'));$this->em->flush();
  $client=self::getContainer()->get('test.client');$client->disableReboot();$client->catchExceptions(false);$client->loginUser($trainer);
  $prefix='/formateur/'.$entity->getId().'/studio';
  foreach (['/fr/administrateur/'.$entity->getId().'/elearning/'.$this->offer->course->getId().'/contenu',$prefix,$prefix.'/cours/nouveau',$prefix.'/cours/'.$this->offer->course->getId().'/modifier',$prefix.'/cours/'.$this->offer->course->getId().'/diffuser',$prefix.'/cours/'.$this->offer->course->getId().'/affecter',$prefix.'/vendeur','/cours-en-ligne','/cours-en-ligne/cours/'.$this->offer->id,'/cours-en-ligne/panier','/cours-en-ligne/bibliotheque','/cours-en-ligne/conditions'] as $url) {
   $client->request('GET',$url);self::assertSame(200,$client->getResponse()->getStatusCode(),$url);
   if ($url==='/cours-en-ligne/bibliotheque') {
    $html=$client->getResponse()->getContent();
    self::assertStringContainsString('Mes espaces',$html);
    self::assertStringContainsString('Mon tableau de bord',$html);
    self::assertStringContainsString('Mon studio',$html);
    self::assertStringNotContainsString('Super administration',$html);
    self::assertStringNotContainsString('Mon administration',$html);
   }
   if (getenv('MARKET_PREVIEW') && $url===$prefix) file_put_contents(getenv('MARKET_PREVIEW'),$client->getResponse()->getContent());
  }
  $trainer=$this->em->find(Utilisateur::class,$trainer->getId());$trainer->setRoles(['ROLE_SUPER_ADMIN']);$this->em->flush();$client->loginUser($trainer);$client->request('GET','/super/marketplace');self::assertSame(200,$client->getResponse()->getStatusCode());
  self::assertStringContainsString('Mon administration',$client->getResponse()->getContent());
  self::assertStringContainsString('Super administration',$client->getResponse()->getContent());
 }

 public function testFreeAssignmentAndPrivateLibrary(): void {
  $trainer=$this->seller->user;$entity=$this->offer->course->getEntite();$course=$this->offer->course;
  foreach ([[$trainer,['TENANT_FORMATEUR']],[$this->buyer,['TENANT_STAGIAIRE']]] as [$u,$roles]) $this->em->persist((new UtilisateurEntite())->setUtilisateur($u)->setEntite($entity)->setCreateur($trainer)->setRoles($roles));
  $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entity)->setStatus('active'));
  $node=(new \App\Entity\Elearning\ElearningNode())->setEntite($entity)->setCreateur($trainer)->setCourse($course)->setTitre('Introduction')->setSlug('intro')->setIsPublished(true);
  $block=(new \App\Entity\Elearning\ElearningBlock())->setEntite($entity)->setCreateur($trainer)->setNode($node)->setContent('<p>Hello</p><script>alert(1)</script>');
  foreach([$node,$block] as $x)$this->em->persist($x);$this->em->flush();$buyerId=$this->buyer->getId();$trainerId=$trainer->getId();$this->em->clear();
  $client=self::getContainer()->get('test.client');$client->disableReboot();$client->catchExceptions(false);$client->loginUser($this->em->find(Utilisateur::class,$trainerId));
  $url='/formateur/'.$entity->getId().'/studio/cours/'.$course->getId().'/affecter';$crawler=$client->request('GET',$url);$token=$crawler->filter('input[name="_token"]')->attr('value');
  $client->request('POST',$url,['_token'=>$token,'learners'=>[$buyerId]]);self::assertSame(200,$client->getResponse()->getStatusCode());
  $access=$this->em->getRepository(Access::class)->findOneBy([]);self::assertNotNull($access);self::assertNull($access->purchase);self::assertStringNotContainsString('<script',$access->snapshot['nodes'][0]['blocks'][0]['html']);
  $client->catchExceptions(true);$client->request('POST',$url,['_token'=>$token,'learners'=>[$trainerId]]);self::assertSame(403,$client->getResponse()->getStatusCode());
  $client->request('GET','/cours-en-ligne/apprendre/'.$access->id);self::assertSame(404,$client->getResponse()->getStatusCode());
  $client->loginUser($this->em->find(Utilisateur::class,$buyerId));$client->request('GET','/cours-en-ligne/apprendre/'.$access->id);self::assertSame(200,$client->getResponse()->getStatusCode());self::assertStringContainsString('Hello',$client->getResponse()->getContent());
 }

 public function testCheckoutAndResumeAndReceiptOwnership(): void {
  foreach ([$this->buyer,$this->seller->user] as $u) $this->em->persist((new UtilisateurEntite())->setUtilisateur($u)->setEntite($this->offer->course->getEntite())->setCreateur($this->seller->user)->setRoles($u===$this->buyer?['TENANT_STAGIAIRE']:['TENANT_FORMATEUR']));$this->em->flush();
  $stripe=$this->createMock(StripeGateway::class);$stripe->method('account')->willReturn(['charges_enabled'=>true,'payouts_enabled'=>true]);
  $stripe->expects(self::once())->method('checkout')->willReturnCallback(function(array $request,string $key) {
   self::assertSame(12000,$request['line_items'][0]['price_data']['unit_amount']);self::assertSame('acct_test',$request['payment_intent_data']['on_behalf_of']);self::assertStringStartsWith('market-checkout-',$key);
   return ['id'=>'cs_test','url'=>'https://checkout.stripe.com/test'];
  });
  $service=$this->payments($stripe);self::assertSame('https://checkout.stripe.com/test',$service->checkout($this->buyer,[$this->offer->id],['termsAccepted'=>true,'immediateAccess'=>true,'withdrawalAcknowledged'=>true]));
  $purchase=$this->em->getRepository(Purchase::class)->findOneBy([]);self::assertSame(2000,$purchase->commissionCents);self::assertSame('seller',$purchase->feesPayer);self::assertSame('https://checkout.stripe.com/test',$service->resume($purchase));
  $purchase->checkoutId=null;$this->em->flush();
  $client=self::getContainer()->get('test.client');$client->disableReboot();$client->catchExceptions(false);$client->loginUser($this->buyer);
  $client->request('GET','/cours-en-ligne/achat/'.$purchase->id);self::assertSame(200,$client->getResponse()->getStatusCode());
  $client->request('GET','/cours-en-ligne/mes-donnees');self::assertSame(200,$client->getResponse()->getStatusCode());self::assertStringContainsString($purchase->id,$client->getResponse()->getContent());
  $client->loginUser($this->seller->user);$client->catchExceptions(true);$client->request('GET','/cours-en-ligne/achat/'.$purchase->id);self::assertSame(404,$client->getResponse()->getStatusCode());
 }

 public function testSuperAdminSalesStayOnPlatformStripeWithoutConnect(): void {
  $owner=$this->seller->user;$owner->setRoles(['ROLE_SUPER_ADMIN']);$this->seller->accountId=null;$this->em->flush();
  // A super administrator can create their own courses without a trainer membership.
  (new Policy($this->em))->owns($owner,$this->offer->course->getEntite(),$this->offer->course);
  $stripe=$this->createMock(StripeGateway::class);$stripe->expects(self::never())->method('account');$stripe->expects(self::never())->method('createAccount');$stripe->expects(self::never())->method('transfer');$stripe->expects(self::never())->method('reverse');
  $stripe->expects(self::once())->method('checkout')->willReturnCallback(function(array $request) {
   self::assertArrayNotHasKey('on_behalf_of',$request['payment_intent_data']);self::assertArrayNotHasKey('transfer_data',$request['payment_intent_data']);self::assertArrayNotHasKey('application_fee_amount',$request['payment_intent_data']);
   return ['id'=>'cs_direct','url'=>'https://checkout.stripe.com/direct'];
  });
  $service=$this->payments($stripe);$service->checkout($this->buyer,[$this->offer->id],[]);
  $p=$this->em->getRepository(Purchase::class)->findOneBy([]);self::assertTrue($p->platformSale);self::assertSame(0,$p->commissionBps);self::assertSame(0,$p->commissionCents);self::assertSame('platform',$p->feesPayer);
  $refund=0;$stripe->method('session')->willReturn(['metadata'=>['marketplace_purchase'=>$p->id],'amount_total'=>12000,'currency'=>'eur','payment_status'=>'paid','payment_intent'=>'pi_direct']);
  $stripe->method('payment')->willReturnCallback(function() use(&$refund){return ['status'=>'succeeded','currency'=>'eur','amount_received'=>12000,'latest_charge'=>['id'=>'ch_direct','created'=>time()-20*86400,'amount_refunded'=>$refund,'balance_transaction'=>['fee'=>210,'available_on'=>time()-86400]]];});
  $owner->setRoles(['ROLE_USER']);$this->em->flush(); // Historical destination survives role changes.
  $service->settle($p);$service->settle($p);self::assertSame(0,$p->sellerCents);self::assertSame(0,$p->transferredCents);self::assertSame(210,$p->stripeFeeCents);self::assertSame(10000,$p->platformRevenueHtCents());
  $refund=6000;$service->settle($p);self::assertSame(5000,$p->platformRevenueHtCents());
  $refund=12000;$service->settle($p);self::assertSame(0,$p->platformRevenueHtCents());self::assertTrue($this->em->getRepository(Access::class)->findOneBy([])->suspended);
 }
 public function testSuperAdminSellerScreensDoNotOfferConnect(): void {
  $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->seller->user)->setEntite($this->offer->course->getEntite())->setCreateur($this->seller->user)->setRoles(['TENANT_ADMIN']));
  $owner=$this->seller->user;$owner->setRoles(['ROLE_SUPER_ADMIN']);$this->em->flush();
  $client=self::getContainer()->get('test.client');$client->disableReboot();$client->catchExceptions(false);$client->loginUser($owner);
  $prefix='/formateur/'.$this->offer->course->getEntite()->getId().'/studio';
  foreach ([$prefix.'/vendeur',$prefix.'/cours/'.$this->offer->course->getId().'/diffuser','/super/marketplace/studios'] as $url) { $client->request('GET',$url);self::assertSame(200,$client->getResponse()->getStatusCode());self::assertStringNotContainsString('Activer mes versements avec Stripe',$client->getResponse()->getContent()); }
 }

}
