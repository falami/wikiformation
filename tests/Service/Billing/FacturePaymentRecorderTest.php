<?php
namespace App\Tests\Service\Billing;

use App\Controller\Webhook\StripeWebhookController;
use App\Entity\{Entite, Facture, Paiement, Utilisateur};
use App\Entity\Billing\FactureCheckout;
use App\Enum\FactureStatus;
use App\Service\Billing\FacturePaymentRecorder;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class FacturePaymentRecorderTest extends KernelTestCase
{
    private $em;
    private array $env;
    private FactureCheckout $checkout;
    protected function setUp(): void
    {
        $this->env = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new Utilisateur())->setEmail('payments@example.test')->setPassword('unused')->setPrenom('Payment')->setNom('Test');
        $entity = (new Entite())->setNom('Test')->setPublic(false)->setCreateur($user);
        $this->em->persist($user);$this->em->persist($entity);$this->em->flush();
        $invoice = (new Facture())->setEntite($entity)->setCreateur($user)->setNumero('FAC-TEST')->setDateEmission(new \DateTimeImmutable())->setDestinataire($user)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $this->checkout = (new FactureCheckout())->setEntite($entity)->setFacture($invoice)->setStripeCheckoutSessionId('cs_fixture')->setFactureAmountCents(12000)->setAmountTotalCents(12300)->setServiceFeeCents(300);
        foreach ([$invoice, $this->checkout] as $row) $this->em->persist($row);
        $this->em->flush();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $key) {
            if ($this->env[$i] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->env[$i];
        }
    }
    private function session(array $override = []): array
    {
        return array_replace(['id'=>'cs_fixture','object'=>'checkout.session','mode'=>'payment','payment_status'=>'paid','amount_total'=>12300,'currency'=>'eur','payment_intent'=>'pi_fixture'], $override);
    }
    private function deliver(array $session, string $type = 'checkout.session.completed', bool $valid = true): int
    {
        $body = json_encode(['id'=>'evt_fixture','object'=>'event','type'=>$type,'data'=>['object'=>$session]]);
        $time = time(); $sig = hash_hmac('sha256', $time.'.'.$body, $valid ? 'fixture_secret' : 'invalid');
        $request = Request::create('/webhooks/stripe','POST',server:['HTTP_STRIPE_SIGNATURE'=>'t='.$time.',v1='.$sig],content:$body);
        $controller = new StripeWebhookController('fixture_secret', $this->em->getRepository(FactureCheckout::class), $this->em->getRepository(Paiement::class), $this->em, new FacturePaymentRecorder($this->em));
        return $controller($request)->getStatusCode();
    }
    public function testAsyncPaymentWaitsThenRecordsOnlyOnceIncludingReturnPageReplay(): void
    {
        self::assertSame(200,$this->deliver($this->session(['payment_status'=>'unpaid'])));
        self::assertSame(0,$this->em->getRepository(Paiement::class)->count([]));
        self::assertSame(200,$this->deliver($this->session(),'checkout.session.async_payment_succeeded'));
        self::assertSame(200,$this->deliver($this->session()));
        self::assertTrue((new FacturePaymentRecorder($this->em))->record($this->checkout,$this->session()));
        self::assertSame(1,$this->em->getRepository(Paiement::class)->count([]));
        self::assertSame(12000,$this->em->getRepository(Paiement::class)->findOneBy([])->getMontantCents());
        self::assertSame(FactureStatus::PAID,$this->checkout->getFacture()->getStatus());
        self::assertSame(200,$this->deliver($this->session(),'checkout.session.expired'));
        self::assertSame(FactureCheckout::STATUS_COMPLETED,$this->checkout->getStatus());
    }
    public function testWrongAmountDoesNotMarkAnInvoicePaid(): void
    {
        self::assertSame(500,$this->deliver($this->session(['amount_total'=>1])));
        self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM paiement'));
    }
    public function testSignedWebhookRecoversReservationAfterCheckoutResponseWasLost(): void
    {
        $this->checkout->setStripeCheckoutSessionId(null);
        $this->em->flush();
        $session = $this->session(['metadata' => ['type' => 'facture_checkout',
            'facture_checkout_id' => (string) $this->checkout->getId(),
            'facture_id' => (string) $this->checkout->getFacture()->getId(),
            'entite_id' => (string) $this->checkout->getEntite()->getId(),
        ]]);
        self::assertSame(200, $this->deliver($session));
        self::assertSame('cs_fixture', $this->checkout->getStripeCheckoutSessionId());
        self::assertSame(FactureCheckout::STATUS_COMPLETED, $this->checkout->getStatus());
        self::assertSame(1, $this->em->getRepository(Paiement::class)->count([]));
    }
    public function testWrongCurrencyDoesNotMarkAnInvoicePaid(): void
    {
        self::assertSame(500,$this->deliver($this->session(['currency'=>'usd'])));
        self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM paiement'));
    }
    public function testUnsignedPaymentIsRejected(): void
    {
        self::assertSame(400,$this->deliver($this->session(),valid:false));
        self::assertSame(0,$this->em->getRepository(Paiement::class)->count([]));
    }
}
