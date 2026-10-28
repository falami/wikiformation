<?php

namespace App\Tests\Integration;

use App\Entity\{AuditLog, Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\Billing\{StripeBillingService, SubscriptionTerms};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class BillingCatalogueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002170000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261003110000.php';
        $this->migrate();
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient());
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    private function migrate(): void
    {
        foreach ([\DoctrineMigrations\Version20261002170000::class, \DoctrineMigrations\Version20261003110000::class] as $class) {
            $migration = new $class($this->em->getConnection(), new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $this->em->getConnection()->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }

    private function url(string $name): string
    {
        return self::getContainer()->get('router')->generate($name, ['_locale'=>'fr']);
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        return $client;
    }

    public function testNewCatalogueIsIdempotentAndPreservesHistoricalPrices(): void
    {
        $legacy = (new Plan())->setCode('ESSENTIEL')->setName('Essentiel')->setPriceMonthlyCents(19900)->setOrdre(1)->setStripePriceMonthlyId('price_existing');
        $this->em->persist($legacy);
        $this->em->flush();
        $this->migrate();
        $plans = $this->em->getRepository(Plan::class)->findPublicOffers();
        self::assertSame(['SOLO', 'PRO', 'ORGANISME', 'EXCELLENCE_2026'], array_map(fn(Plan $p) => $p->getCode(), $plans));
        self::assertSame([2900,5900,9900,15000], array_map(fn(Plan $p) => $p->getPriceMonthlyCents(), $plans));
        self::assertSame([100,300,1000,0], array_map(fn(Plan $p) => $p->getMaxApprenantsAn(), $plans));
        self::assertSame(5, $this->em->getRepository(Plan::class)->count([]));
        $this->em->refresh($legacy);
        self::assertSame(19900, $legacy->getPriceMonthlyCents());
        self::assertSame('price_existing', $legacy->getStripePriceMonthlyId());
        foreach ($plans as $plan) {
            self::assertNull($plan->getStripePriceMonthlyId());
            self::assertNull($plan->getPriceYearlyCents());
        }
    }

    public function testCgvUsesThePublicCatalogueAndReflectsPriceChanges(): void
    {
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['code' => 'SOLO']);
        $plan->setPriceMonthlyCents(3150);
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_public_cgv'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['Solo', 'Pro', 'Organisme', 'Excellence'], $crawler->filter('.plan-name')->each(fn($node) => $node->text()));
        self::assertSame(['31,50 €', '59,00 €', '99,00 €', '150,00 €'], $crawler->filter('.plan-price')->each(fn($node) => $node->text()));
        foreach ($this->em->getRepository(Plan::class)->findPublicOffers() as $index => $offer) {
            $features = $crawler->filter('.plan-features')->eq($index)->text();
            self::assertStringContainsString($offer->getMaxApprenantsLabel(), $features);
            self::assertStringContainsString($offer->getMaxFormateursLabel(), $features);
            self::assertStringContainsString($offer->getMaxCommerciauxLabel(), $features);
        }
        self::assertStringNotContainsString('/ an', $crawler->filter('.plans-grid')->text());
        self::assertStringNotContainsString('Essentiel', $crawler->filter('main')->text());
    }

    public function testHomeAndPricingRenderTheSameMonthlyOffersWithoutStripe(): void
    {
        $client = $this->client();
        foreach (['app_public', 'app_public_pricing'] as $route) {
            $crawler = $client->request('GET', $this->url($route));
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(['Solo','Pro','Organisme','Excellence'], $crawler->filter('.wf-offer h3')->each(fn($node) => $node->text()));
            self::assertSame(['29 €','59 €','99 €','150 €'], $crawler->filter('.wf-offer-price strong')->each(fn($node) => $node->text()));
            self::assertCount(0, $crawler->filter('.wf-offer select[name="interval"]'));
            self::assertCount(4, $crawler->filter('.wf-offer input[name="interval"][value="month"]'));
            self::assertStringNotContainsString('-20%', $crawler->filter('.wf-offers')->text());
            self::assertStringContainsString('30 jours d’essai', $crawler->filter('.wf-offers-intro')->text());
        }
    }

    public function testTrialKeepsMonthlyChoiceForLoginAndOnboarding(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        $client->submit($crawler->filter('.wf-offer-form')->first()->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('login', $client->getResponse()->headers->get('Location'));
        $session = $client->getRequest()->getSession();
        self::assertSame('SOLO', $session->get('pricing_selected_plan'));
        self::assertSame('month', $session->get('pricing_selected_interval'));
    }

    public function testLocalStripePriceSetupIsExplicitAndSupportsDryRun(): void
    {
        $command = new \App\Command\ConfigureBillingPriceCommand($this->em->getRepository(Plan::class), $this->em);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['plan'=>'SOLO','interval'=>'month','price-id'=>'price_solo123','--dry-run'=>true]));
        $solo = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'SOLO']);
        self::assertNull($solo->getStripePriceMonthlyId());
        self::assertSame(0, $tester->execute(['plan'=>'SOLO','interval'=>'month','price-id'=>'price_solo123']));
        self::assertSame('price_solo123', $solo->getStripePriceMonthlyId());
        self::assertSame(2900, $solo->getPriceMonthlyCents());
        self::assertSame(2, $tester->execute(['plan'=>'PRO','interval'=>'month','price-id'=>'price_solo123']));
        self::assertSame(2, $tester->execute(['plan'=>'SOLO','interval'=>'year','price-id'=>'price_soloYear']));
        self::assertNull($solo->getPriceYearlyCents());
        self::assertFalse($solo->isCheckoutConfigured('year'));
        self::assertSame(0, $tester->execute(['plan'=>'EXCELLENCE_2026','interval'=>'month','price-id'=>'price_excellence150']));
        $excellence = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'EXCELLENCE_2026']);
        self::assertSame(15000, $excellence->getPriceMonthlyCents());
        self::assertSame('price_excellence150', $excellence->getStripePriceMonthlyId());
    }

    public function testOnboardingDefaultsToMonthlyAndCreatesThirtyDayTrial(): void
    {
        // This brand-new user has no membership; skip the MySQL-only role-ranking expression.
        $actualMemberships = new \App\Repository\UtilisateurEntiteRepository(self::getContainer()->get('doctrine'));
        $memberships = $this->createMock(\App\Repository\UtilisateurEntiteRepository::class);
        $memberships->method('findFirstEntiteForUser')->willReturn(null);
        $memberships->method('userHasEntite')->willReturnCallback($actualMemberships->userHasEntite(...));
        self::getContainer()->set(\App\Repository\UtilisateurEntiteRepository::class, $memberships);
        $user = (new Utilisateur())->setEmail('newpricing@example.test')->setPassword('unused')->setNom('Nouveau')->setPrenom('Client');
        $this->em->persist($user);
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($user);
        $pricing = $client->request('GET', $this->url('app_public_pricing'));
        $client->submit($pricing->filter('form.wf-offer-form')->eq(1)->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();
        self::assertSame('PRO', $crawler->filter('input[name="entite_onboarding[planCode]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('.wk-plan.is-selected[aria-pressed="true"][data-plan="PRO"]'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        // A remembered legacy code must match regardless of casing or whitespace.
        $session = $client->getRequest()->getSession();
        $session->set('pricing_selected_plan', ' pro ');
        $session->save();
        $crawler = $client->request('GET', $this->url('app_onboarding'));
        self::assertSame('PRO', $crawler->filter('input[name="entite_onboarding[planCode]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('.wk-plan.is-selected[aria-pressed="true"][data-plan="PRO"]'));
        self::assertSame('month', $crawler->filter('input[name="entite_onboarding[interval]"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-interval="year"]'));
        self::assertSame(1, $crawler->filter('#onboardingForm #planGrid')->count());
        self::assertSame(['SOLO','PRO','ORGANISME','EXCELLENCE_2026'], $crawler->filter('#planGrid [data-plan]')->each(fn($node) => $node->attr('data-plan')));
        self::assertStringContainsString('30 jours d’essai', $crawler->text());
        $values = [
            'entite_onboarding[planCode]' => 'PRO',
            'entite_onboarding[nom]' => 'Organisme essai',
            'entite_onboarding[email]' => 'essai@example.test',
            'entite_onboarding[telephone]' => '0612345678',
            'entite_onboarding[ville]' => '',
        ];
        // Keep the chosen card clearly selected when validation returns the form.
        $crawler = $client->submit($crawler->filter('#onboardingForm')->form($values));
        self::assertCount(1, $crawler->filter('.wk-plan.is-selected[aria-pressed="true"][data-plan="PRO"]'));
        self::assertCount(3, $crawler->filter('.wk-plan[aria-pressed="false"]'));
        $values['entite_onboarding[ville]'] = 'Nîmes';
        $client->submit($crawler->filter('#onboardingForm')->form($values));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy([]);
        self::assertNotNull($sub);
        self::assertSame('PRO', $sub->getPlan()->getCode());
        self::assertEquals($sub->getStartedAt()->modify('+30 days'), $sub->getTrialEndsAt());
        self::assertEquals($sub->getTrialEndsAt(), $sub->getCurrentPeriodEnd());
    }

    public function testExistingOrganisationGetsThirtyDaysAndCannotRestartItsTrial(): void
    {
        [$user, $entite, $previous] = $this->subscribedTenant('trialing');
        $this->em->remove($previous);
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $data = ['plan'=>'SOLO', 'interval'=>'month', '_token'=>$token];
        $client->request('POST', $this->url('app_pricing_start_trial'), $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite'=>$entite]);
        self::assertNotNull($sub);
        self::assertEquals($sub->getStartedAt()->modify('+30 days'), $sub->getTrialEndsAt());
        self::assertEquals($sub->getTrialEndsAt(), $sub->getCurrentPeriodEnd());
        // Existing trials keep their promised expiry, including older three-month trials.
        $expiry = new \DateTimeImmutable('+70 days');
        $sub->setTrialEndsAt($expiry);
        $this->em->flush();
        $client->request('POST', $this->url('app_pricing_start_trial'), $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $sub = $this->em->getRepository(EntiteSubscription::class)->find($sub->getId());
        self::assertEquals($expiry->getTimestamp(), $sub->getTrialEndsAt()->getTimestamp());
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
    }

    public function testCheckoutCannotCreateSubscriptionWithoutStripePrice(): void
    {
        $user = (new Utilisateur())->setEmail('pricing@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Tarifs');
        $platform = (new Entite())->setNom('Plateforme')->setCreateur($user)->setPublic(false);
        $entite = (new Entite())->setNom('Client tarifs')->setCreateur($user)->setPublic(false);
        foreach ([$user,$platform,$entite] as $record) $this->em->persist($record);
        $this->em->flush();
        $user->setEntite($entite);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entite)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $user->addUtilisateurEntite($membership);
        $this->em->persist($membership);
        $sub = (new EntiteSubscription())->setEntite($entite)->setPlan($this->em->getRepository(Plan::class)->findOneBy(['code'=>'SOLO']))->setStatus('trialing')->setTrialEndsAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($sub);
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        self::assertCount(4, $crawler->filter('.wf-offer-cta[disabled]'));
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', $this->url('app_billing_checkout'), ['plan'=>'SOLO','interval'=>'month','_token'=>$token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
        self::assertNull($sub->getStripeCustomerId());
    }
    public function testLegacyExcellenceAnnualSubscriptionIsPreservedAndNeverAdvertised(): void
    {
        $legacy = (new Plan())->setCode('EXCELLENCE')->setName('Excellence historique')
            ->setPriceMonthlyCents(79900)->setPriceYearlyCents(766800)
            ->setStripePriceMonthlyId('price_old799')->setStripePriceYearlyId('price_oldAnnual');
        $user = (new Utilisateur())->setEmail('legacy@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Legacy');
        $entite = (new Entite())->setNom('Abonné annuel historique')->setCreateur($user)->setPublic(false);
        $sub = (new EntiteSubscription())->setEntite($entite)->setPlan($legacy)->setStatus('active')->setIntervale('year')->setStripeSubscriptionId('sub_historical');
        foreach ([$user, $entite, $legacy, $sub] as $record) $this->em->persist($record);
        $this->em->flush();
        $this->migrate();
        $this->em->refresh($legacy);
        $this->em->refresh($sub);
        self::assertSame(79900, $legacy->getPriceMonthlyCents());
        self::assertSame(766800, $legacy->getPriceYearlyCents());
        self::assertSame('price_old799', $legacy->getStripePriceMonthlyId());
        self::assertSame('price_oldAnnual', $legacy->getStripePriceYearlyId());
        self::assertSame($legacy, $sub->getPlan());
        self::assertSame('year', $sub->getIntervale());
        self::assertSame('sub_historical', $sub->getStripeSubscriptionId());
        self::assertFalse($legacy->isAvailableForNewSubscription('month'));
        $current = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'EXCELLENCE_2026']);
        self::assertSame(15000, $current->getPriceMonthlyCents());
        self::assertNull($current->getStripePriceMonthlyId());
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\ConfigureBillingPriceCommand($this->em->getRepository(Plan::class), $this->em));
        self::assertSame(2, $tester->execute(['plan'=>'EXCELLENCE_2026','interval'=>'month','price-id'=>'price_old799']));
        self::assertSame(2, $tester->execute(['plan'=>'EXCELLENCE','interval'=>'month','price-id'=>'price_other']));
    }

    public function testConfiguredAnnualPriceCannotBeSelectedByNewTrial(): void
    {
        $solo = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'SOLO']);
        $solo->setPriceYearlyCents(34800)->setStripePriceYearlyId('price_annual');
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        self::assertCount(4, $crawler->filter('.wf-offer-cta:not([disabled])'));
        self::assertCount(0, $crawler->filter('select[name="interval"], input[value="year"]'));
        $client->request('POST', $this->url('app_pricing_start_trial'), ['plan'=>'SOLO','interval'=>'year','_token'=>$crawler->filter('input[name="_token"]')->first()->attr('value')]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/tarifs', $client->getResponse()->headers->get('Location'));
        self::assertNull($client->getRequest()->getSession()->get('pricing_selected_plan'));
        self::assertSame(0, $this->em->getRepository(EntiteSubscription::class)->count([]));
    }

    public function testActiveTrialRemainsAccessibleWithoutConfiguredStripePrices(): void
    {
        [$user, $entite, $sub] = $this->subscribedTenant('trialing');
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('.wf-offer-cta[disabled]'));
        self::assertCount(4, $crawler->filter('a.wf-offer-cta'));
        self::assertSame('Continuer mon essai', $crawler->filter('a.wf-offer-cta')->first()->text());
        self::assertStringContainsString('/administrateur/'.$entite->getId().'/billing', $crawler->filter('a.wf-offer-cta')->first()->attr('href'));
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
    }

    public function testAnnualCheckoutAndPlanChangeAreRejectedWithoutChangingExistingAnnualContract(): void
    {
        [$user, $entite, $sub] = $this->subscribedTenant('active');
        $sub->setIntervale('year')->setStripeSubscriptionId('sub_existing')->setStripeCustomerId('cus_existing');
        $solo = $sub->getPlan();
        $solo->setPriceYearlyCents(34800)->setStripePriceYearlyId('price_annual');
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        $client->request('POST', $this->url('app_billing_checkout'), ['plan'=>'SOLO','interval'=>'year','_token'=>$crawler->filter('input[name="_token"]')->first()->attr('value')]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_billing', ['_locale'=>'fr','entite'=>$entite->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('#intervalYear'));
        $client->request('POST', $this->url('app_billing_change_preview'), ['plan'=>'SOLO','interval'=>'year','_token'=>$crawler->filter('#previewToken')->attr('value')]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $client->request('POST', $this->url('app_billing_change_apply'), ['plan'=>'SOLO','interval'=>'year','_token'=>$crawler->filter('#applyChangeForm input[name="_token"]')->attr('value')]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
        $sub = $this->em->getRepository(EntiteSubscription::class)->find($sub->getId());
        self::assertSame('year', $sub->getIntervale());
        self::assertSame('sub_existing', $sub->getStripeSubscriptionId());
        self::assertSame('price_annual', $solo->getStripePriceYearlyId());
    }

    public function testPlatformMembershipDoesNotDisableNewOrganisationTrial(): void
    {
        $user = (new Utilisateur())->setEmail('platform-pricing@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Plateforme');
        $platform = (new Entite())->setNom('Plateforme')->setCreateur($user)->setPublic(false);
        foreach ([$user, $platform] as $record) $this->em->persist($record);
        $this->em->flush();
        $user->setEntite($platform);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($platform)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $user->addUtilisateurEntite($membership);
        $sub = (new EntiteSubscription())->setEntite($platform)->setPlan($this->em->getRepository(Plan::class)->findOneBy(['code'=>'SOLO']))->setStatus('trialing')->setTrialEndsAt(new \DateTimeImmutable('-1 month'));
        foreach ([$membership, $sub] as $record) $this->em->persist($record);
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(4, $crawler->filter('button.wf-offer-cta:not([disabled])'));
        $client->submit($crawler->filter('.wf-offer-form')->first()->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/onboarding', $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
    }

    public function testCheckoutRequiresExplicitCurrentTermsBeforeAnyStripeCall(): void
    {
        [$user, , $previous] = $this->subscribedTenant('canceled');
        $previous->getPlan()->setStripePriceMonthlyId('price_solo');
        $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::never())->method('request');
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        self::assertCount(4, $crawler->filter('input[name="terms_accepted"][required]:not([checked])'));
        $form = $crawler->filter('.wf-offer-form')->first()->form();
        $data = $form->getPhpValues();
        foreach ([[], ['terms_accepted' => '1', 'terms_version' => 'old'], ['terms_accepted' => '1', 'terms_digest' => 'forged']] as $override) {
            $client->request('POST', $this->url('app_billing_checkout'), array_replace($data, $override));
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('/tarifs', $client->getResponse()->headers->get('Location'));
            self::assertSame(0, $this->em->getRepository(AuditLog::class)->count(['event' => 'subscription.terms_accepted']));
            self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
        }
        // A price or document change after rendering requires review again, even with the same version label.
        $this->em->getRepository(Plan::class)->findOneBy(['code' => 'SOLO'])->setPriceMonthlyCents(3150);
        $this->em->flush();
        $client->request('POST', $this->url('app_billing_checkout'), array_replace($data, ['terms_accepted' => '1']));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count(['event' => 'subscription.terms_accepted']));
    }

    public function testCheckoutFreezesAcceptedTermsAndPriceBeforeOpeningStripe(): void
    {
        [$user, $entite, $previous] = $this->subscribedTenant('canceled');
        $previous->getPlan()->setStripePriceMonthlyId('price_solo');
        $this->em->flush();
        self::getContainer()->set(StripeBillingService::class, new StripeBillingService('sk_test_fixture', 'http://localhost'));
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method, $url, $headers, $params): array {
            if (str_ends_with($url, '/prices/price_solo')) {
                $body = ['id' => 'price_solo', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 2900,
                    'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'tax_behavior' => 'exclusive'];
            } elseif (str_ends_with($url, '/customers')) {
                $body = ['id' => 'cus_terms', 'object' => 'customer'];
            } else {
                self::assertStringEndsWith('/checkout/sessions', $url);
                self::assertSame(1, $this->em->getRepository(AuditLog::class)->count(['event' => 'subscription.terms_accepted']));
                self::assertSame('cus_terms', $params['customer']);
                $body = ['id' => 'cs_terms', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/test-terms'];
            }
            return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->loginUser($user);
        $crawler = $client->request('GET', $this->url('app_public_pricing'));
        $data = $crawler->filter('.wf-offer-form')->first()->form()->getPhpValues();
        $data['terms_accepted'] = '1';
        $client->request('POST', $this->url('app_billing_checkout'), $data);
        self::assertSame('https://checkout.stripe.com/test-terms', $client->getResponse()->headers->get('Location'));
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['event' => 'subscription.terms_accepted']);
        self::assertNotNull($audit);
        self::assertSame($user->getId(), $audit->getActor()->getId());
        self::assertSame($entite->getId(), $audit->getEntite()->getId());
        $proof = $audit->getPayload();
        self::assertSame(SubscriptionTerms::VERSION, $proof['terms']['version']);
        self::assertSame($data['terms_digest'], hash('sha256', $proof['terms']['html']));
        self::assertSame($proof['terms']['sha256'], $data['terms_digest']);
        self::assertStringContainsString('id="s12"', $proof['terms']['html']);
        self::assertStringContainsString('provision pour le traitement du paiement', $proof['terms']['html']);
        self::assertSame(2900, $proof['plan']['priceCentsExcludingTax']);
        self::assertSame('month', $proof['plan']['interval']);
        self::assertSame('accepted_before_checkout', $proof['stage']);
        self::assertNotSame($previous->getId(), $proof['subscriptionId']);
        // A later catalogue change must never rewrite the acceptance evidence.
        $this->em->getRepository(Plan::class)->findOneBy(['code' => 'SOLO'])->setPriceMonthlyCents(3150);
        $this->em->flush();
        $this->em->refresh($audit);
        self::assertSame($proof, $audit->getPayload());
    }

    /** @return array{Utilisateur, Entite, EntiteSubscription} */
    private function subscribedTenant(string $status): array
    {
        $user = (new Utilisateur())->setEmail('tenant-pricing@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Tarifs');
        $platform = (new Entite())->setNom('Plateforme')->setCreateur($user)->setPublic(false);
        $entite = (new Entite())->setNom('Client tarifs')->setCreateur($user)->setPublic(false);
        foreach ([$user, $platform, $entite] as $record) $this->em->persist($record);
        $this->em->flush();
        $user->setEntite($entite);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entite)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $user->addUtilisateurEntite($membership);
        $this->em->persist($membership);
        $sub = (new EntiteSubscription())->setEntite($entite)->setPlan($this->em->getRepository(Plan::class)->findOneBy(['code'=>'SOLO']))->setStatus($status)->setTrialEndsAt(new \DateTimeImmutable('+1 month'));
        $this->em->persist($sub);
        $this->em->flush();
        return [$user, $entite, $sub];
    }

}
