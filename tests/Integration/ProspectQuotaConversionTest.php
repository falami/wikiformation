<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Utilisateur, UtilisateurEntite, Prospect, ProspectInteraction, EmailLog};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Exception\BillingQuotaExceededException;
use App\Service\Prospect\ProspectConverter;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProspectQuotaConversionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;
    private Utilisateur $admin;
    private Entite $entite;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@prospect-quota.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $this->entite = (new Entite())->setNom('Conversion')->setPublic(false)->setCreateur($this->admin);
        $plan = (new Plan())->setCode('CONVERSION_QUOTA')->setName('Conversion quota')->setMaxApprenantsAn(1)->setMaxProspects(5)->setMaxEntreprises(1);
        foreach ([$this->admin, $this->entite, $plan] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setPlan($plan)->setStatus(EntiteSubscription::STATUS_ACTIVE));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function prospect(bool $company): Prospect
    {
        $prospect = (new Prospect())->setEntite($this->entite)->setCreateur($this->admin)->setEmail('new@prospect-quota.test')->setNom('Prospect')->setPrenom('Nouveau');
        if ($company) {
            $enterprise = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Entreprise cliente');
            $this->em->persist($enterprise);
            $prospect->setLinkedEntreprise($enterprise);
        }
        $log = (new EmailLog())->setEntite($this->entite)->setCreateur($this->admin)->setProspect($prospect)->setToEmail($prospect->getEmail())->setSubject('Historique');
        $interaction = (new ProspectInteraction())->setEntite($this->entite)->setCreateur($this->admin)->setProspect($prospect)->setTitle('Premier contact');
        foreach ([$prospect, $log, $interaction] as $entity) $this->em->persist($entity);
        $this->em->flush();

        return $prospect;
    }

    public static function targets(): iterable
    {
        yield 'individual' => [false];
        yield 'company contact' => [true];
    }

    #[DataProvider('targets')]
    public function testConversionCreatesAccountAndAnnualUsageWithItsHistory(bool $company): void
    {
        $prospect = $this->prospect($company);
        self::getContainer()->get(ProspectConverter::class)->convert($this->entite, $prospect, $this->admin);
        $user = $prospect->getLinkedUser();
        self::assertNotNull($user?->getId());
        self::assertFalse($prospect->isActive());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Billing\LearnerYearUsage::class)->count(['entite' => $this->entite]));
        self::assertSame($user->getId(), (int) $this->em->getConnection()->fetchOne('SELECT to_user_id FROM email_log'));
        self::assertSame($user->getId(), (int) $this->em->getConnection()->fetchOne('SELECT utilisateur_id FROM prospect_interaction'));
        self::getContainer()->get(ProspectConverter::class)->convert($this->entite, $prospect, $this->admin);
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    #[DataProvider('targets')]
    public function testFullLearnerQuotaRollsBackAccountConversionAndHistory(bool $company): void
    {
        $prospect = $this->prospect($company);
        $existing = (new Utilisateur())->setEmail('existing@prospect-quota.test')->setPassword('unused')->setNom('Existing')->setPrenom('Learner');
        $this->em->persist($existing);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($existing)->setEntite($this->entite)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]));
        $this->em->flush();
        try {
            self::getContainer()->get(ProspectConverter::class)->convert($this->entite, $prospect, $this->admin);
            self::fail('A full yearly learner quota must reject conversion.');
        } catch (BillingQuotaExceededException $error) {
            self::assertSame('max_apprenants_an', $error->getQuotaKey());
        }
        $db = $this->em->getConnection();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM utilisateur'));
        self::assertSame(1, (int) $db->fetchOne('SELECT is_active FROM prospect'));
        self::assertNull($db->fetchOne('SELECT linked_user_id FROM prospect'));
        self::assertNull($db->fetchOne('SELECT to_user_id FROM email_log'));
        self::assertNull($db->fetchOne('SELECT utilisateur_id FROM prospect_interaction'));
    }
}
