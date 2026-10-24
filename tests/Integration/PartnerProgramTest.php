<?php
namespace App\Tests\Integration;

use App\Entity\{Entite,Utilisateur};
use App\Entity\Billing\{Plan,EntiteSubscription};
use App\Entity\Partner\{Partner,PartnerTerms,Referral,Commission};
use App\Service\Partner\{Referrals,Commissions,PartnerStripeGateway,CommissionMath};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Lock\{LockFactory,Store\FlockStore};

final class PartnerProgramTest extends KernelTestCase
{
    private $em;
    private Partner $partner;
    private PartnerTerms $terms;
    private Referral $ref;
    private PartnerStripeGateway $gateway;
    private Commissions $service;
    private array $database;
    private array $invoice;
    private array $charge;
    protected function setUp(): void
    {
        $this->database=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class,$this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em=self::getContainer()->get('doctrine')->getManager();
        $this->em->getConfiguration()->addCustomStringFunction('JSON_CONTAINS',PartnerJsonContains::class);
        $this->em->getConnection()->getNativeConnection()->sqliteCreateFunction('JSON_CONTAINS',static fn($json,$value)=>(int)in_array(json_decode($value,true),json_decode($json,true),true));
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $owner=(new Utilisateur())->setEmail('client@example.test')->setPassword('unused')->setPrenom('Client')->setNom('Test');
        $user=(new Utilisateur())->setEmail('partner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin')->setRoles(['ROLE_PARTNER']);
        $entity=(new Entite())->setNom('Centre Formation')->setCreateur($owner)->setPublic(false);
        $plan=(new Plan())->setCode('pro')->setName('Professionnel')->setStripePriceMonthlyId('price_plan');
        foreach([$owner,$user,$entity,$plan] as $row) $this->em->persist($row);
        $this->partner=new Partner();$this->partner->user=$user;$this->partner->name='Conseil Formation';$this->partner->code='WF-TEST';$this->partner->automaticPayouts=true;$this->partner->stripeAccountId='acct_partner';
        $this->terms=new PartnerTerms();$this->terms->partner=$this->partner;$this->terms->author=$owner;$this->terms->rateBps=2000;$this->terms->effectiveAt=new \DateTimeImmutable('-60 days');
        $this->ref=new Referral();$this->ref->partner=$this->partner;$this->ref->entite=$entity;$this->ref->author=$owner;$this->ref->createdAt=new \DateTimeImmutable('-60 days');
        foreach([$this->partner,$this->terms,$this->ref] as $row) $this->em->persist($row);
        $this->em->persist((new EntiteSubscription())->setEntite($entity)->setPlan($plan)->setStatus('active')->setStripeSubscriptionId('sub_test')->setStripeCustomerId('cus_test'));
        $this->em->flush();
        $this->invoice=['id'=>'in_test','status'=>'paid','currency'=>'eur','total'=>12000,'amount_paid'=>12000,'status_transitions'=>['paid_at'=>(new \DateTimeImmutable('-31 days'))->getTimestamp()],
            '_charges'=>['ch_test'],'_hasCreditNotes'=>false,'_lines'=>[['amount'=>10000,'discount_amounts'=>[['amount'=>2000]],'_price'=>['id'=>'price_plan','tax_behavior'=>'exclusive']],['amount'=>2000,'_price'=>['id'=>'price_addon','tax_behavior'=>'exclusive']]]];
        $this->charge=['paid'=>true,'currency'=>'eur','amount'=>12000,'amount_refunded'=>0];
        $this->gateway=$this->createMock(PartnerStripeGateway::class);
        $this->gateway->method('invoice')->willReturnCallback(fn()=>$this->invoice);
        $this->gateway->method('charge')->willReturnCallback(fn()=>$this->charge);
        $this->gateway->method('account')->willReturn(['payouts_enabled'=>true,'capabilities'=>['transfers'=>'active'],'settings'=>['payouts'=>['schedule'=>['interval'=>'manual']]]]);
        $this->service=new Commissions($this->em,new Referrals($this->em),$this->gateway,new LockFactory(new FlockStore()));
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach(['_ENV','_SERVER'] as $i=>$key) {if ($this->database[$i]===null) unset($GLOBALS[$key]['DATABASE_URL']);else $GLOBALS[$key]['DATABASE_URL']=$this->database[$i];}
    }
    private function record(): Commission {return $this->service->record(['id'=>'in_test','customer'=>'cus_test','subscription'=>'sub_test']);}
    private function paid(Commission $c): void
    {
        $this->gateway->expects(self::once())->method('transfer')->with('acct_partner',1600,'ch_test','affiliate-transfer-'.$c->id)->willReturn(['id'=>'tr_test','amount'=>1600,'currency'=>'eur','destination'=>'acct_partner']);
        $this->gateway->expects(self::once())->method('payout')->with('acct_partner',1600,'affiliate-payout-'.$c->id)->willReturn(['id'=>'po_test','amount'=>1600,'currency'=>'eur','status'=>'paid']);
        self::assertSame('paid',$this->service->process($c,true));
    }
    public function testDiscountsOptionsVatAndWebhookIdempotency(): void
    {
        $c=$this->record();self::assertSame(8000,$c->baseCents);self::assertSame(1600,$c->grossCents);
        self::assertSame($c,$this->record());self::assertSame(1,$this->em->getRepository(Commission::class)->count([]));
        self::assertSame(30,$c->paidAt->diff($c->availableAt)->days);
    }
    public function testHistoricalTermsAreUsed(): void
    {
        $new=new PartnerTerms();$new->partner=$this->partner;$new->author=$this->ref->author;$new->rateBps=5000;$this->em->persist($new);$this->em->flush();
        self::assertSame(1600,$this->record()->grossCents);
    }
    public function testPartialPaymentsRequireReview(): void {$this->invoice['amount_paid']=5000;self::assertSame('review',$this->record()->status);}
    public function testCreditNotesRequireReview(): void {$this->invoice['_hasCreditNotes']=true;self::assertSame('review',$this->record()->status);}
    public function testNoPayoutBeforeThirtyDaysOrWhenPaused(): void
    {
        $c=$this->record();$c->availableAt=new \DateTimeImmutable('+1 day');$this->em->flush();
        $this->gateway->expects(self::never())->method('transfer');$this->gateway->expects(self::never())->method('payout');
        self::assertSame('pending',$this->service->process($c,true));
        $c->availableAt=new \DateTimeImmutable('-1 day');$this->partner->automaticPayouts=false;$this->em->flush();
        self::assertSame('pending',$this->service->process($c,true));
    }
    public function testDryRunDoesNotMoveMoney(): void {$c=$this->record();$this->gateway->expects(self::never())->method('transfer');self::assertSame('pending',$this->service->process($c));}
    public function testPaidThenRepeatedProcessingDoesNotDuplicate(): void
    {
        $c=$this->record();$this->paid($c);$this->gateway->method('getPayout')->willReturn(['status'=>'paid']);
        self::assertSame('paid',$this->service->process($c,true));
    }
    public function testPartialRefundReducesNetBeforeTransfer(): void
    {
        $c=$this->record();$this->charge['amount_refunded']=6000;
        $this->gateway->expects(self::once())->method('transfer')->with('acct_partner',800)->willReturn(['id'=>'tr_test','amount'=>800,'currency'=>'eur','destination'=>'acct_partner']);
        $this->gateway->method('payout')->willReturn(['id'=>'po_test','amount'=>800,'currency'=>'eur','status'=>'pending']);
        self::assertSame('paying',$this->service->process($c,true));self::assertSame(800,$c->netCents);
    }
    public function testFullRefundCancelsBeforeTransfer(): void {$c=$this->record();$this->charge['amount_refunded']=12000;$this->gateway->expects(self::never())->method('transfer');self::assertSame('cancelled',$this->service->process($c,true));}
    public function testLateRefundReversesOnce(): void
    {
        $c=$this->record();$this->paid($c);$this->charge['amount_refunded']=6000;
        $this->gateway->expects(self::once())->method('reverse')->with('tr_test',800,'affiliate-reverse-'.$c->id.'-800');
        $this->gateway->method('getPayout')->willReturn(['status'=>'paid']);
        $this->service->process($c,true);$this->service->process($c,true);self::assertSame(800,$c->reversedCents);
    }
    public function testFailedReversalHoldsPartnerBalance(): void
    {
        $c=$this->record();$this->paid($c);$this->charge['amount_refunded']=6000;
        $this->gateway->method('reverse')->willThrowException(new \RuntimeException('Stripe unavailable'));
        try {$this->service->process($c,true);self::fail('Expected error');} catch (\RuntimeException $e) {self::assertSame('held',$c->status);}
    }
    public function testExpiredUncertainTransferIsNeverBlindlyRetried(): void
    {
        $c=$this->record();$c->transferAttemptAt=new \DateTimeImmutable('-2 days');$this->em->flush();
        $this->gateway->expects(self::never())->method('transfer');self::assertSame('uncertain',$this->service->process($c,true));
    }
    public function testLostTransferResponseIsReconciled(): void
    {
        $c=$this->record();$c->transferAttemptAt=new \DateTimeImmutable('-2 days');$c->status='uncertain';$this->em->flush();
        $this->gateway->method('findTransfer')->willReturn(['id'=>'tr_existing','amount'=>1600,'currency'=>'eur','destination'=>'acct_partner']);
        $this->gateway->expects(self::never())->method('transfer');
        $this->gateway->method('payout')->willReturn(['id'=>'po_test','amount'=>1600,'currency'=>'eur','status'=>'pending']);
        self::assertSame('paying',$this->service->process($c,true));self::assertSame('tr_existing',$c->transferId);
    }
    public function testLostPayoutResponseIsReconciledWithoutSecondPayout(): void
    {
        $c=$this->record();$c->transferId='tr_test';$c->transferAttemptAt=new \DateTimeImmutable('-3 days');$c->payoutAttemptAt=new \DateTimeImmutable('-2 days');$c->status='uncertain';$this->em->flush();
        $this->gateway->expects(self::never())->method('transfer');$this->gateway->expects(self::never())->method('payout');
        $this->gateway->method('findPayout')->willReturn(['id'=>'po_existing','amount'=>1600,'currency'=>'eur','status'=>'paid']);
        self::assertSame('paid',$this->service->process($c,true));self::assertSame('po_existing',$c->payoutId);
    }
    public function testOpenDisputeHoldsCommission(): void
    {
        $c=$this->record();$this->charge['disputed']=true;$this->charge['dispute']='dp_test';
        $this->gateway->method('dispute')->willReturn(['status'=>'under_review']);$this->gateway->expects(self::never())->method('transfer');
        self::assertSame('held',$this->service->process($c,true));
    }
    public function testOneUnresolvedCommissionBlocksOtherPayouts(): void
    {
        $first=$this->record();$first->status='review';$this->em->flush();
        $second=$this->service->record(['id'=>'in_second','customer'=>'cus_test','subscription'=>'sub_test']);
        $this->gateway->expects(self::never())->method('transfer');self::assertSame('pending',$this->service->process($second,true));
    }
    public function testUnknownCodeAndDuplicateAttribution(): void
    {
        $refs=new Referrals($this->em);self::assertNull($refs->byCode('unknown'));self::assertSame($this->partner,$refs->byCode(' wf-test '));
        $this->expectException(\DomainException::class);$refs->attach($this->partner,$this->ref->entite,$this->ref->author);
    }
    public function testFeesAreCappedAtCommission(): void {self::assertSame(['fee'=>110,'net'=>890],CommissionMath::net(1000,100,100));self::assertSame(['fee'=>50,'net'=>0],CommissionMath::net(50,100,100));}
    public function testSelfReferralIsRejected(): void
    {
        $entity=(new Entite())->setNom('Self')->setCreateur($this->partner->user);
        $this->expectException(\DomainException::class);(new Referrals($this->em))->attach($this->partner,$entity,$this->partner->user,'code');
    }
    public function testAdminCreatesAndConfiguresPartner(): void
    {
        $admin=$this->ref->author;$admin->setRoles(['ROLE_SUPER_ADMIN']);$this->em->flush();
        $browser=new KernelBrowser(self::$kernel);$browser->disableReboot();$browser->loginUser($admin);
        $crawler=$browser->request('GET','/fr/super/partenaires');self::assertSame(200,$browser->getResponse()->getStatusCode());
        $browser->submit($crawler->selectButton('Créer l’espace partenaire')->form(['name'=>'Expert Conseil','firstName'=>'Alex','lastName'=>'Durand','email'=>'newpartner@example.test','rate'=>'12.50']));
        self::assertSame(302,$browser->getResponse()->getStatusCode());
        $crawler=$browser->followRedirect();self::assertSame(200,$browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Expert Conseil',$browser->getResponse()->getContent());
        $partner=$this->em->getRepository(Partner::class)->findOneBy(['name'=>'Expert Conseil']);
        self::assertNotNull($partner);self::assertFalse($partner->automaticPayouts);
        $form=$crawler->selectButton('Enregistrer les conditions')->form(['rate'=>'15','feeFixed'=>'0.25','feeRate'=>'0.10']);
        $form['active']->tick();$form['automatic']->tick();$form['feesConfirmed']->tick();
        $browser->submit($form);self::assertSame(302,$browser->getResponse()->getStatusCode());
        $partner=$this->em->find(Partner::class,$partner->id);self::assertTrue($partner->automaticPayouts);
        self::assertSame(2,$this->em->getRepository(PartnerTerms::class)->count(['partner'=>$partner]));
    }
    public function testAnotherPartnersClientDoesNotLeak(): void
    {
        $other=new Partner();$other->user=$this->ref->author;$other->name='Autre partenaire';$other->code='WF-OTHER';$this->em->persist($other);$this->em->flush();
        $browser=new KernelBrowser(self::$kernel);$browser->disableReboot();$browser->loginUser($other->user);
        $browser->request('GET','/fr/partenaire');self::assertSame(200,$browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Centre Formation',$browser->getResponse()->getContent());
    }
    public function testPortalIsScopedAndSuperAdminIsProtected(): void
    {
        $this->record();$browser=new KernelBrowser(self::$kernel);$browser->disableReboot();$browser->loginUser($this->partner->user);
        $browser->request('GET','/fr/partenaire');self::assertSame(200,$browser->getResponse()->getStatusCode(),$browser->getResponse()->getContent());
        self::assertStringContainsString('Centre Formation',$browser->getResponse()->getContent());
        $browser->request('GET','/fr/super/partenaires');self::assertSame(403,$browser->getResponse()->getStatusCode());
        $browser->request('POST','/fr/partenaire/banque');self::assertSame(403,$browser->getResponse()->getStatusCode());
        $browser->loginUser($this->ref->author);$browser->request('GET','/fr/partenaire');self::assertSame(403,$browser->getResponse()->getStatusCode());
    }
}

final class PartnerJsonContains extends \Scienta\DoctrineJsonFunctions\Query\AST\Functions\Mysql\JsonContains
{
 protected function validatePlatform(\Doctrine\ORM\Query\SqlWalker $sqlWalker): void {}
}
