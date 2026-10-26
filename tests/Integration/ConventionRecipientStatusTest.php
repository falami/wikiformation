<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Entreprise, Inscription, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

final class ConventionRecipientStatusTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private array $database;
    private string $base;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $user = (new Utilisateur())->setEmail('recipient-status@company.test')->setPassword('unused')->setPrenom('Camille')->setNom('Client');
        $tenant = (new Entite())->setNom('Organisme')->setPublic(false)->setCreateur($user);
        $this->em->persist($user);
        $this->em->persist($tenant);
        $this->em->flush();
        $user->setEntite($tenant);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($tenant)->setCreateur($user)->setRoles(['TENANT_ENTREPRISE'])->setStatus('active');
        $plan = (new Plan())->setCode('RECIPIENT_STATUS')->setName('Recipient status fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(1)->setMaxEntreprises(2)->setMaxProspects(50);
        $subscription = (new EntiteSubscription())->setEntite($tenant)->setStatus('active')->setPlan($plan);
        $company = (new Entreprise())->setRaisonSociale('Client')->setEntite($tenant)->setCreateur($user)->setRepresentant($user);
        $otherCompany = (new Entreprise())->setRaisonSociale('Autre client')->setEntite($tenant)->setCreateur($user);
        $user->setEntreprise($company);
        $site = (new Site())->setNom('Salle')->setSlug('recipient-status')->setEntite($tenant)->setCreateur($user);
        $session = (new Session())->setCode('SES-RECIPIENT')->setEntite($tenant)->setCreateur($user)->setSite($site)->setFormationIntituleLibre('Formation');
        foreach ([$membership, $plan, $subscription, $company, $otherCompany, $site, $session] as $entity) $this->em->persist($entity);

        $date = new \DateTimeImmutable('2026-10-22 09:21');
        $signature = 'data:image/png;base64,existing-signature';
        foreach (['company-date', 'company-image', 'company-both', 'company-pending', 'company-empty', 'company-of-only', 'company-wrong-party', 'individual-date', 'individual-image', 'individual-pending', 'individual-wrong-party', 'other-company'] as $kind) {
            $convention = (new ConventionContrat())->setEntite($tenant)->setCreateur($user)->setSession($session)->setNumero('CC-'.$kind)->setPdfPath('fixture-preview.pdf');
            if (str_starts_with($kind, 'individual-')) {
                $learner = (new Utilisateur())->setEmail($kind.'@company.test')->setPassword('unused')->setPrenom($kind)->setNom('Stagiaire');
                $registration = (new Inscription())->setEntite($tenant)->setCreateur($user)->setSession($session)->setStagiaire($learner)->setEntreprise($company);
                $convention->setStagiaire($learner)->addInscription($registration);
                $this->em->persist($learner);
                $this->em->persist($registration);
            } else {
                $convention->setEntreprise($kind === 'other-company' ? $otherCompany : $company);
            }
            match ($kind) {
                'company-date', 'other-company' => $convention->setDateSignatureEntreprise($date),
                'company-image' => $convention->setSignatureDataUrlEntreprise($signature),
                'company-both' => $convention->setDateSignatureEntreprise($date)->setSignatureDataUrlEntreprise($signature),
                'company-empty' => $convention->setSignatureDataUrlEntreprise(''),
                'company-of-only' => $convention->setDateSignatureOf($date),
                'company-wrong-party', 'individual-date' => $convention->setDateSignatureStagiaire($date),
                'individual-image' => $convention->setSignatureDataUrlStagiaire($signature),
                'individual-wrong-party' => $convention->setDateSignatureEntreprise($date),
                default => null,
            };
            $this->em->persist($convention);
        }
        $this->em->flush();
        $this->client->loginUser($user);
        $this->base = '/fr/entreprise/'.$tenant->getId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testRecipientFiltersAreDisjointAndCoverEveryVisibleConvention(): void
    {
        $all = $this->conventions('all');
        $signed = $this->conventions('signed');
        $pending = $this->conventions('pending');
        self::assertSame(11, $all['recordsTotal']);
        self::assertSame(5, $signed['recordsFiltered']);
        self::assertSame(6, $pending['recordsFiltered']);
        $numbers = static fn(array $response): array => array_column($response['data'], 'numero');
        self::assertEqualsCanonicalizing(['CC-company-date', 'CC-company-image', 'CC-company-both', 'CC-individual-date', 'CC-individual-image'], $numbers($signed));
        self::assertEqualsCanonicalizing(['CC-company-pending', 'CC-company-empty', 'CC-company-of-only', 'CC-company-wrong-party', 'CC-individual-pending', 'CC-individual-wrong-party'], $numbers($pending));
        self::assertSame([], array_intersect($numbers($signed), $numbers($pending)));
        self::assertEqualsCanonicalizing($numbers($all), array_merge($numbers($signed), $numbers($pending)));
    }

    public function testSignedRecipientsDoNotReceiveAnotherSignatureAction(): void
    {
        foreach ($this->conventions('signed')['data'] as $row) {
            self::assertStringContainsString('>Signée</span>', $row['signatures']);
            self::assertStringNotContainsString('en attente', $row['signatures']);
            self::assertStringNotContainsString('OF', $row['signatures']);
            self::assertStringNotContainsString('Signer', $row['actions']);
            self::assertStringNotContainsString('/signature', $row['actions']);
            self::assertStringContainsString('/pdf', $row['actions']);
        }
        foreach ($this->conventions('pending')['data'] as $row) {
            self::assertStringContainsString('Non signée', $row['signatures']);
            self::assertStringContainsString('en attente', $row['signatures']);
            if (str_starts_with($row['numero'], 'CC-company-')) self::assertStringContainsString('Signer', $row['actions']);
            else self::assertStringNotContainsString('Signer', $row['actions']);
        }
    }

    public function testDashboardDoesNotRequestAlreadyRecordedCompanySignatures(): void
    {
        $this->client->request('GET', $this->base.'/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '[data-pending-convention]');
        $allPendingText = implode(' ', $this->client->getCrawler()->filter('[data-pending-convention]')->each(static fn($node): string => $node->text()));
        foreach (['company-date', 'company-image', 'company-both', 'other-company'] as $kind) self::assertStringNotContainsString('CC-'.$kind, $allPendingText);
        foreach (['company-pending', 'company-empty', 'company-of-only', 'company-wrong-party'] as $kind) self::assertStringContainsString('CC-'.$kind, $allPendingText);
        self::assertStringNotContainsString('Signature à compléter', $allPendingText);
    }

    private function conventions(string $filter): array
    {
        $this->client->request('POST', $this->base.'/documents/conventions/ajax', ['signFilter' => $filter, 'length' => 100]);
        self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
