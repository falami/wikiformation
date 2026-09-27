<?php

namespace App\Tests\Integration;

use App\Entity\{Engin, Entite, Formateur, Formation, Session, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Listing summaries must cover all matching records, even on a one-row page. */
final class ListingKpisTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Site $site;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('kpis@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('kpi-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle A')->setSlug('salle-a');
        $this->em->persist($this->site);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testFormationAveragesAreNotWeightedBySessionsOrPagination(): void
    {
        $courses = [];
        foreach ([['Cours A', 0, 1, 2], ['Cours B', 30000, 3, 1], ['Cours C', 60000, null, 0], ['Secret', 999999, 99, 4]] as $i => [$title, $price, $duration, $sessions]) {
            $tenant = $i === 3 ? $this->other : $this->entite;
            $course = (new Formation())->setEntite($tenant)->setCreateur($this->admin)->setTitre($title)->setSlug('course-' . $i)->setPrixBaseCents($price)->setDuree($duration);
            $this->em->persist($course);
            for ($j = 0; $j < $sessions; ++$j) {
                $this->em->persist((new Session())->setEntite($tenant)->setCreateur($this->admin)->setFormation($course)->setSite($this->site)->setCode('KPI-C-' . $i . '-' . $j));
            }
            $courses[] = $course;
        }
        $this->em->flush();
        $client = $this->client();
        foreach ([0, 1, 2] as $start) {
            $json = $this->request($client, 'formation', ['start' => $start]);
            self::assertCount(1, $json['data']);
            self::assertSame(3, $json['recordsFiltered']);
            self::assertEquals(['total'=>3, 'sessions'=>3, 'averagePrice'=>300, 'averageDuration'=>2], $json['kpis']);
        }
        $filtered = $this->request($client, 'formation', ['search'=>['value'=>'Cours A']]);
        self::assertSame(1, $filtered['recordsFiltered']);
        self::assertEquals(['total'=>1, 'sessions'=>2, 'averagePrice'=>0, 'averageDuration'=>1], $filtered['kpis']);
        $empty = $this->request($client, 'formation', ['niveauFilter'=>'[]']);
        self::assertSame(['total'=>0, 'sessions'=>0, 'averagePrice'=>null, 'averageDuration'=>null], $empty['kpis']);
    }

    public function testTrainerKpisCountAssociationsWithoutJoinDuplicates(): void
    {
        $siteB = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle B')->setSlug('salle-b');
        $this->em->persist($siteB);
        $engins = [];
        foreach (['Engin A, spécial', 'Engin B'] as $name) {
            $engin = (new Engin())->setEntite($this->entite)->setCreateur($this->admin)->setSite($this->site)->setNom($name);
            $this->em->persist($engin);
            $engins[] = $engin;
        }
        foreach ([['Alpha',1,1], ['Beta',2,2], ['Secret',2,8]] as $i => [$name, $associations, $sessions]) {
            $tenant = $i === 2 ? $this->other : $this->entite;
            $user = (new Utilisateur())->setEmail(strtolower($name) . '@example.test')->setPassword('unused')->setPrenom($name)->setNom('Formateur');
            $this->em->persist($user);
            $trainer = (new Formateur())->setUtilisateur($user)->setEntite($tenant)->setCreateur($this->admin);
            for ($j = 0; $j < $associations; ++$j) {
                $trainer->addQualificationEngins($engins[$j]);
                $trainer->addSitePrefere([$this->site, $siteB][$j]);
            }
            $this->em->persist($trainer);
            for ($j = 0; $j < $sessions; ++$j) {
                $this->em->persist((new Session())->setEntite($tenant)->setCreateur($this->admin)->setFormateur($trainer)->setSite($this->site)->setCode('KPI-T-' . $i . '-' . $j));
            }
        }
        $this->em->flush();
        $siteBId = $siteB->getId();
        $client = $this->client();
        $ids = [];
        foreach ([0, 1] as $start) {
            $json = $this->request($client, 'formateur', ['start'=>$start]);
            self::assertCount(1, $json['data']);
            $ids[] = $json['data'][0]['id'];
            self::assertEquals(['total'=>2, 'sessions'=>3, 'averageQualifications'=>1.5, 'averageSites'=>1.5], $json['kpis']);
        }
        self::assertNotSame($ids[0], $ids[1], 'Each page contains a distinct trainer, even with several joined qualifications and sites.');
        $filtered = $this->request($client, 'formateur', ['siteFilter'=>(string)$siteBId]);
        self::assertSame(1, $filtered['recordsFiltered']);
        self::assertEquals(['total'=>1, 'sessions'=>2, 'averageQualifications'=>2, 'averageSites'=>2], $filtered['kpis']);
        $searched = $this->request($client, 'formateur', ['search'=>['value'=>'Alpha']]);
        self::assertEquals(['total'=>1, 'sessions'=>1, 'averageQualifications'=>1, 'averageSites'=>1], $searched['kpis']);
        $empty = $this->request($client, 'formateur', ['enginFilter'=>'[]']);
        self::assertSame(['total'=>0, 'sessions'=>0, 'averageQualifications'=>null, 'averageSites'=>null], $empty['kpis']);
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function request(KernelBrowser $client, string $listing, array $params = []): array
    {
        $url = self::getContainer()->get('router')->generate('app_administrateur_' . $listing . '_ajax', ['entite'=>$this->entite->getId()]);
        $client->request('POST', $url, $params + ['draw'=>1,'start'=>0,'length'=>1]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
