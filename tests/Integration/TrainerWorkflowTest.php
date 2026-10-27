<?php

namespace App\Tests\Integration;

use App\Entity\{ContratFormateur, ConventionContrat, Devis, Entite, Entreprise, Formateur, Formation, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Enum\ContratFormateurStatus;
use App\Service\Planning\SessionPlanningValidator;
use App\Service\Sequence\{ContratFormateurNumberGenerator, SequenceNumberManager};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Contrats, planning et rattachements dans une base jetable ; aucune donnée réelle. */
final class TrainerWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Formateur $morning;
    private Formateur $afternoon;
    private Session $session;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($this->admin); $this->em->persist($this->entite); $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->membership($this->admin, 'TENANT_ADMIN');
        $plan = (new \App\Entity\Billing\Plan())->setCode('test-unlimited')->setName('Test illimité');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->morning = $this->trainer('Matin');
        $this->afternoon = $this->trainer('Après-midi');
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation test')->setSlug('formation-test');
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->em->persist($formation); $this->em->persist($site);
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setFormation($formation)->setSite($site)->setCode('SES-TEST')->setFormateur($this->morning);
        $this->session->addJour($this->slot('2026-10-05 09:00', '2026-10-05 12:30'));
        $this->session->addJour($this->slot('2026-10-05 13:30', '2026-10-05 17:00', $this->afternoon));
        $this->em->persist($this->session); $this->em->flush();
        $sequence = $this->createMock(SequenceNumberManager::class);
        $number = 0;
        $sequence->method('next')->willReturnCallback(static function () use (&$number) { return [2026, ++$number]; });
        self::getContainer()->set(ContratFormateurNumberGenerator::class, new ContratFormateurNumberGenerator($sequence));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testGoogleRoutesRequiresExplicitCsrfProtectedRequest(): void
    {
        $old = [$_ENV['GOOGLE_MAPS_SERVER_KEY'] ?? null, $_SERVER['GOOGLE_MAPS_SERVER_KEY'] ?? null];
        $_ENV['GOOGLE_MAPS_SERVER_KEY'] = $_SERVER['GOOGLE_MAPS_SERVER_KEY'] = 'test-key';
        try {
            $calls = [];
            $http = new \Symfony\Component\HttpClient\MockHttpClient(function ($method, $url, $options) use (&$calls) {
                $calls[] = json_decode($options['body'], true);
                return new \Symfony\Component\HttpClient\Response\MockResponse('{"routes":[{"distanceMeters":10000,"duration":"1200s"}]}');
            });
            self::getContainer()->set('http_client', $http);
            $this->session->getSite()->setAdresse('10 rue Site')->setVille('Paris')->setCodePostal('75001')->setPays('France');
            $this->morning->setAdresse('20 rue Professionnelle')->setVille('Paris')->setCodePostal('75001')->setPays('France');
            $this->morning->getUtilisateur()->setAdresse('30 rue Départ')->setVille('Paris')->setCodePostal('75001')->setPays('France');
            $start = new \DateTimeImmutable('tomorrow 09:00', new \DateTimeZone('Europe/Paris'));
            foreach ($this->session->getJours() as $offset => $day) {
                $day->setDateDebut($start->modify('+'.($offset*4).' hours'))->setDateFin($start->modify('+'.($offset*4+3).' hours'));
            }
            $this->em->flush();
            $client = $this->client(); $client->catchExceptions(true);
            $path = '/fr/administrateur/'.$this->entite->getId().'/session/'.$this->session->getId();
            $page = $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertCount(0, $calls);
            $client->request('POST', $path, ['travel_trainer' => $this->morning->getId()]);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            self::assertCount(0, $calls);
            $page = $client->request('GET', $path);
            $form = $page->filter('input[name="travel_trainer"][value="'.$this->morning->getId().'"]')->ancestors()->filter('form')->first()->form();
            $client->submit($form);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertCount(1, $calls);
            self::assertStringContainsString('20 rue Professionnelle', $calls[0]['origin']['address']);
            // Only the requested trainer is disclosed, never every trainer on the page.
            $client->loginUser($this->morning->getUtilisateur());
            $path = '/fr/formateur/'.$this->entite->getId().'/dashboard';
            $page = $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertCount(1, $calls);
            $client->request('POST', $path);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            self::assertCount(1, $calls);
            $page = $client->request('GET', $path);
            $client->submit($page->selectButton('Calculer la distance avec Google')->form());
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertCount(2, $calls);
        } finally {
            foreach (['ENV', 'SERVER'] as $i => $type) {
                if ($old[$i] === null) unset($GLOBALS['_'.$type]['GOOGLE_MAPS_SERVER_KEY']);
                else $GLOBALS['_'.$type]['GOOGLE_MAPS_SERVER_KEY'] = $old[$i];
            }
        }
    }

    public function testTrainerCreationIncludesCompanySiretVatAndProfessionalAddress(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/formateur/ajouter');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $page->filter('form[name="formateur"]')->form([
            'formateur[utilisateur]' => $this->admin->getId(),
            'formateur[nomSociete]' => 'Formation & Conseil',
            'formateur[siret]' => '80813612100023',
            'formateur[adresse]' => '12 rue des Écoles',
            'formateur[codePostal]' => '30100',
            'formateur[ville]' => 'Alès',
            'formateur[pays]' => 'France',
            'formateur[assujettiTva]' => '1',
            'formateur[tauxTvaParDefaut]' => '20',
            'formateur[numeroTvaIntra]' => 'FR12808136121',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $trainer = $this->em->getRepository(Formateur::class)->findOneBy(['utilisateur' => $this->admin->getId(), 'entite' => $this->entite->getId()]);
        self::assertSame('Formation & Conseil', $trainer->getNomSociete());
        self::assertSame('80813612100023', $trainer->getSiret());
        self::assertSame('Alès', $trainer->getVille());
        self::assertTrue($trainer->isAssujettiTva());
        self::assertSame(20.0, $trainer->getTauxTvaParDefaut());
        self::assertSame('FR12808136121', $trainer->getNumeroTvaIntra());
    }

    public function testContractPdfIdentifiesCompanyWithoutLosingTrainerIdentity(): void
    {
        $this->morning->setNomSociete('Formation & Conseil')->setSiret('80813612100023')->setAdresse('12 rue des Écoles')->setCodePostal('30100')->setVille('Alès');
        $contract = (new ContratFormateur())->setEntite($this->entite)->setFormateur($this->morning)->setSession($this->session)->setNumero('CF-TEST');
        $document = self::getContainer()->get(\App\Service\Pdf\ContratFormateurDocument::class);
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('pdf/contrat_formateur.html.twig', $document->templateData($contract));
        self::assertStringContainsString('Formation &amp; Conseil', $html);
        self::assertStringContainsString('Intervenant :', $html);
        self::assertStringContainsString('30100 Alès', $html);
        if (getenv('TRAINER_PDF_PREVIEW')) {
            file_put_contents(getenv('TRAINER_PDF_PREVIEW'), self::getContainer()->get(\App\Service\Pdf\PdfManager::class)->createPortraitBytes($html));
        }
        $this->morning->setNomSociete('  ');
        $html = $twig->render('pdf/contrat_formateur.html.twig', $document->templateData($contract));
        self::assertStringContainsString('Formateur indépendant', $html);
        self::assertStringNotContainsString('Intervenant :', $html);
    }

    public function testMissingSignedContractCanBeRecoveredOnlyOnce(): void
    {
        $contract = $this->contract($this->morning, 'CF-RECOVERY');
        $contract->setStatus(ContratFormateurStatus::SIGNE);
        $this->em->flush();
        $root = sys_get_temp_dir() . '/contract-recovery-' . bin2hex(random_bytes(6));
        $document = new \App\Service\Pdf\ContratFormateurDocument($root, self::getContainer()->get(\App\Service\Session\SessionParticipantCount::class));
        $command = new \App\Command\RecoverTrainerContractPdfCommand($this->em, $document,
            self::getContainer()->get(\App\Service\Pdf\PdfManager::class), self::getContainer()->get('twig'), $root);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $args = ['contrat' => $contract->getId(), '--entite' => $this->entite->getId(), '--numero' => 'CF-RECOVERY'];
        try {
            self::assertSame(1, $tester->execute($args + ['--execute' => true]));
            self::assertFileDoesNotExist($root . '/var/storage/contrat-formateur-recoveries/' . $contract->getId() . '.json');
            $signatureDir = $root . '/public/uploads/signatures';
            mkdir($signatureDir, 0770, true);
            file_put_contents($signatureDir . '/org.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOuoAAAAASUVORK5CYII='));
            $preferences = (new \App\Entity\EntitePreferences())->setCreateur($this->admin);
            $preferences->setSignatureOrganismePath('uploads/signatures/org.png');
            $this->entite->setPreferences($preferences);
            $this->em->persist($preferences);
            $this->em->flush();
            $contract->setSignatureOrganismePath('uploads/signatures/deleted.png');
            $this->em->flush();
            self::assertNull($document->templateData($contract)['orgSigDataUri']);
            self::assertNotNull($document->templateData($contract, true)['orgSigDataUri']);
            self::assertSame(0, $tester->execute($args));
            self::assertNull($contract->getPdfPath());
            self::assertSame(0, $tester->execute($args + ['--execute' => true]));
            $path = $document->storedPath($contract);
            self::assertNotNull($path);
            self::assertSame(1, $contract->getVersionNumero());
            self::assertSame(ContratFormateurStatus::SIGNE, $contract->getStatus());
            if (getenv('RECOVERY_PDF_PREVIEW')) copy($path, getenv('RECOVERY_PDF_PREVIEW'));
            // Old recovery PDFs can be corrected once, without deleting their previous bytes.
            self::assertNotNull($document->templateData($contract, true)['orgSigDataUri']);
            self::assertSame(0, $tester->execute($args + ['--execute' => true, '--corriger-signature-organisme' => true]));
            self::assertFileExists($path);
            self::assertNotSame($path, $document->storedPath($contract));
            self::assertSame(1, $tester->execute($args + ['--execute' => true, '--corriger-signature-organisme' => true]));
            unlink($document->storedPath($contract));
            self::assertSame(1, $tester->execute($args + ['--execute' => true]));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function testManualSatisfactionAssignmentCreatesAndSkipsDuplicates(): void
    {
        $inscription = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($this->admin);
        $template = (new \App\Entity\SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Satisfaction test');
        $this->em->persist($inscription); $this->em->persist($template); $this->em->flush();
        $iid = $inscription->getId(); $tid = $template->getId();
        $url = '/fr/administrateur/'.$this->entite->getId().'/satisfaction/assignments/new';
        $client = $this->client();
        for ($n = 0; $n < 2; ++$n) {
            $page = $client->request('GET', $url);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $client->submit($page->filter('form[name="form"]')->form([
                'form[template]' => $tid, 'form[inscriptions]' => [$iid], 'form[required]' => '1',
            ]));
            self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            self::assertSame(1, $this->em->getRepository(\App\Entity\SatisfactionAssignment::class)->count([]));
        }
    }

    public function testAssignmentsCountHalfDaysAndPreventSigningAnotherPeriod(): void
    {
        self::assertCount(2, $this->session->getFormateursEffectifs());
        self::assertCount(1, $this->session->getJoursPourFormateur($this->morning));
        self::assertCount(1, $this->session->getJoursPourFormateur($this->afternoon));
        self::assertSame(3.5, $this->session->getNombreHeuresPourFormateur($this->afternoon));
        self::assertSame(0.5, $this->session->getNombreJoursPourFormateur($this->morning));
        self::assertTrue($this->session->isFormateurUtilisateurSurPeriode($this->afternoon->getUtilisateur(), new \DateTimeImmutable('2026-10-05'), 'PM'));
        self::assertFalse($this->session->isFormateurUtilisateurSurPeriode($this->afternoon->getUtilisateur(), new \DateTimeImmutable('2026-10-05'), 'AM'));
        $this->session->getJours()->last()->setFormateur($this->morning);
        self::assertCount(1, $this->session->getFormateursEffectifs());
        self::assertSame(1.0, $this->session->getNombreJoursPourFormateur($this->morning));
    }

    public function testDuplicatingSessionPreservesTrainerAndExplicitPause(): void
    {
        $this->session->getJours()->last()->setPauseMinutes(15);
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_session_duplicate', ['id' => $this->session->getId()]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $copy = $this->em->getRepository(Session::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotSame($this->session->getId(), $copy->getId());
        self::assertCount(2, $copy->getJours());
        $copiedSlots = $copy->getJours()->toArray();
        usort($copiedSlots, static fn($a, $b) => $a->getDateDebut() <=> $b->getDateDebut());
        self::assertNull($copiedSlots[0]->getPauseMinutes());
        self::assertSame(15, $copiedSlots[1]->getPauseMinutes());
        self::assertSame($this->afternoon->getId(), $copiedSlots[1]->getFormateur()->getId());
        self::assertSame(3.25, $copy->getNombreHeuresPourFormateur($this->afternoon));
    }

    public function testSessionPlanningRejectsOverlapsAndAcceptsAdjacentSlots(): void
    {
        $validator = self::getContainer()->get(SessionPlanningValidator::class);
        self::assertSame([], $validator->errors($this->session));
        $other = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setFormation($this->session->getFormation())->setSite($this->session->getSite())->setFormateur($this->afternoon)->setCode('SES-OTHER');
        $slot = $this->slot('2026-10-05 16:00', '2026-10-05 18:00'); $other->addJour($slot);
        self::assertStringContainsString('déjà affecté', implode(' ', $validator->errors($other)));
        $slot->setDateDebut(new \DateTimeImmutable('2026-10-05 17:00'));
        self::assertSame([], $validator->errors($other));
        $other->addJour($this->slot('2026-10-05 17:30', '2026-10-05 19:00', $this->morning));
        self::assertStringContainsString('chevauchent', implode(' ', $validator->errors($other)));
    }

    public function testPlanningHoursRemainLocalInFeedsAndDetails(): void
    {
        $jour = $this->session->getJours()->first();
        $jour->setDateDebut(new \DateTimeImmutable('2026-10-05 08:30', new \DateTimeZone('UTC')));
        $jour->setDateFin(new \DateTimeImmutable('2026-10-05 17:00', new \DateTimeZone('UTC')));
        $inscription = (new \App\Entity\Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->admin);
        $this->em->persist($inscription); $this->em->flush();
        $client = $this->client();
        foreach (['formateurs', 'stagiaires', 'sessions'] as $planning) {
            $prefix = 'app_administrateur_planning_'.$planning.'_';
            $client->request('GET', $this->url($prefix.'data'), ['start' => '2026-10-01', 'end' => '2026-11-01']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $events = json_decode($client->getResponse()->getContent(), true)['events'];
            self::assertContains('2026-10-05T08:30:00', array_column($events, 'start'));
            self::assertContains('2026-10-05T17:00:00', array_column($events, 'end'));
            $params = ['jour' => $jour->getId()];
            if ($planning === 'stagiaires') $params['inscription'] = $inscription->getId();
            else $params['session'] = $this->session->getId();
            if ($planning === 'formateurs') $params['formateur'] = $this->morning->getId();
            $client->request('GET', $this->url($prefix.'event_details', $params));
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $details = json_decode($client->getResponse()->getContent(), true);
            self::assertSame('2026-10-05T08:30:00', $details['jour']['start']);
            self::assertSame('2026-10-05T17:00:00', $details['jour']['end']);
        }
    }

    public function testAdminAndTrainerCalendarsUseTheEffectiveTrainerOnly(): void
    {
        $client = $this->client();
        $range = ['start' => '2026-10-01', 'end' => '2026-11-01'];
        $client->request('GET', $this->url('app_administrateur_planning_formateurs_data'), $range);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $json = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(2, $json['events']);
        $client->request('GET', $this->url('app_administrateur_planning_sessions_data'), $range + ['formateur' => $this->afternoon->getId()]);
        $json = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $json['events']);
        self::assertSame($this->afternoon->getId(), $json['events'][0]['extendedProps']['formateurId']);

        $client->loginUser($this->afternoon->getUtilisateur());
        $client->request('GET', $this->url('app_formateur_calendar_feed'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $events = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $events);
        self::assertStringContainsString('13:30', $events[0]['start']);
        $client->request('GET', $this->url('app_formateur_session_show', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testContractStatusChangeDoesNotRequireReasonOrCreateRevision(): void
    {
        $contract = $this->contract($this->afternoon, 'CF-STATUS');
        $id = $contract->getId();
        $client = $this->client();
        $url = $this->url('app_administrateur_formateurs_contrats_edit', ['id' => $id]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('#contratForm')->form();
        $form['contrat_formateur[status]'] = 'ENVOYE';
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $saved = $this->em->find(ContratFormateur::class, $id);
        self::assertSame(ContratFormateurStatus::ENVOYE, $saved->getStatus());
        self::assertSame(1, $saved->getVersionNumero());
        self::assertSame(0, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->count([]));
    }

    public function testContractContentChangeStillRequiresReason(): void
    {
        $contract = $this->contract($this->afternoon, 'CF-CONTENT');
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_administrateur_formateurs_contrats_edit', ['id' => $contract->getId()]));
        $form = $crawler->filter('#contratForm')->form();
        $form['contrat_formateur[montantPrevuCents]'] = '350';
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Indiquez un motif', $client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->count([]));
    }

    public function testContractPreparationIsCsrfProtectedAndUsesOnlyAssignedHours(): void
    {
        $this->afternoon->setModeRemuneration('HEURE')->setTauxHoraireCents(10000); $this->em->flush();
        $client = $this->client();
        $url = $this->url('app_administrateur_session_contrat_formateur', ['id' => $this->session->getId(), 'formateur' => $this->afternoon->getId()]);
        $client->catchExceptions(true);
        $client->request('GET', $url);
        self::assertSame(405, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->catchExceptions(false);
        $crawler = $client->request('GET', $this->url('app_administrateur_session_show', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('form[action="' . $url . '"] input[name="_token"]')->attr('value');
        $client->request('POST', $url, ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $contract = $this->em->getRepository(ContratFormateur::class)->findOneBy(['formateur' => $this->afternoon]);
        self::assertNotNull($contract);
        self::assertSame(35000, $contract->getMontantPrevuCents());
        $client->request('POST', $url, ['_token' => $token]);
        self::assertSame(1, $this->em->getRepository(ContratFormateur::class)->count([]));
    }

    public function testContractLibraryHasAdminActionsAndPdfUsesOnlyOwnSlot(): void
    {
        $contract = $this->contract($this->afternoon, 'CF-TEST');
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_formateurs_contrats_list'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', $this->url('app_administrateur_formateurs_contrats_ajax'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['recordsTotal']);
        self::assertStringContainsString('/voir', $data['data'][0]['actions']);
        $client->request('GET', $this->url('app_administrateur_formateurs_contrats_show', ['id' => $contract->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $document = self::getContainer()->get(\App\Service\Pdf\ContratFormateurDocument::class);
        $html = self::getContainer()->get('twig')->render('pdf/contrat_formateur.html.twig', $document->templateData($contract));
        self::assertStringContainsString('13h30', $html);
        self::assertStringNotContainsString('09h00', $html);
        self::assertStringContainsString('0,5 jour', $html);
        $contract = $this->em->find(ContratFormateur::class, $contract->getId());
        $contract->setStatus(ContratFormateurStatus::SIGNE)->setSignatureAt(new \DateTimeImmutable()); $this->em->flush();
        $client->request('GET', $this->url('app_administrateur_formateurs_contrats_pdf', ['id' => $contract->getId()]));
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertNull($contract->getPdfPath());
    }

    public function testQuoteCanExplicitlyRecoverAnUnsignedConventionAndThenDisplaysIt(): void
    {
        $company = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client test');
        $quote = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setEntrepriseDestinataire($company)->setFormation($this->session->getFormation())->setNumero('DEV-RECOVERY');
        $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setEntreprise($company)->setNumero('CONV-RECOVERY')->setEffectifPrevisionnel(2);
        foreach ([$company, $quote, $convention] as $entity) $this->em->persist($entity); $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_administrateur_devis_show', ['id' => $quote->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Rattacher la convention')->form([
            'devis_convention_link[convention]' => (string) $convention->getId(), 'devis_convention_link[confirmation]' => '1',
        ]);
        $client->submit($form); self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($quote->getId(), $this->em->find(ConventionContrat::class, $convention->getId())->getDevis()?->getId());
        self::assertStringContainsString('CONV-RECOVERY', $crawler->filter('body')->text());
    }

    public function testSessionEditorPreservesPerSlotAssignmentsAndRejectsOverlap(): void
    {
        $client = $this->client();
        $url = $this->url('app_administrateur_session_modifier', ['id' => $this->session->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(2, $crawler->filter('[data-add-session-slot]')->count());
        self::assertSame('2', $crawler->filter('#jours-collection')->attr('data-next-index'));
        $form = $crawler->filter('form#session')->form();
        $client->submit($form, ['session[jours][1][dateDebut]' => '05/10/2026 12:00']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('se chevauchent', $client->getResponse()->getContent());
        $crawler = $client->request('GET', $url);
        $client->submit($crawler->filter('form#session')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $session = $this->em->find(Session::class, $this->session->getId());
        self::assertSame($this->afternoon->getId(), $session->getJours()->last()->getFormateur()->getId());
        self::assertCount(0, $session->getInscriptions(), 'Une session doit pouvoir être planifiée avant les inscriptions.');
    }

    public function testSessionEditorRejectsRemovingEverySlotWithoutDeletingTheSavedPlanning(): void
    {
        $client = $this->client();
        $sessionId = $this->session->getId();
        $url = $this->url('app_administrateur_session_modifier', ['id' => $sessionId]);
        $crawler = $client->request('GET', $url);
        $values = $crawler->filter('form#session')->form()->getPhpValues();
        $values['session']['jours'] = [];

        $crawler = $client->request('POST', $url, $values);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ajoutez au moins une journée à la session.', $crawler->text());
        self::assertSame(0, $crawler->filter('#jours-collection .jour-item')->count());
        $this->em->clear();
        self::assertCount(2, $this->em->find(Session::class, $sessionId)->getJours(), 'Un formulaire invalide ne doit pas supprimer les créneaux enregistrés.');
    }

    public function testContractDocumentsRejectAnEntityFromAnotherOrganism(): void
    {
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($other); $this->em->flush();
        $contract = $this->contract($this->afternoon, 'CF-FOREIGN');
        $contract->setEntite($other); $this->em->flush();
        $client = $this->client(); $client->catchExceptions(true);
        foreach (['show', 'pdf', 'edit'] as $action) {
            $client->request('GET', $this->url('app_administrateur_formateurs_contrats_' . $action, ['id' => $contract->getId()]));
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
        }
        $client->request('POST', $this->url('app_administrateur_formateurs_contrats_ajax'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true)['recordsTotal']);
    }

    public function testAttendanceGroupsSlotsByDateAndAllowsTheSecondTrainer(): void
    {
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createLandscape')->willReturnCallback(static fn(string $html) => new \Symfony\Component\HttpFoundation\Response($html));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $client = $this->client(); $client->loginUser($this->afternoon->getUtilisateur());
        $client->request('GET', $this->url('app_formateur_emargement_feed'), ['session' => $this->session->getId(), 'date' => '2026-10-05']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['trainer']['scheduled_am']);
        self::assertTrue($data['trainer']['scheduled_pm']);
        $crawler = $client->request('GET', $this->url('app_formateur_emargement_liste', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, $crawler->filter('tbody tr')->count());
        $crawler = $client->request('GET', $this->url('app_formateur_emargement_pdf'), ['session' => $this->session->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, $crawler->filter('.day-row')->count());
        self::assertStringContainsString('09:00 à 12:30 · 13:30 à 17:00', $crawler->text());
        $client->request('POST', $this->url('app_formateur_emargement_sign', ['id' => $this->session->getId()]), ['periode' => 'AM', 'date' => '05/10/2026', 'signatureData' => 'data:image/png;base64,invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testQuickTrainerCreationDoesNotTransferAnotherOrganismsProfile(): void
    {
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($other); $this->afternoon->setEntite($other); $this->em->flush();
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_formateur_new'), ['prenom' => 'Test', 'nom' => 'Formateur', 'email' => $this->afternoon->getUtilisateur()->getEmail()]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame($other->getId(), $this->em->find(Formateur::class, $this->afternoon->getId())->getEntite()->getId());
        $client->request('POST', $this->url('app_administrateur_session_formateur_new'), ['prenom' => 'Nouveau', 'nom' => 'Formateur', 'email' => 'new.trainer@example.test']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue(json_decode($client->getResponse()->getContent(), true)['success']);
    }

    public function testRecoveryRefusesAnAlreadySignedConvention(): void
    {
        $company = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client');
        $quote = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setEntrepriseDestinataire($company)->setFormation($this->session->getFormation())->setNumero('DEV-SIGNED');
        $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setEntreprise($company)->setNumero('CONV-SIGNED')->setEffectifPrevisionnel(2)->setDateSignatureEntreprise(new \DateTimeImmutable());
        foreach ([$company, $quote, $convention] as $entity) $this->em->persist($entity); $this->em->flush();
        self::assertSame([], $this->em->getRepository(ConventionContrat::class)->findAttachableToDevis($quote));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('signée');
        self::getContainer()->get(\App\Service\Convention\DevisConventionLinker::class)->link($quote, $convention);
    }

    public function testGuestAttendanceAppearsInTrainerFeedSummaryAndPdf(): void
    {
        $participant = $this->guest('Camille Sans Compte');
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==';
        $attendance = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)
            ->setParticipantAccess($participant)->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode(\App\Enum\DemiJournee::AM)->setRole('stagiaire')
            ->setSignatureDataUrl($signature)->setSignedAt(new \DateTimeImmutable());
        $this->em->persist($attendance); $this->em->flush();
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createLandscape')->willReturnCallback(static fn(string $html) => new \Symfony\Component\HttpFoundation\Response($html));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $client = $this->client(); $client->loginUser($this->afternoon->getUtilisateur());
        $client->request('GET', $this->url('app_formateur_emargement_feed'), ['session' => $this->session->getId(), 'date' => '2026-10-05']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['trainees']);
        self::assertSame('Camille Sans Compte', $data['trainees'][0]['name']);
        self::assertTrue($data['trainees'][0]['signed_am']);
        self::assertFalse($data['trainees'][0]['signed_pm']);
        self::assertSame($signature, $data['trainees'][0]['signature_am_url']);
        $crawler = $client->request('GET', $this->url('app_formateur_emargement_liste', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 / 4', preg_replace('/\s+/', ' ', $crawler->filter('tbody')->text()));
        $crawler = $client->request('GET', $this->url('app_formateur_emargement_pdf'), ['session' => $this->session->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Camille Sans Compte', $crawler->text());
        self::assertStringContainsString($signature, $client->getResponse()->getContent());
    }

    public function testGuestSatisfactionIsSearchableAndReadableByAdmin(): void
    {
        $participant = $this->guest('Alex Sans Compte');
        $template = (new \App\Entity\SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Appréciation');
        $assignment = (new \App\Entity\SatisfactionAssignment())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setTemplate($template)->setParticipantAccess($participant);
        $attempt = (new \App\Entity\SatisfactionAttempt())->setAssignment($assignment)->setEntite($this->entite)->setCreateur($this->admin)->setStartedAt(new \DateTimeImmutable())->setSubmittedAt(new \DateTimeImmutable())->setNoteGlobale(5);
        $assignment->setAttempt($attempt);
        foreach ([$template, $assignment, $attempt] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $client = $this->client();
        foreach ([['search' => ['value' => 'Alex']], ['stagiaireFilter' => 'Sans Compte']] as $filter) {
            $client->request('POST', $this->url('app_administrateur_satisfaction_assignment_ajax'), $filter);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertSame(1, $data['recordsFiltered']);
            self::assertSame('Alex Sans Compte', $data['data'][0]['stagiaire']);
            self::assertSame('Sans compte', $data['data'][0]['email']);
        }
        $crawler = $client->request('GET', $this->url('app_administrateur_satisfaction_attempt_show', ['attempt' => $attempt->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Alex Sans Compte', $crawler->text());
    }

    public function testGuestAttendanceSurvivesSessionEditingWithoutAccountInscriptions(): void
    {
        $participant = $this->guest('Camille Sans Compte');
        $attendance = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)
            ->setParticipantAccess($participant)->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode(\App\Enum\DemiJournee::AM)->setRole('stagiaire')->setSignedAt(new \DateTimeImmutable());
        $this->em->persist($attendance); $this->em->flush();
        $id = $attendance->getId();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_administrateur_session_modifier', ['id' => $this->session->getId()]));
        $client->submit($crawler->filter('form#session')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNotNull($this->em->find(\App\Entity\Emargement::class, $id), 'Une signature sans compte doit rester conservée.');
    }

    public function testGuestAttendanceIsCountedAndNamedInAdministrativeFollowUp(): void
    {
        $participant = $this->guest('Camille Sans Compte');
        $yesterday = new \DateTimeImmutable('yesterday');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $this->session->getJours()->first()->setDateDebut($yesterday->setTime(9, 0))->setDateFin($yesterday->setTime(12, 30));
        $this->session->getJours()->last()->setDateDebut($tomorrow->setTime(13, 30))->setDateFin($tomorrow->setTime(17, 0));
        $attendance = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)
            ->setParticipantAccess($participant)->setDateJour($yesterday)->setPeriode(\App\Enum\DemiJournee::AM)->setRole('stagiaire');
        $this->em->persist($attendance); $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['unsignedEmargements']);
        self::assertSame('Camille Sans Compte', $data['unsignedEmargements'][0]['userLabel']);
        self::assertNull($data['unsignedEmargements'][0]['userId']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true)['unsignedEmargements']);
        $client->request('POST', $this->url('app_administrateur_session_ajax'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('0/4 signés', json_decode($client->getResponse()->getContent(), true)['data'][0]['dossier']);
        $this->em->find(\App\Entity\SessionParticipantAccess::class, $participant->getId())->setActive(false); $this->em->flush();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertCount(0, json_decode($client->getResponse()->getContent(), true)['unsignedEmargements']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true)['unsignedEmargements']);
    }

    public function testGuestWithoutAnyAttendanceEntryAppearsAmongMissingSignatures(): void
    {
        $guest = $this->guest('Participant Papier');
        $yesterday = new \DateTimeImmutable('yesterday');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $this->session->getJours()->first()->setDateDebut($yesterday->setTime(9, 0))->setDateFin($yesterday->setTime(12, 30));
        $this->session->getJours()->last()->setDateDebut($tomorrow->setTime(13, 30))->setDateFin($tomorrow->setTime(17, 0));
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['unsignedEmargements']);
        self::assertSame('Participant Papier', $data['unsignedEmargements'][0]['userLabel']);
        self::assertSame('missing', $data['unsignedEmargements'][0]['type']);
        self::assertSame($guest->getId(), $data['unsignedEmargements'][0]['participantId']);
        self::assertNull($data['unsignedEmargements'][0]['userId']);
    }

    public function testCompanyPdfIncludesOnlyItsOwnGuestAndTrainerCsvKeepsGuestName(): void
    {
        $own = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client A');
        $other = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client B');
        $this->em->persist($own); $this->em->persist($other);
        $this->admin->setEntreprise($own);
        foreach ([[$own, 'Camille Client A'], [$other, 'Alex Client B']] as [$company, $name]) {
            $convention = (new ConventionContrat())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setEntreprise($company)->setNumero('CONV-' . $name);
            $this->em->persist($convention);
            $guest = $this->guest($name)->setConvention($convention);
            $attendance = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)
                ->setParticipantAccess($guest)->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode(\App\Enum\DemiJournee::AM)->setRole('stagiaire')
                ->setSignatureDataUrl('data:image/png;base64,dGVzdA==')->setSignedAt(new \DateTimeImmutable());
            $this->em->persist($convention); $this->em->persist($attendance);
        }
        $this->em->flush();
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createLandscape')->willReturnCallback(static fn(string $html) => new \Symfony\Component\HttpFoundation\Response($html));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_entreprise_documents_emargement_sheet_pdf', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Camille Client A', $crawler->text());
        self::assertStringNotContainsString('Alex Client B', $crawler->text());
        $client->loginUser($this->afternoon->getUtilisateur());
        $client->request('GET', $this->url('app_formateur_emargement_export', ['id' => $this->session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Camille Client A', $client->getInternalResponse()->getContent());
        self::assertStringContainsString('Alex Client B', $client->getInternalResponse()->getContent());
        self::assertStringContainsString('2026-10-05,AM,', $client->getInternalResponse()->getContent());
    }

    private function guest(string $name): \App\Entity\SessionParticipantAccess
    {
        $guest = (new \App\Entity\SessionParticipantAccess())->setSession($this->session)->setEntite($this->entite)->setSourceKey('guest:test:' . md5($name))->setDisplayName($name);
        $this->em->persist($guest); $this->em->flush();
        return $guest;
    }

    private function trainer(string $name): Formateur
    {
        $u = (new Utilisateur())->setEntite($this->entite)->setCreateur($this->admin)->setEmail(bin2hex(random_bytes(4)) . '@example.test')->setPassword('unused')->setPrenom($name)->setNom('Formateur');
        $f = (new Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($u)->setAssujettiTva(false);
        $u->setFormateur($f); $this->membership($u, 'TENANT_FORMATEUR');
        $this->em->persist($u); $this->em->persist($f);
        return $f;
    }

    private function membership(Utilisateur $u, string $role): void
    {
        $u->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($u)->setEntite($this->entite)->setCreateur($this->admin)->setRoles([$role]));
    }

    private function slot(string $start, string $end, ?Formateur $trainer = null): SessionJour
    {
        return (new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable($start))->setDateFin(new \DateTimeImmutable($end))->setFormateur($trainer);
    }

    private function contract(Formateur $f, string $numero): ContratFormateur
    {
        $c = (new ContratFormateur())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setFormateur($f)->setNumero($numero);
        $this->em->persist($c); $this->em->flush(); return $c;
    }

    public function testSupportTraineesIncludeAllSelectedSessionsWithoutDuplicates(): void
    {
        $second = (new Session())->setEntite($this->entite)->setCreateur($this->admin)
            ->setFormation($this->session->getFormation())->setSite($this->session->getSite())->setCode('SES-SUPPORT');
        $this->em->persist($second);
        $this->em->persist((new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($second)->setStagiaire($this->admin));
        $this->em->flush();
        $client = $this->client();
        $url = $this->url('app_formateur_supports_trainees_feed');
        $ids = [$this->session->getId(), $second->getId()];
        $client->request('GET', $url, ['sessionIds' => $ids]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([$this->admin->getId()], array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'id'));

        $this->em->persist((new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($this->admin));
        $this->em->flush();
        $client->request('GET', $url, ['sessionIds' => $ids]);
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['data']);
        $client->request('GET', $url, ['sessionId' => $second->getId()]);
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['data']);
        $client->request('GET', $url);
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
        $client->request('GET', $url, ['sessionIds' => [$second->getId(), 999999]]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin); return $client;
    }

    private function url(string $route, array $params = []): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()] + $params);
    }
}
