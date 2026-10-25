<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite, Formateur, Entreprise, Prospect};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\QuotaUsage;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillingQuotaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Plan $plan;
    private array $database;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@quota.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $this->entite = (new Entite())->setNom('Quotas')->setCreateur($this->admin)->setPublic(false);
        $this->plan = (new Plan())->setCode('QUOTA_TEST')->setName('Quota test')->setMaxUtilisateurs(5)->setMaxFormateurs(1)->setMaxProspects(1)->setMaxEntreprises(1);
        foreach ([$this->admin, $this->entite, $this->plan] as $object) $this->em->persist($object);
        $this->em->flush();
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setPlan($this->plan)->setStatus('active'));
        $this->em->flush();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $index=>$type) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']); else $GLOBALS['_'.$type]['DATABASE_URL']=$this->database[$index];
        }
    }
    private function user(string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($name.'@quota.test')->setPassword('unused')->setNom($name)->setPrenom('Test');
        $this->em->persist($user); return $user;
    }
    private function member(Utilisateur $user, array $roles): UtilisateurEntite
    {
        $member=(new UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($user)->setCreateur($this->admin)->setRoles($roles);
        $this->em->persist($member); return $member;
    }
    private function counts(): array { return self::getContainer()->get(QuotaUsage::class)->counts($this->entite); }
    public function testProfileAndMembershipCountOneTrainerAndLimitDirectProfiles(): void
    {
        $user=$this->user('trainer'); $this->member($user,[UtilisateurEntite::TENANT_FORMATEUR]);
        $this->em->persist((new Formateur())->setEntite($this->entite)->setUtilisateur($user)->setCreateur($this->admin));
        $this->em->flush();
        self::assertSame(1,$this->counts()['max_formateurs']);
        $this->em->persist((new Formateur())->setEntite($this->entite)->setUtilisateur($this->user('second'))->setCreateur($this->admin));
        $this->expectException(BillingQuotaExceededException::class); $this->em->flush();
    }
    public function testBatchProspectsCannotBypassQuota(): void
    {
        foreach (['A','B'] as $name) $this->em->persist((new Prospect())->setEntite($this->entite)->setCreateur($this->admin)->setNom($name));
        $this->expectException(BillingQuotaExceededException::class); $this->em->flush();
    }
    public function testCompanyCountTracksDeletionAndOtherTenantDoesNotConsume(): void
    {
        $company=(new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('A'); $this->em->persist($company); $this->em->flush();
        self::assertSame(1,$this->counts()['max_entreprises']);
        $this->em->remove($company); $this->em->flush(); self::assertSame(0,$this->counts()['max_entreprises']);
        $other=(new Entite())->setNom('Other')->setCreateur($this->admin)->setPublic(false);$this->em->persist($other);$this->em->flush();
        $this->em->persist((new Entreprise())->setEntite($other)->setCreateur($this->admin)->setRaisonSociale('Other'));$this->em->flush();
        self::assertSame(0,$this->counts()['max_entreprises']);
    }
    public function testRoleChangeAndReactivationAreChecked(): void
    {
        $one=$this->member($this->user('one'),[UtilisateurEntite::TENANT_FORMATEUR]);$this->em->flush();
        $two=$this->member($this->user('two'),[UtilisateurEntite::TENANT_STAGIAIRE]);$this->em->flush();
        $two->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
        $this->expectException(BillingQuotaExceededException::class);$this->em->flush();
    }
    public function testUnlimitedStillCountsAndEditingAnOverQuotaRecordIsAllowed(): void
    {
        $this->plan->setMaxProspects(0);$this->em->flush();
        foreach (['A','B'] as $name) {$p=(new Prospect())->setEntite($this->entite)->setCreateur($this->admin)->setNom($name);$this->em->persist($p);}
        $this->em->flush(); self::assertSame(2,$this->counts()['max_prospects']);
        $this->plan->setMaxProspects(1);$this->em->flush();$p->setNom('Updated');$this->em->flush();
        self::assertSame(2,$this->counts()['max_prospects']);
    }
    private function annualCount(): int
    {
        $year=(int)(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Paris')))->format('Y');
        return $this->em->getRepository(\App\Entity\Billing\EntiteUsageYear::class)->findOneBy(['entite'=>$this->entite,'year'=>$year])?->getApprenantsCount() ?? 0;
    }
    public function testLearnerIsCountedOnceWithMembershipAndMultipleRegistrations(): void
    {
        $this->plan->setMaxApprenantsAn(1);$this->em->flush();
        $user=$this->user('learner'); $this->member($user,[UtilisateurEntite::TENANT_STAGIAIRE]);$this->em->flush();
        self::assertSame(1,$this->annualCount());
        $formation=(new \App\Entity\Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Training')->setSlug('training');
        $site=(new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('site');
        $this->em->persist($formation);$this->em->persist($site);
        foreach (['A','B'] as $code) {
            $session=(new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode($code)->setFormation($formation)->setSite($site)->setCapacite(10);
            $this->em->persist($session);
            $ins=(new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($user);
            $this->em->persist($ins);
        }
        $this->em->flush();self::assertSame(1,$this->annualCount());
        $this->em->remove($ins);$this->em->flush();self::assertSame(1,$this->annualCount());
    }
    public function testAnnualBatchLimitIsEnforcedBeforeAnyWrite(): void
    {
        $this->plan->setMaxApprenantsAn(1);$this->em->flush();
        $this->member($this->user('student-a'),[UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->member($this->user('student-b'),[UtilisateurEntite::TENANT_STAGIAIRE]);
        try {$this->em->flush();self::fail('Quota should reject batch');} catch (BillingQuotaExceededException $e) {self::assertSame('max_apprenants_an',$e->getQuotaKey());}
        self::assertSame(0,$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_learner_year_usage'));
    }
    public function testUnlimitedLearnersAreCountedAndPreviousYearDoesNotConsumeCurrentQuota(): void
    {
        $user=$this->user('yearly');$this->em->flush();
        $year=(int)date('Y');
        $this->em->persist(new \App\Entity\Billing\LearnerYearUsage($this->entite,$user,$year-1));$this->em->flush();
        $this->member($user,[UtilisateurEntite::TENANT_STAGIAIRE]);$this->em->flush();self::assertSame(1,$this->annualCount());
        $this->member($this->user('another'),[UtilisateurEntite::TENANT_STAGIAIRE]);$this->em->flush();self::assertSame(2,$this->annualCount());
    }
    public function testOnlyAdministratorsAndManagersConsumeUserSeats(): void
    {
        $this->plan->setMaxUtilisateurs(1)->setMaxFormateurs(5);$this->em->flush();
        $this->member($this->admin,[UtilisateurEntite::TENANT_ADMIN,UtilisateurEntite::TENANT_DIRIGEANT]);
        $this->member($this->user('teacher'),[UtilisateurEntite::TENANT_FORMATEUR]);
        $this->member($this->user('sales'),[UtilisateurEntite::TENANT_COMMERCIAL]);
        $this->em->flush();self::assertSame(1,$this->counts()['max_utilisateurs']);self::assertSame(1,$this->counts()['max_formateurs']);
        $this->member($this->user('extra-admin'),[UtilisateurEntite::TENANT_ADMIN]);
        $this->expectException(BillingQuotaExceededException::class);$this->em->flush();
    }
    public function testInactiveTrainerFreesPlaceAndReactivationCannotExceedLimit(): void
    {
        $member=$this->member($this->user('inactive'),[UtilisateurEntite::TENANT_FORMATEUR]);$this->em->flush();
        $member->setStatus(UtilisateurEntite::STATUS_INVITED);$this->em->flush();self::assertSame(0,$this->counts()['max_formateurs']);
        $this->member($this->user('replacement'),[UtilisateurEntite::TENANT_FORMATEUR]);$this->em->flush();
        $member->setStatus(UtilisateurEntite::STATUS_ACTIVE);$this->expectException(BillingQuotaExceededException::class);$this->em->flush();
    }

    public function testDirectRegistrationConsumesAnnualQuotaWithoutMembership(): void
    {
        $this->plan->setMaxApprenantsAn(1);$this->em->flush();
        $formation=(new \App\Entity\Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Direct')->setSlug('direct');
        $site=(new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('direct-site');
        $session=(new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode('DIRECT')->setFormation($formation)->setSite($site)->setCapacite(10);
        foreach ([$formation,$site,$session] as $item) $this->em->persist($item);
        $ins=(new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->user('direct'));
        $this->em->persist($ins);$this->em->flush();self::assertSame(1,$this->annualCount());
        $second=(new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->user('blocked-direct'));
        $this->em->persist($second);$this->expectException(BillingQuotaExceededException::class);$this->em->flush();
    }

}
