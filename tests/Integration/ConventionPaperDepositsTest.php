<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Entreprise, Formation, Session, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Enum\SessionPieceType;
use App\Service\Convention\ConventionPaperDeposits;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\Mailer\MailerInterface;

final class ConventionPaperDepositsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private array $database;
    private Entite $tenant;
    private Utilisateur $admin;
    private Session $session;
    private ConventionContrat $convention;
    private string $uploadDir;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->uploadDir = self::getContainer()->getParameter('session_piece_dir');
        if (!is_dir($this->uploadDir)) mkdir($this->uploadDir, 0770, true);

        $this->admin = (new Utilisateur())->setEmail('paper-conventions@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test');
        $this->tenant = (new Entite())->setNom('Organisme papier')->setEmail('paper@example.test')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->tenant] as $row) $this->em->persist($row);
        $this->em->flush();
        $this->admin->setEntite($this->tenant);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->tenant)->setUtilisateur($this->admin)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setName('Paper test')->setCode('PAPER_TEST');
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->tenant)->setPlan($plan)->setStatus('active'));
        $formation = (new Formation())->setEntite($this->tenant)->setCreateur($this->admin)->setTitre('Formation papier')->setSlug('formation-papier');
        $site = (new Site())->setEntite($this->tenant)->setCreateur($this->admin)->setNom('Salle papier')->setSlug('salle-papier');
        $company = (new Entreprise())->setEntite($this->tenant)->setCreateur($this->admin)->setRaisonSociale('Client papier');
        foreach ([$formation, $site, $company] as $row) $this->em->persist($row);
        $this->session = (new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setFormation($formation)->setSite($site)->setCode('SES-PAPER-TEST');
        $this->convention = (new ConventionContrat())->setEntite($this->tenant)->setCreateur($this->admin)->setSession($this->session)->setEntreprise($company)->setNumero('CONV-PAPER-TEST');
        $this->em->persist($this->session);
        $this->em->persist($this->convention);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file) || is_link($file)) unlink($file);
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $n => $key) {
            if ($this->database[$n] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->database[$n];
        }
    }

    public function testExistingPaperDepositReplacesPendingStatusOnListingAndDetailWithoutInventingSignatures(): void
    {
        $piece = $this->piece();
        $piece->setUploadedAt(new \DateTimeImmutable('2026-09-18 14:25:00', new \DateTimeZone('Europe/Paris')));
        $this->em->flush();

        $status = $this->listingStatus();
        self::assertStringContainsString('Document signé déposé', $status);
        self::assertStringContainsString('Dépôt de session', $status);
        self::assertStringContainsString('18/09/2026', $status);
        self::assertStringNotContainsString('en attente', $status);
        self::assertStringNotContainsString('Non signée', $status);
        self::assertStringNotContainsString('Partiellement signée', $status);

        $page = $this->client->request('GET', $this->url('/'.$this->convention->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Document signé déposé', $html);
        self::assertStringContainsString('Dépôt de session', $html);
        self::assertStringContainsString('/pieces/'.$piece->getId(), $html);
        $viewUrl = $page->filter('a[href*="/pieces/'.$piece->getId().'/view"]')->first()->attr('href');
        $this->client->request('GET', $viewUrl);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $this->convention = $this->em->find(ConventionContrat::class, $this->convention->getId());
        $this->em->refresh($this->convention);
        self::assertNull($this->convention->getDateSignatureEntreprise());
        self::assertNull($this->convention->getDateSignatureStagiaire());
        self::assertNull($this->convention->getDateSignatureOf());
        self::assertNull($this->convention->getSignatureDataUrlEntreprise());
        self::assertNull($this->convention->getSignatureDataUrlStagiaire());
        self::assertNull($this->convention->getPdfPath());
        self::assertFalse($this->convention->isSigned());
    }

    public function testOnlyExistingSignedConventionFilesFromTheSameSessionAndTenantAreShown(): void
    {
        $otherTenant = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->admin);
        $otherSession = (new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setFormation($this->session->getFormation())->setSite($this->session->getSite())->setCode('SES-OTHER-PAPER');
        $this->em->persist($otherTenant);
        $this->em->persist($otherSession);
        $this->em->flush();
        $this->piece($otherSession);
        $foreignPiece = $this->piece(tenant: $otherTenant);
        $this->piece(type: SessionPieceType::FACTURE);
        $this->piece(createFile: false);
        $this->em->flush();

        self::assertSame([], $this->deposits());
        $status = $this->listingStatus();
        self::assertStringContainsString('Non signée', $status);
        self::assertStringContainsString('en attente', $status);
        self::assertStringNotContainsString('Document signé déposé', $status);

        // A forged URL must not expose a different tenant's deposit even if its session matches.
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant->getId().'/session/'.$this->session->getId().'/pieces/'.$foreignPiece->getId().'/view');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('previously uploaded signed paper', $this->client->getResponse()->getContent());
    }

    public function testValidatedDepositsArePreferredAndDatesOrderEachGroup(): void
    {
        $old = $this->piece()->setValide(true)->setUploadedAt(new \DateTimeImmutable('2026-09-01'));
        $recent = $this->piece()->setValide(true)->setUploadedAt(new \DateTimeImmutable('2026-09-15'));
        $unvalidated = $this->piece()->setUploadedAt(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();
        self::assertSame([$recent->getId(), $old->getId(), $unvalidated->getId()], array_map(static fn (SessionPiece $p): int => $p->getId(), $this->deposits()));
        self::assertStringContainsString('Validé', $this->listingStatus());
        $this->em->find(SessionPiece::class, $old->getId())->setValide(false);
        $this->em->find(SessionPiece::class, $recent->getId())->setValide(false);
        $this->em->flush();
        self::assertStringNotContainsString('Validé', $this->listingStatus());
        self::assertStringContainsString('Document signé déposé', $this->listingStatus());
    }

    public function testRemovingAStoredFileOrItsRecordRestoresTheElectronicStatus(): void
    {
        $piece = $this->piece();
        $this->em->flush();
        self::assertStringContainsString('Document signé déposé', $this->listingStatus());
        unlink($this->uploadDir.'/'.$piece->getFilename());
        self::assertStringContainsString('Non signée', $this->listingStatus());

        $another = $this->piece();
        $this->em->flush();
        self::assertStringContainsString('Document signé déposé', $this->listingStatus());
        $this->em->remove($this->em->find(SessionPiece::class, $another->getId()));
        $this->em->flush();
        self::assertStringContainsString('Non signée', $this->listingStatus());
    }

    public function testPaperDepositDoesNotEraseAnExistingElectronicSignature(): void
    {
        $signedAt = new \DateTimeImmutable('2026-09-10 10:00:00');
        $this->convention->setDateSignatureEntreprise($signedAt);
        $this->piece();
        $this->em->flush();
        $status = $this->listingStatus();
        self::assertStringContainsString('Document signé déposé', $status);
        self::assertStringNotContainsString('en attente', $status);
        $this->convention = $this->em->find(ConventionContrat::class, $this->convention->getId());
        $this->em->refresh($this->convention);
        self::assertSame($signedAt->format(DATE_ATOM), $this->convention->getDateSignatureEntreprise()?->format(DATE_ATOM));
        self::assertNull($this->convention->getDateSignatureOf());
        self::assertTrue($this->convention->isSigned());
    }

    public function testSessionDepositDoesNotClaimIndividualSignatureWhenSeveralConventionsShareTheSession(): void
    {
        $individual = (new ConventionContrat())->setEntite($this->tenant)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($this->admin)->setNumero('CONV-INDIVIDUAL-PAPER');
        $this->em->persist($individual);
        $this->piece();
        $this->em->flush();
        $receipts = self::getContainer()->get(ConventionPaperDeposits::class)->forConventions([$this->convention, $individual]);
        self::assertCount(1, $receipts[$this->convention->getId()] ?? []);
        self::assertCount(1, $receipts[$individual->getId()] ?? []);
        foreach ([$this->convention, $individual] as $convention) {
            $status = $this->listingStatus($convention);
            self::assertStringContainsString('Dépôt de session', $status);
            self::assertStringNotContainsString('Entreprise : signé', $status);
            self::assertStringNotContainsString('Stagiaire : signé', $status);
            self::assertStringNotContainsString('Organisme : signé', $status);
            self::assertFalse($convention->isSigned());
        }
    }

    public function testFilesOutsideTheUploadDirectoryCannotCountAsDeposits(): void
    {
        $outside = dirname($this->uploadDir).'/paper-outside-test-'.bin2hex(random_bytes(10)).'.pdf';
        file_put_contents($outside, '%PDF-1.4 outside fixture');
        $this->files[] = $outside;
        $this->piece(createFile: false)->setFilename('../'.basename($outside));
        $linkName = 'paper-link-test-'.bin2hex(random_bytes(10)).'.pdf';
        $link = $this->uploadDir.'/'.$linkName;
        symlink($outside, $link);
        $this->files[] = $link;
        $this->piece(createFile: false)->setFilename($linkName);
        $this->em->flush();
        self::assertSame([], $this->deposits());
        self::assertStringContainsString('Non signée', $this->listingStatus());
    }

    /** @return list<SessionPiece> */
    private function deposits(): array
    {
        return self::getContainer()->get(ConventionPaperDeposits::class)->forConventions([$this->convention])[$this->convention->getId()] ?? [];
    }

    private function piece(?Session $session = null, ?Entite $tenant = null, SessionPieceType $type = SessionPieceType::CONVENTION_SIGNEE, bool $createFile = true): SessionPiece
    {
        $filename = 'convention-paper-test-'.bin2hex(random_bytes(12)).'.pdf';
        if ($createFile) {
            $path = $this->uploadDir.'/'.$filename;
            file_put_contents($path, '%PDF-1.4 fixture: previously uploaded signed paper');
            $this->files[] = $path;
        }
        $piece = (new SessionPiece())->setSession($this->em->find(Session::class, ($session ?? $this->session)->getId()))->setEntite($this->em->find(Entite::class, ($tenant ?? $this->tenant)->getId()))->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))->setType($type)->setFilename($filename)->setMimeType('application/pdf');
        $this->em->persist($piece);
        return $piece;
    }

    private function listingStatus(?ConventionContrat $convention = null): string
    {
        $this->client->request('POST', $this->url('/ajax'), ['length' => 100]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['data'] as $row) if ($row['id'] === ($convention ?? $this->convention)->getId()) return $row['suivi'];
        self::fail('La convention attendue est absente de la liste.');
    }

    private function url(string $suffix): string
    {
        return '/fr/administrateur/'.$this->tenant->getId().'/conventions'.$suffix;
    }
}
