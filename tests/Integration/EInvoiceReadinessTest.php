<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{EInvoiceConnection, Entite, Facture, Utilisateur};
use App\Entity\Billing\EntiteSubscription;
use App\Service\EInvoicing\{EInvoiceConfiguration, EInvoiceReadiness, SubscriptionPaymentReadiness};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EInvoiceReadinessTest extends KernelTestCase
{
    private array $database;
    private $em;
    private Entite $entite;
    private Utilisateur $user;
    private EInvoiceConnection $connection;
    private EntiteSubscription $subscription;
    private SubscriptionPaymentReadiness $payments;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(EInvoiceConfiguration::class, new EInvoiceConfiguration('fixture', 'fixture', 'https://example.test/callback', base64_encode(str_repeat('k', 32))));
        $this->payments = $this->createMock(SubscriptionPaymentReadiness::class);
        self::getContainer()->set(SubscriptionPaymentReadiness::class, $this->payments);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('gate@example.test')->setPassword('fixture')->setNom('Test')->setPrenom('Gate');
        $this->entite = (new Entite())->setNom('Organisme')->setSiret('12345678900012')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->connection = new EInvoiceConnection($this->entite, 'production');
        $this->connection->connected(['id'=>'company-fixture', 'number'=>'123456789', 'formal_name'=>'Organisme'], 'encrypted-fixture');
        $this->connection->consentToBilling($this->user->getId()); $this->connection->setAutomatic(true);
        $this->subscription = (new EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setStripeCustomerId('cus_fixture')->setStripeSubscriptionId('sub_fixture');
        $this->em->persist($this->connection); $this->em->persist($this->subscription); $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$target) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$target]['DATABASE_URL']);
            else $GLOBALS['_'.$target]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public static function unavailableStates(): iterable
    {
        foreach (['missing', 'sandbox', 'disabled', 'disconnected', 'identity', 'consent', 'legacy-free', 'no-subscription', 'canceled', 'expired', 'no-stripe-ids'] as $state) yield $state=>[$state];
    }

    #[DataProvider('unavailableStates')]
    public function testEveryLocalPrerequisiteBlocksEvenAPreassignedInvoiceNumber(string $state): void
    {
        $this->payments->expects(self::never())->method('assertReady');
        switch ($state) {
            case 'missing': $this->em->remove($this->connection); break;
            case 'sandbox':
                $this->em->remove($this->connection);
                $this->em->persist(new EInvoiceConnection($this->entite, 'sandbox'));
                break;
            case 'disabled': $this->connection->setAutomatic(false); break;
            case 'disconnected': $this->connection->disconnect(); break;
            case 'identity': $this->entite->setSiret('98765432100012'); break;
            case 'consent': (new \ReflectionProperty($this->connection, 'billingConsentAt'))->setValue($this->connection, null); break;
            case 'legacy-free':
                $this->entite->setSiret('80813612100023');
                (new \ReflectionProperty($this->connection, 'companyNumber'))->setValue($this->connection, '808136121');
                (new \ReflectionProperty($this->connection, 'unitPriceCents'))->setValue($this->connection, 0);
                break;
            case 'no-subscription': $this->em->remove($this->subscription); break;
            case 'canceled': $this->subscription->setStatus('canceled'); break;
            case 'expired': $this->subscription->setCurrentPeriodEnd(new \DateTimeImmutable('-1 minute')); break;
            case 'no-stripe-ids': $this->subscription->setStripeCustomerId(null); break;
        }
        $this->em->flush();
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('CANNOT-BYPASS');
        try {
            $this->em->persist($invoice);
            self::fail('No new invoice may bypass the gate through Doctrine.');
        } catch (\DomainException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertNull($invoice->getId());
            self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
            self::assertTrue($this->em->isOpen());
        }
    }

    public function testStripeFailureBlocksIssuanceDespiteAnActiveLocalSubscription(): void
    {
        $this->payments->expects(self::once())->method('assertReady')->with($this->subscription)->willThrowException(new \DomainException('Moyen de paiement indisponible.'));
        $this->expectException(\DomainException::class);
        self::getContainer()->get(EInvoiceReadiness::class)->assertCanIssue($this->entite);
    }

    public function testReadyConnectionWorksAndRevocationBlocksTheNextInvoice(): void
    {
        $this->payments->expects(self::once())->method('assertReady')->with($this->subscription);
        $gate = self::getContainer()->get(EInvoiceReadiness::class);
        $gate->assertCanIssue($this->entite);
        $this->connection->disconnect(); $this->em->flush();
        $this->expectException(\DomainException::class);
        $gate->assertCanIssue($this->entite);
    }

    public function testInternalOrganismAlsoRequiresStripePaymentReadiness(): void
    {
        $this->entite->setSiret('80813612100023');
        (new \ReflectionProperty($this->connection, 'companyNumber'))->setValue($this->connection, '808136121');
        $this->connection->consentToBilling($this->user->getId());
        self::assertSame(1, $this->connection->getUnitPriceCents());
        $this->em->flush();
        $this->payments->expects(self::once())->method('assertReady')->with($this->subscription);
        self::getContainer()->get(EInvoiceReadiness::class)->assertCanIssue($this->entite);
    }
}
