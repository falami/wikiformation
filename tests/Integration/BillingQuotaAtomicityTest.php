<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Formateur, Formation, Inscription, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, EntiteUsageYear, LearnerYearUsage, Plan};
use App\Entity\Elearning\{ElearningCourse, ElearningEnrollment};
use App\Enum\EnrollmentStatus;
use App\Enum\StatusInscription;
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\QuotaUsage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillingQuotaAtomicityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Plan $plan;
    private EntiteSubscription $subscription;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = $this->user('owner');
        $this->entite = $this->tenant('Atomicity');
        $this->plan = (new Plan())->setCode('ATOMICITY')->setName('Atomicity')->setMaxUtilisateurs(1)->setMaxFormateurs(1)->setMaxApprenantsAn(1);
        $this->em->persist($this->plan);
        $this->subscription = $this->subscribe($this->entite, $this->plan);
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
        $user = (new Utilisateur())->setEmail($name.'@atomicity.test')->setPassword('unused')->setNom($name)->setPrenom('Test');
        $this->em->persist($user);
        return $user;
    }

    private function tenant(string $name): Entite
    {
        $entity = (new Entite())->setNom($name)->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($entity);
        return $entity;
    }

    private function subscribe(Entite $entity, Plan $plan): EntiteSubscription
    {
        $subscription = (new EntiteSubscription())->setEntite($entity)->setPlan($plan)->setStatus(EntiteSubscription::STATUS_ACTIVE);
        $this->em->persist($subscription);
        return $subscription;
    }

    private function member(Utilisateur $user, array $roles, ?Entite $entity = null): UtilisateurEntite
    {
        $member = (new UtilisateurEntite())->setEntite($entity ?? $this->entite)->setUtilisateur($user)->setCreateur($this->admin)->setRoles($roles);
        $this->em->persist($member);
        return $member;
    }

    private function profile(Utilisateur $user): void
    {
        $this->em->persist((new Formateur())->setEntite($this->entite)->setUtilisateur($user)->setCreateur($this->admin));
    }

    private function annualCount(): int
    {
        $year = (int)(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y');
        return $this->em->getRepository(EntiteUsageYear::class)->findOneBy(['entite' => $this->entite, 'year' => $year])?->getApprenantsCount() ?? 0;
    }

    private function assertQuotaRejects(string $key): void
    {
        try {
            $this->em->flush();
            self::fail('The quota must reject this mutation.');
        } catch (BillingQuotaExceededException $error) {
            self::assertSame($key, $error->getQuotaKey());
        }
    }

    public function testNewTenantAndPendingSubscriptionCannotBypassSeatLimit(): void
    {
        $new = $this->tenant('Pending');
        $this->subscribe($new, $this->plan);
        $this->member($this->user('pending-one'), [UtilisateurEntite::TENANT_ADMIN], $new);
        $this->member($this->user('pending-two'), [UtilisateurEntite::TENANT_ADMIN], $new);
        $this->assertQuotaRejects('max_utilisateurs');
        self::assertNull($new->getId());
        self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testNewTenantAndPendingSubscriptionCannotBypassAnnualLimit(): void
    {
        $new = $this->tenant('Pending learners');
        $this->subscribe($new, $this->plan);
        $this->member($this->user('pending-student-one'), [UtilisateurEntite::TENANT_STAGIAIRE], $new);
        $this->member($this->user('pending-student-two'), [UtilisateurEntite::TENANT_STAGIAIRE], $new);
        $this->assertQuotaRejects('max_apprenants_an');
        self::assertNull($new->getId());
        self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
    }

    private function dormantTrainerAndReplacement(): UtilisateurEntite
    {
        $dormant = $this->user('dormant');
        $member = $this->member($dormant, [UtilisateurEntite::TENANT_FORMATEUR]);
        $member->setStatus(UtilisateurEntite::STATUS_INVITED);
        $this->profile($dormant);
        $this->member($this->user('replacement'), [UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        self::assertSame(1, self::getContainer()->get(QuotaUsage::class)->counts($this->entite)['max_formateurs']);
        return $member;
    }

    public function testDeletingInactiveMembershipCannotExposeExtraTrainerProfile(): void
    {
        $member = $this->dormantTrainerAndReplacement();
        $this->em->remove($member);
        $this->assertQuotaRejects('max_formateurs');
        self::assertSame(2, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
    }

    public function testMovingInactiveMembershipChecksSourceTenantToo(): void
    {
        $member = $this->dormantTrainerAndReplacement();
        $other = $this->tenant('Other');
        $this->subscribe($other, $this->plan);
        $this->em->flush();
        $member->setEntite($other);
        $this->assertQuotaRejects('max_formateurs');
        self::assertSame($this->entite->getId(), (int)$this->em->getConnection()->fetchOne('SELECT entite_id FROM utilisateur_entite WHERE id = ?', [$member->getId()]));
    }

    public function testPendingUpgradeAndDowngradeUseTheNewPlanAtFlush(): void
    {
        $larger = (new Plan())->setCode('LARGER')->setName('Larger')->setMaxUtilisateurs(2)->setMaxFormateurs(2);
        $this->em->persist($larger);
        $this->em->flush();
        $this->member($this->user('first-admin'), [UtilisateurEntite::TENANT_ADMIN]);
        $this->em->flush();
        $this->subscription->setPlan($larger);
        $this->member($this->user('second-admin'), [UtilisateurEntite::TENANT_ADMIN]);
        $this->em->flush();
        self::assertSame(2, self::getContainer()->get(QuotaUsage::class)->counts($this->entite)['max_utilisateurs']);
        $this->subscription->setPlan($this->plan);
        $this->member($this->user('third-admin'), [UtilisateurEntite::TENANT_ADMIN]);
        $this->assertQuotaRejects('max_utilisateurs');
        self::assertSame($larger->getId(), (int)$this->em->getConnection()->fetchOne('SELECT plan_id FROM billing_entite_subscription WHERE id = ?', [$this->subscription->getId()]));
    }

    public function testQuotaRefusalPreservesCallerTransactionAndPriorWrites(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->member($this->user('first'), [UtilisateurEntite::TENANT_ADMIN]);
        $this->em->flush();
        $this->member($this->user('excess'), [UtilisateurEntite::TENANT_ADMIN]);
        $this->assertQuotaRejects('max_utilisateurs');
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(1, (int)$connection->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
        $connection->rollBack();
        self::assertSame(0, (int)$connection->fetchOne('SELECT COUNT(*) FROM utilisateur_entite'));
    }

    public function testSqlFailureRollsBackAnnualLedgerAndReleasesOwnedTransaction(): void
    {
        $this->plan->setMaxApprenantsAn(0);
        $this->em->flush();
        $one = $this->user('duplicate');
        $two = $this->user('duplicate');
        $this->member($one, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->member($two, [UtilisateurEntite::TENANT_STAGIAIRE]);
        try {
            $this->em->flush();
            self::fail('The duplicate account must fail.');
        } catch (UniqueConstraintViolationException) {
            self::assertFalse($this->em->isOpen());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        foreach (['utilisateur_entite', 'billing_learner_year_usage', 'billing_entite_usage_year'] as $table) {
            self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
    }

    public function testReactivationAndRoleRemovalDoNotRefundOrDuplicateAnnualConsumption(): void
    {
        $member = $this->member($this->user('student'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $member->setStatus(UtilisateurEntite::STATUS_INVITED);
        $this->em->flush();
        $member->setStatus(UtilisateurEntite::STATUS_ACTIVE);
        $this->em->flush();
        $member->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        $member->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        self::assertSame(1, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
        $this->member($this->user('extra'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->assertQuotaRejects('max_apprenants_an');
    }

    public function testCanceledRegistrationConsumesOnlyWhenActivated(): void
    {
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Test')->setSlug('test');
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Test')->setSlug('test');
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode('TEST')->setFormation($formation)->setSite($site)->setCapacite(10);
        foreach ([$formation, $site, $session] as $entity) $this->em->persist($entity);
        $registration = (new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->user('registered'))->setStatus(StatusInscription::ANNULE);
        $this->em->persist($registration);
        $this->em->flush();
        self::assertSame(0, $this->annualCount());
        $registration->setStatus(StatusInscription::PREINSCRIT);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        $registration->setStatus(StatusInscription::ANNULE);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
    }

    public function testDeletedPersonalAccountRetainsAnnualConsumption(): void
    {
        $user = $this->user('deleted-student');
        $member = $this->member($user, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $this->em->remove($member);
        $this->em->remove($user);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        self::assertSame(1, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT utilisateur_id FROM billing_learner_year_usage'));
        $this->member($this->user('replacement-student'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->assertQuotaRejects('max_apprenants_an');
    }

    public function testConcurrentSuspensionIsPreservedWhenAddingLearnerRole(): void
    {
        $member = $this->member($this->user('stale-status'), [UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        $this->em->getConnection()->update('utilisateur_entite', ['status' => UtilisateurEntite::STATUS_SUSPENDED], ['id' => $member->getId()]);
        $member->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        self::assertSame(0, $this->annualCount());
        self::assertSame(UtilisateurEntite::STATUS_SUSPENDED, $this->em->getConnection()->fetchOne('SELECT status FROM utilisateur_entite WHERE id = ?', [$member->getId()]));
        $this->em->refresh($member);
        $member->setStatus(UtilisateurEntite::STATUS_ACTIVE);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
    }

    public function testConcurrentEnrollmentMoveRequiresReloadBeforeReactivation(): void
    {
        $enrollment = $this->elearning($this->user('moved-online'), 'moved-online', EnrollmentStatus::SUSPENDED);
        $other = $this->tenant('Concurrent destination');
        $this->subscribe($other, $this->plan);
        $this->member($this->user('destination-full'), [UtilisateurEntite::TENANT_STAGIAIRE], $other);
        $this->em->flush();
        $this->em->getConnection()->update('elearning_enrollment', ['entite_id' => $other->getId()], ['id' => $enrollment->getId()]);
        $enrollment->setStatus(EnrollmentStatus::ACTIVE);
        try {
            $this->em->flush();
            self::fail('A stale tenant must require reloading before quota validation.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame(EnrollmentStatus::SUSPENDED->value, $this->em->getConnection()->fetchOne('SELECT status FROM elearning_enrollment WHERE id = ?', [$enrollment->getId()]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testConcurrentLearnerReplacementCannotReuseOldPersonsAnnualSeat(): void
    {
        $old = $this->user('old-person');
        $this->member($old, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $new = $this->user('new-person');
        $enrollment = $this->elearning($old, 'reassigned-online', EnrollmentStatus::SUSPENDED);
        $this->em->flush();
        $this->em->getConnection()->update('elearning_enrollment', ['stagiaire_id' => $new->getId()], ['id' => $enrollment->getId()]);
        $enrollment->setStatus(EnrollmentStatus::ACTIVE);
        $this->assertQuotaRejects('max_apprenants_an');
        self::assertSame(1, $this->annualCount());
    }

    private function elearning(Utilisateur $user, string $slug, EnrollmentStatus $status = EnrollmentStatus::ACTIVE): ElearningEnrollment
    {
        $course = (new ElearningCourse())->setEntite($this->entite)->setCreateur($this->admin)->setTitre($slug)->setSlug($slug);
        $enrollment = (new ElearningEnrollment())->setEntite($this->entite)->setCreateur($this->admin)->setCourse($course)->setStagiaire($user)->setStatus($status);
        $this->em->persist($course);
        $this->em->persist($enrollment);
        return $enrollment;
    }

    public function testDirectElearningEnrollmentConsumesAnnualQuota(): void
    {
        $this->elearning($this->user('online'), 'online');
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        $this->elearning($this->user('online-extra'), 'online-extra');
        $this->assertQuotaRejects('max_apprenants_an');
    }

    public function testMembershipAndElearningShareTheSameAnnualSeat(): void
    {
        $user = $this->user('hybrid');
        $this->member($user, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->elearning($user, 'hybrid-one');
        $this->em->flush();
        $this->elearning($user, 'hybrid-two', EnrollmentStatus::UPCOMING);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        self::assertSame(1, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
    }

    public function testSuspendedElearningEnrollmentIsCheckedUponReactivation(): void
    {
        $enrollment = $this->elearning($this->user('suspended-online'), 'suspended', EnrollmentStatus::SUSPENDED);
        $this->em->flush();
        self::assertSame(0, $this->annualCount());
        $this->member($this->user('full-quota'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $enrollment->setStatus(EnrollmentStatus::ACTIVE);
        $this->assertQuotaRejects('max_apprenants_an');
    }

    public function testPreviousYearLedgerDoesNotPreventCurrentYearElearningConsumption(): void
    {
        $user = $this->user('returning-online');
        $year = (int)(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y');
        $this->em->persist(new LearnerYearUsage($this->entite, $user, $year - 1));
        $this->em->flush();
        $this->elearning($user, 'returning');
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        self::assertSame(2, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
    }

    public function testInvitedLearnerConsumesOnlyWhenActivated(): void
    {
        $member = $this->member($this->user('invited'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $member->setStatus(UtilisateurEntite::STATUS_INVITED);
        $this->em->flush();
        self::assertSame(0, $this->annualCount());
        $member->setStatus(UtilisateurEntite::STATUS_ACTIVE);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
    }

    public function testAddingLearnerRoleToExistingAccountConsumesAnnualSeat(): void
    {
        $member = $this->member($this->user('becomes-student'), [UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->flush();
        self::assertSame(0, $this->annualCount());
        $member->setRoles([UtilisateurEntite::TENANT_FORMATEUR, UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
    }

    public function testReplacingMembershipUserChecksTheNewLearnerAgainstAnnualLimit(): void
    {
        $member = $this->member($this->user('original-student'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $member->setUtilisateur($this->user('new-student'));
        $this->assertQuotaRejects('max_apprenants_an');
        self::assertSame(1, $this->annualCount());
    }

    public function testMovingLearnerConsumesDestinationAnnualQuotaWithoutRefundingSource(): void
    {
        $member = $this->member($this->user('moving-student'), [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $other = $this->tenant('Destination');
        $this->subscribe($other, $this->plan);
        $this->em->flush();
        $member->setEntite($other);
        $this->em->flush();
        self::assertSame(1, $this->annualCount());
        self::assertSame(1, (int)$this->em->getConnection()->fetchOne('SELECT apprenants_count FROM billing_entite_usage_year WHERE entite_id = ?', [$other->getId()]));
        self::assertSame(2, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
    }
}
