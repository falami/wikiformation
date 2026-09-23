<?php

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Devis, Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class DevisListTrainingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Formation $formation;
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
        $this->admin = (new Utilisateur())->setEmail('quotes-list@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Habilitation électrique')->setSlug('habilitation-electrique');
        $this->site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->em->persist($this->formation);
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

    public function testListsAllLinkedSessionsOnceAndSupportsSearchWithoutBreakingPagination(): void
    {
        $devis = $this->quote('DEV-MULTIPLE')->setFormation($this->formation)->setMontantTtcCents(50000);
        $first = $this->session('SES-OCT', ['2026-10-07', '2026-10-09']);
        $second = $this->session('SES-NOV', ['2026-11-10']);
        $unplanned = $this->session('SES-UNPLANNED', []);
        foreach ([$first, $first, $second, $unplanned] as $index => $session) {
            $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setDevis($devis)->setNumero('CONV-' . $index)->setIntituleFormation('H0B0 sur mesure');
            $this->em->persist($convention);
        }
        $this->em->persist((new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($first)->addDevi($devis));
        $this->quote('DEV-PLAIN')->setFormation($this->formation)->setMontantTtcCents(10000);
        $this->quote('DEV-FOREIGN', $this->other)->setFormation($this->formation);
        $this->em->flush();
        $client = $this->client();
        $data = $this->table($client, ['search' => ['value' => 'H0B0'], 'length' => 1]);
        self::assertSame(2, $data['recordsTotal']);
        self::assertSame(1, $data['recordsFiltered']);
        self::assertCount(1, $data['data']);
        self::assertSame("Habilitation électrique\nH0B0 sur mesure", $data['data'][0]['formation']);
        self::assertSame("SES-OCT · 07/10/2026 → 09/10/2026\nSES-NOV · 10/11/2026\nSES-UNPLANNED · À planifier", $data['data'][0]['dates']);
        self::assertStringContainsString('disabled', $data['data'][0]['actions']);
        $data = $this->table($client, ['search' => ['value' => 'Habilitation'], 'length' => 1, 'start' => 1, 'order' => [['column' => 0, 'dir' => 'desc']]]);
        self::assertSame(2, $data['recordsFiltered']);
        self::assertSame('DEV-MULTIPLE', $data['data'][0]['numero']);
        self::assertStringNotContainsString('FOREIGN', json_encode($data));
        $data = $this->table($client, ['length' => 1, 'order' => [['column' => 4, 'dir' => 'desc']]]);
        self::assertSame('DEV-MULTIPLE', $data['data'][0]['numero'], 'The TTC sort must use its new column index.');
        $client->request('GET', $this->url('app_administrateur_devis_index'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('<th>Formation</th>', $client->getResponse()->getContent());
        self::assertStringContainsString('<th>Dates de formation</th>', $client->getResponse()->getContent());
    }

    public function testDirectRegistrationsProvideFallbackTitleAndForeignAssociationsCannotLeak(): void
    {
        $devis = $this->quote('DEV-DIRECT');
        $session = $this->session('SES-DIRECT', ['2026-10-15']);
        $this->em->persist((new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($session)->addDevi($devis));
        $foreignFormation = (new Formation())->setEntite($this->other)->setCreateur($this->admin)->setTitre('CONFIDENTIEL')->setSlug('confidentiel');
        $foreignSession = (new Session())->setEntite($this->other)->setCreateur($this->admin)->setFormation($foreignFormation)->setSite($this->site)->setCode('SES-CONFIDENTIEL');
        $this->em->persist($foreignFormation);
        $this->em->persist($foreignSession);
        $this->em->persist((new ConventionContrat())->setEntite($this->other)->setCreateur($this->admin)->setSession($foreignSession)->setDevis($devis)->setNumero('CONV-FOREIGN')->setIntituleFormation('SECRET'));
        $this->em->persist((new Inscription())->setEntite($this->other)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($foreignSession)->addDevi($devis));
        $this->quote('DEV-UNPLANNED');
        $this->em->flush();
        $client = $this->client();
        $data = $this->table($client, ['search' => ['value' => 'Habilitation']]);
        self::assertSame(1, $data['recordsFiltered']);
        self::assertSame('Habilitation électrique', $data['data'][0]['formation']);
        self::assertSame('15/10/2026', $data['data'][0]['dates']);
        self::assertSame(0, $this->table($client, ['search' => ['value' => 'SECRET']])['recordsFiltered']);
        self::assertSame(0, $this->table($client, ['search' => ['value' => 'CONFIDENTIEL']])['recordsFiltered']);
        $data = $this->table($client, ['search' => ['value' => 'UNPLANNED']]);
        self::assertSame('—', $data['data'][0]['formation']);
        self::assertSame('Non planifiée', $data['data'][0]['dates']);
    }

    private function quote(string $numero, ?Entite $entite = null): Devis
    {
        $devis = (new Devis())->setEntite($entite ?? $this->entite)->setCreateur($this->admin)->setNumero($numero)->setDestinataire($this->admin);
        $this->em->persist($devis);
        return $devis;
    }

    private function session(string $code, array $dates): Session
    {
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setFormation($this->formation)->setSite($this->site)->setCode($code);
        $this->em->persist($session);
        foreach ($dates as $date) {
            $jour = (new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable($date . ' 08:30'))->setDateFin(new \DateTimeImmutable($date . ' 17:00'));
            $session->addJour($jour);
        }
        return $session;
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()]);
    }

    private function table(KernelBrowser $client, array $parameters = []): array
    {
        $client->request('POST', $this->url('app_administrateur_devis_ajax'), $parameters);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
