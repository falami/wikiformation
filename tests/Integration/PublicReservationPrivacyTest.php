<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Formation, PublicHost, Reservation, Session, Site, Utilisateur};
use App\Enum\{StatusReservation, StatusSession};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicReservationPrivacyTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $originalDatabase;
    private Formation $training;
    private Entite $provider;
    private Entite $otherProvider;
    private Utilisateur $visitor;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->visitor = (new Utilisateur())->setEmail('visitor@example.test')->setNom('Visiteur')->setPrenom('Test')->setPassword('not-a-real-password');
        $em->persist($this->visitor);
        $this->provider = (new Entite())->setNom('Organisme destinataire')->setCreateur($this->visitor)->setPublic(true);
        $this->otherProvider = (new Entite())->setNom('Autre organisme du compte')->setCreateur($this->visitor)->setPublic(false);
        $em->persist($this->provider); $em->persist($this->otherProvider); $em->flush();
        $this->visitor->setEntite($this->otherProvider);
        $this->training = (new Formation())->setTitre('Formation publique')->setSlug('reservation-privacy-training')->setEntite($this->provider)->setCreateur($this->visitor)->setIsPublic(true);
        $em->persist($this->training); $em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->visitor);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $scope) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $scope]['DATABASE_URL']);
            else $GLOBALS['_' . $scope]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    private function url(string $suffix = ''): string
    {
        return '/fr/formation/' . $this->training->getSlug() . '/reservation' . $suffix;
    }

    private function session(Formation $training, Entite $organization, StatusSession $status = StatusSession::PUBLISHED): Session
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $organization = $em->find(Entite::class, $organization->getId());
        $training = $em->find(Formation::class, $training->getId());
        $creator = $em->find(Utilisateur::class, $this->visitor->getId());
        $site = (new Site())->setNom('Salle test')->setSlug('room-' . uniqid())->setCreateur($creator)->setEntite($organization);
        $session = (new Session())->setCode('SESSION-' . uniqid())->setFormation($training)->setSite($site)->setEntite($organization)->setCreateur($creator)->setStatus($status);
        $em->persist($site); $em->persist($session); $em->flush();
        return $session;
    }

    public function testReservationBelongsToTheTrainingProviderAndIdentifiesTheRecipient(): void
    {
        $page = $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Organisme destinataire', $page->filter('[data-privacy-notice="reservation"]')->text());
        $form = $page->filter('form[name="reservation"]')->form([
            'reservation[places]' => '2', 'reservation[dateSouhaitee]' => '', 'reservation[commentaire]' => 'Disponibilité le lundi.',
        ]);
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $reservations = self::getContainer()->get('doctrine')->getRepository(Reservation::class)->findAll();
        self::assertCount(1, $reservations);
        self::assertSame($this->provider->getId(), $reservations[0]->getEntite()->getId());
        self::assertNotSame($this->visitor->getEntite()->getId(), $reservations[0]->getEntite()->getId());
        self::assertSame($this->visitor->getId(), $reservations[0]->getUtilisateur()->getId());
        self::assertSame(StatusReservation::WAITING_LIST, $reservations[0]->getStatus());
    }

    public function testPrivateAndExcludedTrainingsCannotBeReadOrSubmitted(): void
    {
        $this->client->catchExceptions(true);
        $em = self::getContainer()->get('doctrine')->getManager();
        foreach ([[false, false], [true, true]] as [$public, $excluded]) {
            $em->find(Formation::class, $this->training->getId())->setIsPublic($public)->setExcludeFromGlobalCatalogue($excluded); $em->flush();
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, $this->url(), ['reservation' => ['places' => 1]]);
                self::assertSame(404, $this->client->getResponse()->getStatusCode());
                self::assertStringNotContainsString('Formation publique', $this->client->getResponse()->getContent());
            }
        }
        self::assertCount(0, $em->getRepository(Reservation::class)->findAll());
    }

    public function testWhiteLabelCannotReserveAnotherProvidersTraining(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $host = (new PublicHost())->setHost('provider.example.test')->setName('Autre organisme')->setEntite($this->otherProvider)->setShowAllPublicFormations(false);
        $em->persist($host); $em->flush();
        $this->client->catchExceptions(true);
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, 'http://provider.example.test' . $this->url(), ['reservation' => ['places' => 1]]);
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
        }
        self::assertCount(0, $em->getRepository(Reservation::class)->findAll());
        $em->find(PublicHost::class, $host->getId())->setEntite($em->find(Entite::class, $this->provider->getId())); $em->flush();
        $this->client->request('GET', 'http://provider.example.test' . $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $em->find(PublicHost::class, $host->getId())->setRestrictToAssignedFormations(true); $em->flush();
        $this->client->request('GET', 'http://provider.example.test' . $this->url());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOnlyPublishedSessionsOfTheSameTrainingAndProviderAreExposed(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $otherTraining = (new Formation())->setTitre('Autre formation')->setSlug('other-training')->setEntite($this->provider)->setCreateur($this->visitor)->setIsPublic(true);
        $em->persist($otherTraining); $em->flush();
        $invalid = [
            $this->session($otherTraining, $this->provider),
            $this->session($this->training, $this->otherProvider),
        ];
        foreach ([StatusSession::DRAFT, StatusSession::CANCELED, StatusSession::FULL] as $status) $invalid[] = $this->session($this->training, $this->provider, $status);
        $this->client->catchExceptions(true);
        foreach (array_merge([0, 999999], array_map(fn(Session $session): int => $session->getId(), $invalid)) as $id) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, $this->url('?session=' . $id), ['reservation' => ['places' => 1]]);
                self::assertSame(404, $this->client->getResponse()->getStatusCode());
            }
        }
        self::assertCount(0, $em->getRepository(Reservation::class)->findAll());
        $valid = $this->session($this->training, $this->provider);
        $page = $this->client->request('GET', $this->url('?session=' . $valid->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('form[name="reservation"]')->form(['reservation[places]' => '1']));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $saved = $em->getRepository(Reservation::class)->findAll();
        self::assertCount(1, $saved);
        self::assertSame($valid->getId(), $saved[0]->getSession()->getId());
        self::assertSame(StatusReservation::PENDING, $saved[0]->getStatus());
    }

    public function testCommentLengthAndCsrfAreValidatedOnTheServer(): void
    {
        $page = $this->client->request('GET', $this->url());
        $values = $page->filter('form[name="reservation"]')->form(['reservation[places]' => '1'])->getPhpValues();
        foreach ([['commentaire' => str_repeat('a', 5001)], ['_token' => 'invalid'], ['entite' => $this->otherProvider->getId()]] as $override) {
            $this->client->request('POST', $this->url(), ['reservation' => $override + $values['reservation']]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(0, self::getContainer()->get('doctrine')->getRepository(Reservation::class)->findAll());
        }
    }
}
