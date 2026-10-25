<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\{EntitlementService, QuotaUsage};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommercialQuotaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Utilisateur $owner;
    private Entite $tenant;
    private Plan $plan;
    private EntiteSubscription $subscription;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->owner = $this->user('owner');
        $this->tenant = (new Entite())->setNom('Commercial quotas')->setCreateur($this->owner)->setPublic(false);
        $this->plan = (new Plan())->setCode('COMMERCIAL_TEST')->setName('Commercial test')->setMaxUtilisateurs(1)
            ->setSeatLimits(['admins' => 2, 'formateurs' => 1]);
        $this->subscription = (new EntiteSubscription())->setEntite($this->tenant)->setPlan($this->plan)->setStatus('active');
        foreach ([$this->tenant, $this->plan, $this->subscription] as $record) $this->em->persist($record);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function user(string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($name.'@commercial-quota.test')->setPassword('unused')->setNom($name)->setPrenom('Test');
        $this->em->persist($user);
        return $user;
    }

    private function member(string $name, array $roles = [UtilisateurEntite::TENANT_COMMERCIAL], string $status = UtilisateurEntite::STATUS_ACTIVE, ?Entite $tenant = null): UtilisateurEntite
    {
        $member = (new UtilisateurEntite())->setUtilisateur($this->user($name))->setCreateur($this->owner)
            ->setEntite($tenant ?? $this->tenant)->setRoles($roles)->setStatus($status);
        $this->em->persist($member);
        return $member;
    }

    private function counts(): array
    {
        return self::getContainer()->get(QuotaUsage::class)->counts($this->tenant);
    }

    private function assertQuotaRejected(): void
    {
        try {
            $this->em->flush();
            self::fail('The commercial quota must reject this write.');
        } catch (BillingQuotaExceededException $error) {
            self::assertSame('max_commerciaux', $error->getQuotaKey());
        }
    }

    public function testExistingActiveAccountsAreCountedSeparatelyFromManagementAndTrainers(): void
    {
        $this->member('one', [UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_ADMIN]);
        $this->member('two', [UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_FORMATEUR]);
        $this->member('invited', status: UtilisateurEntite::STATUS_INVITED);
        $this->member('suspended', status: UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        self::assertSame(2, $this->counts()['max_commerciaux']);
        self::assertSame(1, $this->counts()['max_utilisateurs']);
        self::assertSame(1, $this->counts()['max_formateurs']);
        self::assertSame(2, self::getContainer()->get(EntitlementService::class)->limits($this->tenant)['max_commerciaux']);
        $this->member('third');
        $this->assertQuotaRejected();
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
    }

    public function testBatchCannotExceedQuota(): void
    {
        foreach (['one', 'two', 'three'] as $name) $this->member($name);
        $this->assertQuotaRejected();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
    }

    public function testAddingCommercialRoleIsChecked(): void
    {
        $this->member('one');
        $this->member('two');
        $third = $this->member('third', [UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        $third->addRole(UtilisateurEntite::TENANT_COMMERCIAL);
        $this->assertQuotaRejected();
    }

    public function testReactivationIsCheckedAfterSuspensionFreesPlace(): void
    {
        $one = $this->member('one');
        $this->member('two');
        $this->em->flush();
        $one->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        self::assertSame(1, $this->counts()['max_commerciaux']);
        $this->member('replacement');
        $this->em->flush();
        $one->setStatus(UtilisateurEntite::STATUS_ACTIVE);
        $this->assertQuotaRejected();
    }

    public function testRemovingRoleAndDeletingMembershipReleasePlaces(): void
    {
        $one = $this->member('one');
        $two = $this->member('two');
        $this->em->flush();
        $one->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->remove($two);
        $this->member('replacement');
        $this->em->flush();
        self::assertSame(1, $this->counts()['max_commerciaux']);
    }

    public function testOtherOrganismDoesNotConsumeCommercialCapacity(): void
    {
        $other = (new Entite())->setNom('Other')->setCreateur($this->owner)->setPublic(false);
        $this->em->persist($other);
        $this->member('other-one', tenant: $other);
        $this->member('one');
        $this->member('two');
        $this->em->flush();
        self::assertSame(2, $this->counts()['max_commerciaux']);
    }

    public function testUnlimitedStillCountsAndDowngradeAllowsEditingExistingAccounts(): void
    {
        $this->plan->setMaxCommerciaux(0);
        $this->member('one');
        $this->member('two');
        $third = $this->member('three');
        $this->em->flush();
        self::assertSame(3, $this->counts()['max_commerciaux']);
        $this->plan->setMaxCommerciaux(1);
        $this->em->flush();
        $third->setFonction('Commercial senior');
        $this->em->flush();
        self::assertSame(3, $this->counts()['max_commerciaux']);
        $this->member('four');
        $this->assertQuotaRejected();
    }

    public function testChangedPlanInSameFlushDeterminesCommercialLimit(): void
    {
        $this->member('one');
        $this->member('two');
        $this->em->flush();
        $newPlan = (new Plan())->setName('Three')->setCode('THREE')->setMaxCommerciaux(3);
        $this->em->persist($newPlan);
        $this->subscription->setPlan($newPlan);
        $this->member('three');
        $this->em->flush();
        self::assertSame(3, $this->counts()['max_commerciaux']);
    }

    public function testEnforcementUsesFreshPlanDespiteManagedEntityBeingStale(): void
    {
        // Simulate a committed plan update that the identity map has not reloaded.
        $this->em->getConnection()->update('billing_plan', ['seat_limits' => '{"commerciaux":1}'], ['id' => $this->plan->getId()]);
        self::assertSame(2, $this->plan->getMaxCommerciaux());
        $this->member('one');
        $this->member('two');
        $this->assertQuotaRejected();
    }

    public function testTransferUsesFreshMembershipRolesAndStatusFromSourceOrganism(): void
    {
        $other = (new Entite())->setNom('Source')->setCreateur($this->owner)->setPublic(false);
        $this->em->persist($other);
        $moving = $this->member('moving', [UtilisateurEntite::TENANT_FORMATEUR], UtilisateurEntite::STATUS_SUSPENDED, $other);
        $this->member('one');
        $this->member('two');
        $this->em->flush();
        // A concurrent request changed attributes that this request will not write.
        $this->em->getConnection()->update('utilisateur_entite', [
            'roles' => '["TENANT_COMMERCIAL"]', 'status' => UtilisateurEntite::STATUS_ACTIVE,
        ], ['id' => $moving->getId()]);
        $moving->setEntite($this->tenant);
        $this->assertQuotaRejected();
        self::assertSame($other->getId(), (int) $this->em->getConnection()->fetchOne('SELECT entite_id FROM utilisateur_entite WHERE id = ?', [$moving->getId()]));
        self::assertSame(2, $this->counts()['max_commerciaux']);
    }

    public function testConcurrentTransferRejectsRoleMutationFromStaleTenantRequest(): void
    {
        $other = (new Entite())->setNom('Source')->setCreateur($this->owner)->setPublic(false);
        $this->em->persist($other);
        $moving = $this->member('moving', [UtilisateurEntite::TENANT_FORMATEUR], tenant: $other);
        $this->member('one');
        $this->member('two');
        $this->em->flush();
        $this->em->getConnection()->update('utilisateur_entite', ['entite_id' => $this->tenant->getId()], ['id' => $moving->getId()]);
        $moving->addRole(UtilisateurEntite::TENANT_COMMERCIAL);
        try {
            $this->em->flush();
            self::fail('A stale request must reload the organism before editing.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame(2, $this->counts()['max_commerciaux']);
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testConcurrentTransferRejectsDeletionFromStaleTenantRequest(): void
    {
        $other = (new Entite())->setNom('Destination')->setCreateur($this->owner)->setPublic(false);
        $this->em->persist($other);
        $moving = $this->member('moving');
        $this->em->flush();
        $this->em->getConnection()->update('utilisateur_entite', ['entite_id' => $other->getId()], ['id' => $moving->getId()]);
        $this->em->remove($moving);
        try {
            $this->em->flush();
            self::fail('A stale request must not delete another organism’s membership.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite WHERE id = ?', [$moving->getId()]));
    }

    public function testConcurrentSubscriptionTransferRejectsStaleChange(): void
    {
        $other = (new Entite())->setNom('Subscription destination')->setCreateur($this->owner)->setPublic(false);
        $this->em->persist($other);
        $this->em->flush();
        $this->em->getConnection()->update('billing_entite_subscription', ['entite_id' => $other->getId()], ['id' => $this->subscription->getId()]);
        $this->subscription->setStatus('trialing');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->em->flush();
    }

    public function testPublishedUnlimitedUsersAndTrainersIgnoreStaleLegacyAliases(): void
    {
        $this->plan->setMaxUtilisateurs(0)->setMaxFormateurs(0)->setSeatLimits(['admins' => 1, 'formateurs' => 1]);
        $this->em->flush();
        foreach (['one', 'two', 'three'] as $name) $this->member($name, [UtilisateurEntite::TENANT_ADMIN, UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        self::assertSame(3, $this->counts()['max_utilisateurs']);
        self::assertSame(3, $this->counts()['max_formateurs']);
        self::assertSame(1, self::getContainer()->get(EntitlementService::class)->limits($this->tenant)['max_commerciaux']);
    }

    public function testBillingPageShowsExistingCommercialAccountsAndCapacity(): void
    {
        $admin = (new UtilisateurEntite())->setEntite($this->tenant)->setUtilisateur($this->owner)
            ->setCreateur($this->owner)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $this->owner->setEntite($this->tenant)->addUtilisateurEntite($admin);
        $this->em->persist($admin);
        $this->member('commercial');
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->owner);
        $url = self::getContainer()->get('router')->generate('app_administrateur_billing', ['_locale' => 'fr', 'entite' => $this->tenant->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $card = $crawler->filter('section[aria-label="Consommation de votre abonnement"] .kpi-card')->reduce(
            static fn ($node) => str_contains($node->text(), 'Comptes commerciaux actifs')
        );
        self::assertCount(1, $card);
        self::assertSame('1 / 2', $card->filter('.kpi-value')->text());
        self::assertStringContainsString('1 disponible(s)', $card->text());
    }
}
