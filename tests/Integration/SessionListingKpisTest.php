<?php

namespace App\Tests\Integration;

use App\Entity\{Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class SessionListingKpisTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Formation $formation;
    private Site $site;
    private array $originalDatabase;
    private Formation $otherFormation;
    private Formation $foreignFormation;
    private string $futureDate;
    private string $pastDate;

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
        $this->otherFormation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Bureautique')->setSlug('bureautique');
        $this->foreignFormation = (new Formation())->setEntite($this->other)->setCreateur($this->admin)->setTitre('Confidentiel')->setSlug('confidentiel');
        $trainerUser = (new Utilisateur())->setEmail('trainer-kpi@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin');
        $trainer = (new \App\Entity\Formateur())->setUtilisateur($trainerUser)->setEntite($this->entite)->setCreateur($this->admin);
        foreach ([$this->otherFormation, $this->foreignFormation, $trainerUser, $trainer] as $entity) $this->em->persist($entity);
        $today = new \DateTimeImmutable('today');
        $this->futureDate = $today->modify('+10 days')->format('Y-m-d');
        $this->pastDate = $today->modify('-10 days')->format('Y-m-d');
        $past = $this->session('SES-PAST', $this->formation, \App\Enum\StatusSession::DONE, $this->pastDate);
        $running = $this->session('SES-RUNNING', $this->formation, \App\Enum\StatusSession::PUBLISHED, $today->format('Y-m-d'));
        $running->getJours()->first()->setDateDebut($today->modify('-1 day'));
        $running->getJours()->first()->setDateFin($today->modify('+1 day'));
        $future = $this->session('SES-FUTURE', $this->otherFormation, \App\Enum\StatusSession::DRAFT, $this->futureDate);
        $future->setFormateur($trainer);
        $this->session('SES-CANCELED', $this->formation, \App\Enum\StatusSession::CANCELED, $today->modify('+20 days')->format('Y-m-d'));
        $this->session('SES-FOREIGN', $this->foreignFormation, \App\Enum\StatusSession::DRAFT, $this->futureDate, $this->other);
        $completeInscription = $this->inscription($past);
        $dossier = (new \App\Entity\DossierInscription())->setCreateur($this->admin)->setEntite($this->entite)->setInscription($completeInscription);
        $completeInscription->setDossier($dossier);
        $this->em->persist($dossier);
        $this->em->persist((new \App\Entity\SessionPiece())->setEntite($this->entite)->setCreateur($this->admin)->setSession($past)->setType(\App\Enum\SessionPieceType::CONVENTION_SIGNEE)->setFilename('test-only.pdf'));
        $this->inscription($future);
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

    public function testNoneAndMultipleSelectionsMatchListingAndKeepTenantScope(): void
    {
        $f1 = (string)$this->formation->getId();
        $f2 = (string)$this->otherFormation->getId();
        $foreign = (string)$this->foreignFormation->getId();
        foreach ([
            [[], [4, 2, 1, 1]],
            [['statusFilter'=>'[]'], [0, 0, 0, 0]],
            [['formationFilter'=>'[]'], [0, 0, 0, 0]],
            [['statusFilter'=>'["draft","published"]'], [2, 1, 1, 0]],
            [['formationFilter'=>json_encode([$f1, $f2, $foreign])], [4, 2, 1, 1]],
            [['formationFilter'=>$foreign], [0, 0, 0, 0]],
            [['formationFilter'=>$f2, 'statusFilter'=>'["draft","done"]'], [1, 1, 0, 0]],
            // URLs saved before the filter update remain supported.
            [['status'=>'done', 'formation'=>$f1], [1, 0, 0, 1]],
        ] as [$filters, $expected]) {
            $this->assertMatchingCounts($filters, $expected);
        }
    }

    public function testPeriodDossierTrainerAndSearchUseTheSameFilteredSessions(): void
    {
        foreach ([
            [['dateFrom'=>$this->futureDate, 'dateTo'=>$this->futureDate], [1, 1, 0, 0]],
            [['dateTo'=>$this->pastDate], [1, 0, 0, 1]],
            [['dateFrom'=>$this->futureDate], [2, 2, 0, 0]],
            [['formateurFilter'=>'martin'], [1, 1, 0, 0]],
            [['formateurFilter'=>'inconnu'], [0, 0, 0, 0]],
            [['search'=>['value'=>'SES-RUNNING']], [1, 0, 1, 0]],
            [['search'=>['value'=>'Confidentiel']], [0, 0, 0, 0]],
            [['dossierFilter'=>'[]'], [0, 0, 0, 0]],
            [['dossierFilter'=>'complete'], [1, 0, 0, 1]],
            [['dossierFilter'=>'missing'], [1, 1, 0, 0]],
            [['dossierFilter'=>'["complete","missing"]'], [2, 1, 0, 1]],
            [['dossierFilter'=>'["complete","missing"]', 'dateFrom'=>$this->futureDate], [1, 1, 0, 0]],
        ] as [$filters, $expected]) {
            $this->assertMatchingCounts($filters, $expected);
        }
    }

    public function testCompactGetMatchesPostFiltersAndSortWithoutLeakingOtherTenants(): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        $url = self::getContainer()->get('router')->generate('app_administrateur_session_ajax', ['entite' => $this->entite->getId()]);
        $filters = ['statusFilter' => '["draft","done"]', 'formationFilter' => json_encode([$this->formation->getId(), $this->otherFormation->getId(), $this->foreignFormation->getId()])];

        $client->request('POST', $url, $filters + [
            'draw' => 8, 'length' => 25, 'search' => ['value' => 'SES-'],
            'columns' => [['name' => 'code']], 'order' => [['column' => 0, 'dir' => 'asc']],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $post = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $client->request('GET', $url, $filters + [
            'draw' => 8, 'length' => 25, 'searchTerm' => 'SES-', 'orderBy' => 'code', 'orderDir' => 'asc',
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $get = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(8, $get['draw']);
        self::assertSame(4, $get['recordsTotal']);
        self::assertSame(2, $get['recordsFiltered']);
        self::assertSame(array_column($post['data'], 'infos'), array_column($get['data'], 'infos'));
        self::assertStringContainsString('SES-FUTURE', $get['data'][0]['infos']);
        self::assertStringContainsString('SES-PAST', $get['data'][1]['infos']);
        self::assertStringNotContainsString('SES-FOREIGN', $client->getResponse()->getContent());

        $client->request('GET', $url, ['searchTerm' => 'SES-PAST', 'orderBy' => 'code', 'orderDir' => 'desc']);
        $searched = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $searched['recordsFiltered']);
        self::assertCount(1, $searched['data']);
        self::assertStringContainsString('SES-PAST', $searched['data'][0]['infos']);

        // The original DataTables payload is also accepted over GET.
        $client->request('GET', $url, ['columns' => [['name' => 'code']], 'order' => [['column' => 0, 'dir' => 'desc']]]);
        $descending = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('SES-RUNNING', $descending['data'][0]['infos']);
        self::assertStringContainsString('SES-CANCELED', $descending['data'][3]['infos']);
    }

    public function testListingPaginationIsBoundedForGetAndPost(): void
    {
        for ($i = 0; $i < 105; ++$i) {
            $this->session(sprintf('SES-LIMIT-%03d', $i), $this->formation, \App\Enum\StatusSession::DRAFT, $this->futureDate);
        }
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        $url = self::getContainer()->get('router')->generate('app_administrateur_session_ajax', ['entite' => $this->entite->getId()]);

        foreach ([
            ['GET', [], 25],
            ['GET', ['length' => -1], 100],
            ['POST', ['length' => 500], 100],
            ['GET', ['length' => 0], 1],
            ['POST', ['length' => -2], 1],
        ] as [$method, $pagination, $expected]) {
            $client->request($method, $url, $pagination + ['start' => -10, 'searchTerm' => 'SES-LIMIT-', 'orderBy' => 'code', 'orderDir' => 'asc']);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $json = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(105, $json['recordsFiltered']);
            self::assertCount($expected, $json['data']);
            self::assertStringContainsString('SES-LIMIT-000', $json['data'][0]['infos']);
        }

        $client->request('GET', $url, ['start' => 100, 'length' => 25, 'searchTerm' => 'SES-LIMIT-', 'orderBy' => 'code', 'orderDir' => 'asc']);
        $lastPage = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(5, $lastPage['data']);
        self::assertStringContainsString('SES-LIMIT-100', $lastPage['data'][0]['infos']);
    }

    public function testListingFailureDoesNotExposeExceptionDetails(): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        $url = self::getContainer()->get('router')->generate('app_administrateur_session_ajax', ['entite' => $this->entite->getId()]);
        $client->request('GET', $url, ['draw' => 4, 'dateFrom' => 'not-a-real-date']);
        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $json = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4, $json['draw']);
        self::assertSame([], $json['data']);
        self::assertSame('Impossible de charger les sessions. Veuillez réessayer.', $json['error']);
        self::assertStringNotContainsString('not-a-real-date', $client->getResponse()->getContent());
    }

    /** @param array{int,int,int,int} $expected Total, upcoming, running, done. */
    public function testGlobalSearchIncludesCompaniesLocationsAndParticipantsWithoutDuplicates(): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Acme Industrie');
        $employer = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Employeur Delta');
        $this->em->persist($company);
        $this->em->persist($employer);
        $past = $this->em->getRepository(Session::class)->findOneBy(['code'=>'SES-PAST']);
        $past->setEntrepriseCliente($company);
        $this->site->setVille('Montpellier')->setCodePostal('34000')->setAdresse('12 rue des Lilas');
        // Multiple matches must still count the session only once.
        $learner = (new Utilisateur())->setEmail('second.search@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test');
        $this->em->persist($learner);
        $this->inscription($past)->setStagiaire($learner)->setEntreprise($employer);
        $this->em->flush();
        foreach (['acme', 'INDUSTRIE', 'Employeur Delta', 'Acme Admin Test', '34000 Acme', 'Lilas Industrie'] as $term) {
            $this->assertMatchingCounts(['search'=>['value'=>$term]], [1,0,0,1]);
        }
        $this->assertMatchingCounts(['search'=>['value'=>'Admin Test']], [2,1,0,1]);
        $this->assertMatchingCounts(['search'=>['value'=>'Montpellier']], [4,2,1,1]);
        $this->assertMatchingCounts(['search'=>['value'=>'Bureautique Camille']], [1,1,0,0]);
        $this->assertMatchingCounts(['search'=>['value'=>'Confidentiel']], [0,0,0,0]);
    }

    public function testSearchFindsSubcontractingCompanyShownOnSessionCard(): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('OPCADIA');
        $this->em->persist($company);
        $past = $this->em->getRepository(Session::class)->findOneBy(['code'=>'SES-PAST']);
        // This is the blue company badge, not entrepriseCliente or a learner's employer.
        $past->setOrganismeFormation($company);
        $this->em->flush();
        foreach (['opc', 'OPC', 'OpCaDiA', 'opc Habilitation'] as $term) {
            $this->assertMatchingCounts(['search'=>['value'=>$term]], [1,0,0,1]);
        }
        $this->assertMatchingCounts(['search'=>['value'=>'opc'], 'statusFilter'=>'draft'], [0,0,0,0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recipientSignatures')]
    public function testConventionCoverageAndDossierFiltersRequireOnlyTheRecipientSignature(bool $company, string $setter, mixed $value, bool $signed): void
    {
        [$session, $inscription, $convention] = $this->conventionSession($company);
        $convention->$setter($value);
        $this->em->flush();
        $cards = self::getContainer()->get(\App\Service\Session\ParticipantConventions::class)->forSession($session);
        self::assertCount(1, $cards[$inscription->getId()]);
        self::assertSame($signed, $cards[$inscription->getId()][0]['signed']);

        $listing = $this->signatureListing();
        self::assertStringContainsString(($signed ? '1' : '0') . '/1 inscriptions couvertes et signées', $listing['data'][0]['dossier']);
        self::assertSame($signed ? 1 : 0, $this->signatureListing('complete')['recordsFiltered']);
        self::assertSame($signed ? 0 : 1, $this->signatureListing('missing')['recordsFiltered']);
    }

    public static function recipientSignatures(): iterable
    {
        $date = new \DateTimeImmutable('2026-10-22 09:21:00');
        yield 'entreprise, date seule' => [true, 'setDateSignatureEntreprise', $date, true];
        yield 'entreprise, image seule' => [true, 'setSignatureDataUrlEntreprise', 'data:image/png;base64,existing', true];
        yield 'entreprise, OF seul' => [true, 'setDateSignatureOf', $date, false];
        yield 'entreprise, signature du stagiaire seule' => [true, 'setDateSignatureStagiaire', $date, false];
        yield 'entreprise, image vide' => [true, 'setSignatureDataUrlEntreprise', '', false];
        yield 'individuel, date seule' => [false, 'setDateSignatureStagiaire', $date, true];
        yield 'individuel, image seule' => [false, 'setSignatureDataUrlStagiaire', 'data:image/png;base64,existing', true];
        yield 'individuel, OF seul' => [false, 'setDateSignatureOf', $date, false];
        yield 'individuel, signature entreprise seule' => [false, 'setDateSignatureEntreprise', $date, false];
    }

    public function testUnsignedDuplicateDoesNotInvalidateAnInscriptionAlreadyCoveredByASignedConvention(): void
    {
        [$session, $inscription, $convention] = $this->conventionSession(true);
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable());
        $duplicate = (new \App\Entity\ConventionContrat())->setSession($session)->setEntite($this->entite)
            ->setCreateur($this->admin)->setEntreprise($convention->getEntreprise())->setNumero('CONV-DUPLICATE')->addInscription($inscription);
        $this->em->persist($duplicate);
        $this->em->flush();

        self::assertStringContainsString('1/1 inscriptions couvertes et signées', $this->signatureListing()['data'][0]['dossier']);
        self::assertSame(1, $this->signatureListing('complete')['recordsFiltered']);
        self::assertSame(0, $this->signatureListing('missing')['recordsFiltered']);
    }

    public function testPaperConventionStillCompletesSessionDossierWithoutInventingElectronicSignatures(): void
    {
        [$session, , $convention] = $this->conventionSession(true);
        $piece = (new \App\Entity\SessionPiece())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSession($session)->setType(\App\Enum\SessionPieceType::CONVENTION_SIGNEE)->setFilename('paper-convention.pdf');
        $this->em->persist($piece);
        $this->em->flush();

        self::assertStringContainsString('Déposé', $this->signatureListing()['data'][0]['dossier']);
        self::assertSame(1, $this->signatureListing('complete')['recordsFiltered']);
        self::assertSame(0, $this->signatureListing('missing')['recordsFiltered']);
        self::assertFalse($convention->isSignedByRecipient());
        self::assertNull($convention->getDateSignatureEntreprise());
        self::assertNull($convention->getDateSignatureOf());
    }

    /** @return array{Session, Inscription, \App\Entity\ConventionContrat} */
    private function conventionSession(bool $company): array
    {
        $session = $this->session('SES-SIGNATURE', $this->formation, \App\Enum\StatusSession::DONE, $this->pastDate);
        $inscription = $this->inscription($session);
        $session->addInscription($inscription);
        $dossier = (new \App\Entity\DossierInscription())->setCreateur($this->admin)->setEntite($this->entite)->setInscription($inscription);
        $inscription->setDossier($dossier);
        $this->em->persist($dossier);
        $convention = (new \App\Entity\ConventionContrat())->setSession($session)->setEntite($this->entite)
            ->setCreateur($this->admin)->setNumero('CONV-SIGNATURE')->addInscription($inscription);
        if ($company) {
            $recipient = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Entreprise signataire');
            $this->em->persist($recipient);
            $convention->setEntreprise($recipient);
        } else {
            $convention->setStagiaire($this->admin);
        }
        $this->em->persist($convention);

        return [$session, $inscription, $convention];
    }

    private function signatureListing(?string $dossierFilter = null): array
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        $filters = ['searchTerm' => 'SES-SIGNATURE'];
        if ($dossierFilter !== null) $filters['dossierFilter'] = $dossierFilter;
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_session_ajax', ['entite' => $this->entite->getId()]), $filters);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertMatchingCounts(array $filters, array $expected): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $params = ['entite'=>$this->entite->getId()];
        $client->request('POST', self::getContainer()->get('router')->generate('app_administrateur_session_ajax', $params), $filters+['draw'=>1,'start'=>0,'length'=>1]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $rows = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4, $rows['recordsTotal']);
        self::assertSame($expected[0], $rows['recordsFiltered'], json_encode($filters));
        self::assertCount(min(1, $expected[0]), $rows['data']);
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_session_kpis', $params), $filters);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $kpis = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_combine(['count','upcoming','running','done'], $expected), $kpis, json_encode($filters));
    }

    private function session(string $code, Formation $formation, \App\Enum\StatusSession $status, string $date, ?Entite $tenant = null): Session
    {
        $tenant ??= $this->entite;
        $session = (new Session())->setEntite($tenant)->setCreateur($this->admin)->setFormation($formation)->setSite($this->site)->setCode($code)->setStatus($status);
        $session->addJour((new SessionJour())->setCreateur($this->admin)->setEntite($tenant)->setDateDebut(new \DateTimeImmutable($date.' 08:30'))->setDateFin(new \DateTimeImmutable($date.' 17:00')));
        $this->em->persist($session);
        return $session;
    }

    private function inscription(Session $session): Inscription
    {
        $inscription = (new Inscription())->setSession($session)->setStagiaire($this->admin)->setCreateur($this->admin)->setEntite($this->entite);
        $this->em->persist($inscription);
        return $inscription;
    }
}
