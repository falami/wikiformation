<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Billing\{Addon, EntiteSubscription, Plan};
use App\Service\Billing\EntitlementService;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillingAddonQuotaTest extends KernelTestCase
{
    public function testAddonSelectionIsCanonical(): void
    {
        $subscription = (new EntiteSubscription())->setAddons([' pack ', 'pack', '', '  ', 42, ['pack'], 'second']);
        self::assertSame(['pack', 'second'], $subscription->getAddons());
        self::assertNull($subscription->setAddons(null)->getAddons());
    }

    public function testHistoricalDuplicateAddonCodesNeverMultiplyLearnerQuota(): void
    {
        $database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $em = self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $owner = (new Utilisateur())->setEmail('owner@addon.test')->setNom('Owner')->setPrenom('Test')->setPassword('unused');
            $tenant = (new Entite())->setNom('Addons')->setCreateur($owner)->setPublic(false);
            $plan = (new Plan())->setCode('ADDON_TEST')->setName('Addon')->setMaxApprenantsAn(100);
            $pack = (new Addon())->setCode('pack')->setName('Pack')->setStripePriceId('price_test')->setExtraApprenantsAn(50);
            $inactive = (new Addon())->setCode('inactive')->setName('Inactive')->setStripePriceId('price_inactive')->setExtraApprenantsAn(80)->setIsActive(false);
            $subscription = (new EntiteSubscription())->setEntite($tenant)->setPlan($plan)->setStatus('active');
            foreach ([$owner, $tenant, $plan, $pack, $inactive, $subscription] as $record) $em->persist($record);
            $em->flush();
            // Direct hydration reproduces historical JSON predating setter normalization.
            (new \ReflectionProperty(EntiteSubscription::class, 'addons'))->setValue($subscription, ['pack', ' pack ', 'inactive', 'unknown']);
            $em->flush();
            $entitlement = self::getContainer()->get(EntitlementService::class);
            self::assertSame(150, $entitlement->limits($tenant)['max_apprenants_an']);
            self::assertSame(150, $entitlement->limits($tenant, true)['max_apprenants_an']);
            $plan->setMaxApprenantsAn(0);
            $em->flush();
            self::assertSame(0, $entitlement->limits($tenant)['max_apprenants_an']);
            self::assertSame(0, $entitlement->limits($tenant, true)['max_apprenants_an']);
        } finally {
            self::ensureKernelShutdown();
            foreach (['ENV', 'SERVER'] as $index => $type) {
                if ($database[$index] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
                else $GLOBALS['_'.$type]['DATABASE_URL'] = $database[$index];
            }
        }
    }
}
