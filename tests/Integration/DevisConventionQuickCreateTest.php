<?php

namespace App\Tests\Integration;

use App\Entity\{Devis, Entite, Entreprise, Formation, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, EntiteUsageYear};
use App\Service\Sequence\{SequenceNumberManager, SessionNumberGenerator};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\DomCrawler\{Crawler, Form};

/** HTTP, validation et écritures uniquement dans une base SQLite en mémoire. */
final class DevisConventionQuickCreateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Entite $entite;
    private Devis $devis;
    private Utilisateur $user;
    private Site $site;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('admin@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Camille')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user);
        $this->em->persist($this->entite);
        $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->user->addUtilisateurEntite((new UtilisateurEntite())->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Formation test')->setSlug('formation-test');
        $this->site = (new Site())->setEntite($this->entite)->setCreateur($this->user)->setNom('Salle test')->setSlug('salle-test');
        $entreprise = (new Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Entreprise test');
        $this->devis = (new Devis())->setEntite($this->entite)->setCreateur($this->user)->setEntrepriseDestinataire($entreprise)->setFormation($formation)->setNumero('DEV-TEST');
        $subscription = (new EntiteSubscription())->setEntite($this->entite)->setStatus('active');
        foreach ([$formation, $this->site, $entreprise, $this->devis, $subscription] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $sequence = $this->createMock(SequenceNumberManager::class);
        $number = 0;
        $sequence->method('next')->willReturnCallback(static function () use (&$number) { return [2026, ++$number]; });
        self::getContainer()->set(SessionNumberGenerator::class, new SessionNumberGenerator($sequence));
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) {
                unset($GLOBALS['_' . $type]['DATABASE_URL']);
            } else {
                $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
            }
        }
    }

    public function testSiteCreationIsCsrfProtectedAndReplayDoesNotDuplicate(): void
    {
        $form = $this->openForm('site');
        $form->setValues(['devis_convention_site[nom]' => 'Nouveau centre', 'devis_convention_site[ville]' => 'Paris']);
        $this->client->submit($form);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $result = $this->response();
        self::assertSame('site', $result['kind']);
        $site = $this->em->find(Site::class, $result['id']);
        self::assertSame($this->entite->getId(), $site->getEntite()->getId());
        self::assertSame($this->user->getId(), $site->getCreateur()->getId());
        self::assertNotEmpty($site->getSlug());
        $this->client->submit($form);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($result['id'], $this->response()['id']);
        self::assertSame(2, $this->em->getRepository(Site::class)->count([]));
        $invalid = $this->openForm('site');
        $invalid->setValues(['devis_convention_site[nom]' => 'Sans CSRF', 'devis_convention_site[_token]' => 'invalid']);
        $this->client->submit($invalid);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('html', $this->response());
        self::assertSame(2, $this->em->getRepository(Site::class)->count([]));
    }

    public function testSessionCreationPersistsSlotsAndReturnsSelectionMetadata(): void
    {
        $form = $this->openForm('session');
        $form->setValues([
            'devis_convention_session[site]' => (string) $this->site->getId(),
            'devis_convention_session[capacite]' => '12',
            'devis_convention_session[jours][0][dateDebut]' => '2026-10-03T09:00',
            'devis_convention_session[jours][0][dateFin]' => '2026-10-03T17:00',
        ]);
        $this->client->submit($form);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $result = $this->response();
        self::assertSame(12, $result['remaining']);
        self::assertSame('6,5 heures', $result['duration']);
        self::assertSame('03/10/2026 à 09:00', $result['startLabel']);
        $session = $this->em->find(Session::class, $result['id']);
        self::assertSame($this->devis->getFormation()->getId(), $session->getFormation()->getId());
        self::assertSame($this->entite->getId(), $session->getEntite()->getId());
        self::assertSame($this->entite->getId(), $session->getJours()->first()->getEntite()->getId());
        self::assertSame($this->user->getId(), $session->getJours()->first()->getCreateur()->getId());
        $this->client->submit($form);
        self::assertSame($result['id'], $this->response()['id']);
        self::assertSame(1, $this->em->getRepository(Session::class)->count([]));
    }

    public function testSessionPausesKeepThreeFullDaysAtTwentyOneHoursAndAllowAnOverride(): void
    {
        $form = $this->openForm('session');
        self::assertArrayHasKey('pauseMinutes', $form->getPhpValues()['devis_convention_session']['jours'][0]);
        $values = $form->getPhpValues();
        $values['devis_convention_session']['site'] = (string) $this->site->getId();
        $values['devis_convention_session']['jours'] = [];
        foreach ([7, 8, 9] as $day) {
            $date = sprintf('2026-10-%02d', $day);
            $values['devis_convention_session']['jours'][] = ['dateDebut' => $date . 'T08:30', 'dateFin' => $date . 'T17:00', 'pauseMinutes' => ''];
        }
        $this->client->request('POST', $form->getUri(), $values);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        self::assertSame('21 heures', $this->response()['duration']);
        $session = $this->em->find(Session::class, $this->response()['id']);
        self::assertSame(21.0, $session->getDureeFormationHeures());
        self::assertNull($session->getJours()->first()->getPauseMinutes());

        $form = $this->openForm('session');
        $form->setValues([
            'devis_convention_session[site]' => (string) $this->site->getId(),
            'devis_convention_session[jours][0][dateDebut]' => '2026-10-12T08:30',
            'devis_convention_session[jours][0][dateFin]' => '2026-10-12T17:00',
            'devis_convention_session[jours][0][pauseMinutes]' => '0',
        ]);
        $this->client->submit($form);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        self::assertSame('8,5 heures', $this->response()['duration']);
        $session = $this->em->find(Session::class, $this->response()['id']);
        self::assertSame(0, $session->getJours()->first()->getPauseMinutes());
    }

    public function testSessionRejectsAPauseLongerThanTheSlotAndRetainsItsValue(): void
    {
        $form = $this->openForm('session');
        $form->setValues([
            'devis_convention_session[site]' => (string) $this->site->getId(),
            'devis_convention_session[jours][0][dateDebut]' => '2026-10-12T09:00',
            'devis_convention_session[jours][0][dateFin]' => '2026-10-12T12:30',
            'devis_convention_session[jours][0][pauseMinutes]' => '240',
        ]);
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('La pause doit être plus courte', $this->response()['html']);
        $crawler = new Crawler($this->response()['html']);
        self::assertSame('240', $crawler->filter('input[name$="[pauseMinutes]"]')->attr('value'));
        self::assertSame(0, $this->em->getRepository(Session::class)->count([]));
    }

    public function testSessionRejectsForeignSiteAndOverlappingDates(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $foreignSite = (new Site())->setEntite($other)->setCreateur($this->user)->setNom('Autre site')->setSlug('autre-site');
        $this->em->persist($other);
        $this->em->persist($foreignSite);
        $this->em->flush();
        $form = $this->openForm('session');
        $values = $form->getPhpValues();
        $values['devis_convention_session']['site'] = (string) $foreignSite->getId();
        $values['devis_convention_session']['jours'] = [
            ['dateDebut' => '2026-10-03T09:00', 'dateFin' => '2026-10-03T12:00'],
            ['dateDebut' => '2026-10-03T11:00', 'dateFin' => '2026-10-03T17:00'],
        ];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('chevaucher', $this->response()['html']);
        self::assertSame(0, $this->em->getRepository(Session::class)->count([]));
        $values['devis_convention_session']['jours'] = [['dateDebut' => '', 'dateFin' => '']];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testNewClientIsLinkedToCompanyAndReceivesTenantStagiaireRole(): void
    {
        $form = $this->openForm('client');
        $form->setValues(['devis_convention_client[prenom]' => 'Alex', 'devis_convention_client[nom]' => 'Durand', 'devis_convention_client[email]' => 'Alex.Durand@example.test']);
        $this->client->submit($form);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $result = $this->response();
        $client = $this->em->find(Utilisateur::class, $result['id']);
        self::assertSame('alex.durand@example.test', $client->getEmail());
        self::assertSame($this->devis->getEntrepriseDestinataire()->getId(), $client->getEntreprise()->getId());
        self::assertNotEmpty($client->getPassword());
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $client, 'entite' => $this->entite]);
        self::assertTrue($membership->hasRole(UtilisateurEntite::TENANT_STAGIAIRE));
        self::assertSame(1, $this->em->getRepository(EntiteUsageYear::class)->findOneBy(['entite' => $this->entite])->getApprenantsCount());
        $secondForm = $this->openForm('client');
        $secondForm->setValues(['devis_convention_client[prenom]' => 'Autre prénom', 'devis_convention_client[nom]' => 'Autre nom', 'devis_convention_client[email]' => $client->getEmail()]);
        $this->client->submit($secondForm);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->response()['already']);
        self::assertSame('Alex', $client->getPrenom());
        self::assertSame(1, $this->em->getRepository(EntiteUsageYear::class)->findOneBy(['entite' => $this->entite])->getApprenantsCount());
    }

    public function testQuoteFromAnotherTenantIsRejected(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $this->em->flush();
        $this->client->catchExceptions(true);
        $url = self::getContainer()->get('router')->generate('app_administrateur_devis_convention_quick_create', ['entite' => $other->getId(), 'id' => $this->devis->getId(), 'kind' => 'site']);
        $this->client->request('GET', $url);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testClientQuotaFailureDoesNotCreateAnAccount(): void
    {
        $usage = new EntiteUsageYear($this->entite, (int) date('Y'));
        $usage->increment(50);
        $this->em->persist($usage);
        $this->em->flush();
        $form = $this->openForm('client');
        $form->setValues(['devis_convention_client[prenom]' => 'Alex', 'devis_convention_client[nom]' => 'Durand', 'devis_convention_client[email]' => 'quota@example.test']);
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Quota apprenants', $this->response()['html']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur WHERE email = ?', ['quota@example.test']));
    }

    public function testForeignClientIsNotExposedOrAttachedByEmail(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $foreign = (new Utilisateur())->setEntite($other)->setNom('Confidentiel')->setPrenom('Identité')->setEmail('foreign@example.test')->setPassword('unused');
        $this->em->persist($other);
        $this->em->persist($foreign);
        $this->em->flush();
        $form = $this->openForm('client');
        $form->setValues(['devis_convention_client[prenom]' => 'Alex', 'devis_convention_client[nom]' => 'Durand', 'devis_convention_client[email]' => 'foreign@example.test']);
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Confidentiel', $this->response()['html']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite WHERE utilisateur_id = ? AND entite_id = ?', [$foreign->getId(), $this->entite->getId()]));
    }

    public function testStagiaireCannotUseCreationEndpoints(): void
    {
        $this->user->setRoles(['ROLE_USER']);
        $this->user->getUtilisateurEntites()->first()->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->catchExceptions(true);
        foreach (['session', 'site', 'client'] as $kind) {
            $url = self::getContainer()->get('router')->generate('app_administrateur_devis_convention_quick_create', ['entite' => $this->entite->getId(), 'id' => $this->devis->getId(), 'kind' => $kind]);
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
    }

    private function openForm(string $kind): Form
    {
        $url = self::getContainer()->get('router')->generate('app_administrateur_devis_convention_quick_create', ['entite' => $this->entite->getId(), 'id' => $this->devis->getId(), 'kind' => $kind]);
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        return (new Crawler($this->response()['html'], 'http://localhost' . $url))->filter('form')->form();
    }

    private function response(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
