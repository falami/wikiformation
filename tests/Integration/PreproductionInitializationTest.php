<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\InitializePreproductionCommand;
use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Service\Preproduction\InitialData;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PreproductionInitializationTest extends KernelTestCase
{
    private array $previous;

    protected function setUp(): void
    {
        $this->previous = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->previous[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->previous[$i];
        }
    }

    public function testOtherDatabasesAreRejectedBeforeAnySchemaWrite(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tester = new CommandTester(self::getContainer()->get(InitializePreproductionCommand::class));
        self::assertSame(1, $tester->execute(['--execute' => true], ['interactive' => false]));
        self::assertStringContainsString('Initialisation refusée', $tester->getDisplay());
        self::assertSame([], $em->getConnection()->createSchemaManager()->listTableNames());
    }

    public function testFictionalAccountIsVerifiedAndSubscriptionHasNoStripeLink(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $hash = $hasher->hashPassword(new Utilisateur(), 'Only-a-test-password-2026');
        (new InitialData())->create($em, 'admin@example.test', $hash);
        $em->clear();
        $user = $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'admin@example.test']);
        self::assertTrue($user->isVerified());
        self::assertTrue($hasher->isPasswordValid($user, 'Only-a-test-password-2026'));
        self::assertContains('ROLE_SUPER_ADMIN', $user->getRoles());
        self::assertSame(1, $em->getRepository(Entite::class)->count([]));
        self::assertFalse($user->getEntite()->isPublic());
        self::assertSame(1, $em->getRepository(UtilisateurEntite::class)->count([]));
        self::assertSame(5, $em->getRepository(Plan::class)->count([]));
        $sub = $em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $user->getEntite()]);
        self::assertSame('active', $sub->getStatus());
        self::assertSame('SANDBOX', $sub->getPlan()->getCode());
        self::assertNull($sub->getStripeSubscriptionId());
        self::assertNull($sub->getStripeCustomerId());
    }
}
