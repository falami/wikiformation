<?php

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Enum\{DemiJournee, SessionPieceType, StatusSession, TypeFinancement};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Attendance alerts are tested against an isolated database, including legacy records. */
final class AttendanceScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private Session $internal;
    private Session $subcontracted;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('attendance-admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->learner = (new Utilisateur())->setEmail('attendance-learner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->learner, $this->entite] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('attendance-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->internal = $this->session('SES-INTERNAL', $this->entite, false);
        $this->subcontracted = $this->session('SES-SUBCONTRACTED', $this->entite, true);
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($other);
        $foreign = $this->session('SES-FOREIGN', $other, false);
        foreach ([$this->internal, $this->subcontracted, $foreign] as $session) {
            $this->em->persist((new Emargement())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setUtilisateur($this->learner)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('yesterday'))->setPeriode(DemiJournee::AM));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testDashboardExcludesExternalAttendanceFromMissingAndUnsignedAlerts(): void
    {
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertCount(2, $alerts);
        self::assertSame(['missing', 'unsigned'], array_column($alerts, 'type'));
        self::assertSame(['SES-INTERNAL', 'SES-INTERNAL'], array_column($alerts, 'sessionLabel'));
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
        self::assertSame(3, $this->em->getRepository(Emargement::class)->count([]), 'Existing records are retained, including subcontracted sessions.');
    }

    public function testStoredSignatureImageIsNotReportedAsUnsigned(): void
    {
        $this->em->getRepository(Emargement::class)->findOneBy(['session' => $this->internal])->setSignaturePath('signatures/existing.png');
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertCount(1, $alerts);
        self::assertSame('missing', $alerts[0]['type']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
    }

    public function testSessionListDoesNotRequireExternalSignaturesForCompleteness(): void
    {
        self::assertTrue($this->internal->isEmargementRequis());
        self::assertFalse($this->subcontracted->isEmargementRequis());
        foreach ([SessionPieceType::CONTRAT_FORMATEUR_SIGNE, SessionPieceType::EMARGEMENT_SIGNE, SessionPieceType::COMPTE_RENDU_FORMATEUR, SessionPieceType::COMPTE_RENDU_STAGIAIRE] as $type) {
            $this->piece($this->subcontracted, $type);
        }
        $this->em->flush();
        $client = $this->client();
        foreach (['complete' => 'SES-SUBCONTRACTED', 'missing' => 'SES-INTERNAL'] as $filter => $expectedCode) {
            $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => $filter, 'length' => 10]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $body = $client->getResponse()->getContent();
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result, $body);
            self::assertSame(1, $result['recordsFiltered']);
            self::assertStringContainsString($expectedCode, $body);
            if ($filter === 'complete') {
                $html = implode(' ', $result['data'][0]);
                self::assertStringContainsString('Compte rendu formateur', $html);
                self::assertStringContainsString('Compte(s) rendu(s) stagiaires', $html);
                self::assertStringContainsString('>OK</span>', $html);
                self::assertStringNotContainsString('Conventions', $html);
                self::assertStringNotContainsString('Factures', $html);
                self::assertStringNotContainsString('À compléter', $result['data'][0]['dossier']);
                self::assertStringNotContainsString('Émargements: 0/', $html);
            }
        }
    }

    public function testUploadedAttendanceStopsMissingAndUnsignedAlerts(): void
    {
        $this->piece($this->internal, SessionPieceType::EMARGEMENT_SIGNE);
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame([], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
    }

    public function testClosedSessionsHaveNoDashboardAttendanceAlertsAndKeepTheirRecords(): void
    {
        foreach ([StatusSession::CANCELED, StatusSession::DONE] as $status) {
            $this->internal->setStatus($status);
            $this->em->flush();
            $client = $this->client();
            $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
            self::assertSame([], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
            $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
            self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
            self::assertSame(3, $this->em->getRepository(Emargement::class)->count([]));
        }
    }

    public function testPastInProgressSessionKeepsMissingAttendanceVisible(): void
    {
        $this->internal->setStatus(StatusSession::IN_PROGRESS);
        foreach ($this->internal->getJours() as $index => $jour) {
            $day = $index === 0 ? 'yesterday' : '-2 days';
            $jour->setDateDebut(new \DateTimeImmutable($day.' 08:30'))->setDateFin(new \DateTimeImmutable($day.' 17:00'));
        }
        $this->em->flush();
        self::assertTrue($this->internal->isEmargementEnAttenteRequis());
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['length' => 10]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $row = array_values(array_filter($result['data'], fn ($row) => str_contains(implode(' ', $row), 'SES-INTERNAL')))[0];
        $html = implode(' ', $row);
        self::assertStringNotContainsString('Suivi clôturé', $html);
        self::assertStringContainsString('0/4 signés', $html);
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertSame(['missing', 'missing', 'missing', 'unsigned'], array_column($alerts, 'type'));
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
    }

    public function testSubcontractedChecklistRequiresReportsButNeverConventionsOrInvoices(): void
    {
        $this->piece($this->subcontracted, SessionPieceType::CONTRAT_FORMATEUR_SIGNE);
        $this->piece($this->subcontracted, SessionPieceType::EMARGEMENT_SIGNE);
        $this->piece($this->subcontracted, SessionPieceType::COMPTE_RENDU_FORMATEUR);
        $this->em->flush();
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'missing', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $result['recordsFiltered']);
        $row = array_values(array_filter($result['data'], fn ($row) => str_contains(implode(' ', $row), 'SES-SUBCONTRACTED')))[0];
        $html = implode(' ', $row);
        self::assertStringContainsString('Compte(s) rendu(s) stagiaires', $html);
        self::assertStringContainsString('À déposer', $html);
        self::assertStringNotContainsString('Conventions', $html);
        self::assertStringNotContainsString('Factures', $html);
        // Les anciens questionnaires papier restent reconnus comme comptes rendus stagiaires.
        $this->piece($this->subcontracted, SessionPieceType::SATISFACTION_STAGIAIRE);
        $this->em->flush();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'complete', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['recordsFiltered']);
        self::assertStringContainsString('SES-SUBCONTRACTED', implode(' ', $result['data'][0]));
    }

    public function testCancelledSessionHasNoMissingDocumentsInList(): void
    {
        $this->internal->setStatus(StatusSession::CANCELED);
        $this->em->flush();
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'complete', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['recordsFiltered']);
        $html = implode(' ', $result['data'][0]);
        self::assertStringContainsString('Session annulée', $html);
        self::assertStringContainsString('Aucun document attendu', $html);
        self::assertStringNotContainsString('À compléter', $result['data'][0]['dossier']);
    }

    public static function closureModes(): iterable { yield 'success' => [false]; yield 'PDF failure rollback' => [true]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('closureModes')]
    public function testClosureCalculatesAttendanceCreatesPdfAndPreservesExistingCertificates(bool $failPdf): void
    {
        $last = $this->internal->getJours()->last(); $this->internal->removeJour($last); $this->em->remove($last);
        $date = $this->internal->getJours()->first()->getDateDebut()->format('Y-m-d');
        $registration = $this->em->getRepository(Inscription::class)->findOneBy(['session'=>$this->internal]);
        $registration->setStatus(\App\Enum\StatusInscription::CONFIRME);
        foreach (['AM','PM'] as $period) $registration->enregistrerPresenceManuelle($date.':'.$period, 'paper', 'Feuille signée', $this->admin);
        $other = (new Utilisateur())->setEmail('missing@attendance.test')->setPassword('unused')->setPrenom('Alex')->setNom('Test'); $this->em->persist($other);
        $missing = (new Inscription())->setStagiaire($other)->setSession($this->internal)->setEntite($this->entite)->setCreateur($this->admin)->setStatus(\App\Enum\StatusInscription::CONFIRME);
        $this->em->persist($registration); $this->em->persist($missing); $this->em->flush();
        $closer = self::getContainer()->get(\App\Service\Session\CloseAttendance::class);
        if ($failPdf) {
            $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
            $pdf->method('attestation')->willThrowException(new \RuntimeException('PDF failure'));
            $closer = new \App\Service\Session\CloseAttendance($this->em,
                self::getContainer()->get(\App\Service\AssiduiteCalculator::class),
                self::getContainer()->get(\App\Service\Document\RealisationCertificate::class), $pdf,
                self::getContainer()->getParameter('kernel.project_dir'));
            try { $closer->close($this->internal, $this->admin); self::fail('Failure expected'); }
            catch (\RuntimeException $error) { self::assertSame('PDF failure', $error->getMessage()); }
            self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attestation'));
            self::assertNull($this->em->getConnection()->fetchOne('SELECT emargement_cloture_at FROM session WHERE id = ?', [$this->internal->getId()]));
            return;
        }
        $path = null;
        try {
            $result = $closer->close($this->internal, $this->admin);
            self::assertSame(1, $result['created']); self::assertSame(1, $result['pending']);
            self::assertEquals(100, $registration->getTauxAssiduite());
            self::assertNull($missing->getTauxAssiduite()); self::assertNull($missing->getAttestation());
            self::assertFalse($registration->isReussi());
            $attestation = $registration->getAttestation();
            self::assertNotNull($attestation);
            $path = self::getContainer()->getParameter('kernel.project_dir').'/public/'.$attestation->getPdfPath();
            self::assertFileExists($path); self::assertSame('%PDF', file_get_contents($path, false, null, 0, 4));
            self::assertTrue(self::getContainer()->get(\App\Service\Document\RealisationCertificate::class)->available($registration));
            $result = $closer->close($this->internal, $this->admin);
            self::assertSame(0, $result['created']); self::assertSame(1, $result['existing']);
            self::assertSame(1, $this->em->getRepository(\App\Entity\Attestation::class)->count([]));
        } finally { if ($path && is_file($path)) unlink($path); }
    }

    public function testClosureRejectsFutureSlots(): void
    {
        $this->expectException(\DomainException::class);
        self::getContainer()->get(\App\Service\Session\CloseAttendance::class)->close($this->internal, $this->admin);
    }

    public function testManualClosureRequiresCsrfAndCanBeReopened(): void
    {
        $last = $this->internal->getJours()->last(); $this->internal->removeJour($last); $this->em->remove($last); $this->em->flush();
        $client = $this->client();
        $id = $this->internal->getId();
        $url = self::getContainer()->get('router')->generate('app_administrateur_session_attendance_tracking', ['entite' => $this->entite->getId(), 'id' => $id]);
        $client->catchExceptions(true);
        $client->request('POST', $url, ['action' => 'close', '_token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $crawler = $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_session_show', ['entite' => $this->entite->getId(), 'id' => $id]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#suivi-emargements input[name="_token"]')->attr('value');
        $client->request('POST', $url, ['action' => 'close', '_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $session = $this->em->find(Session::class, $id);
        self::assertNotNull($session->getEmargementClotureAt());
        self::assertSame('attendance-admin@example.test', $session->getEmargementCloturePar());
        self::assertFalse($session->isEmargementEnAttenteRequis());
        self::assertSame(StatusSession::DRAFT, $session->getStatus());
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['unsignedEmargements']);
        $client->request('POST', $url, ['action' => 'reopen', '_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $session = $this->em->find(Session::class, $id);
        self::assertNull($session->getEmargementClotureAt());
        self::assertTrue($session->isEmargementEnAttenteRequis());
        self::assertSame(3, $this->em->getRepository(Emargement::class)->count([]));
    }

    public function testPaperImportReviewAndValidationPreserveEvidenceAndRemoveOnlySelectedAlert(): void
    {
        $imports = self::getContainer()->get(\App\Service\Attendance\PaperImport::class);
        $tenant = $this->entite->getId(); $sessionId = $this->internal->getId();
        $ins = $this->em->getRepository(Inscription::class)->findOneBy(['session'=>$this->internal]);
        $target = $ins->getId().'|'.(new \DateTimeImmutable('yesterday'))->format('Y-m-d').':AM';
        $token = bin2hex(random_bytes(16)); $dir=$imports->directory($tenant,$token);
        mkdir($dir,0700,true);
        $image=imagecreatetruecolor(100,100); imagepng($image,$dir.'/view-1.png'); imagedestroy($image);
        file_put_contents($dir.'/original.pdf','%PDF-test evidence');
        $imports->save($tenant,$token,['session'=>$sessionId,'name'=>'Test.pdf','extension'=>'pdf','sha256'=>hash_file('sha256',$dir.'/original.pdf'),'pages'=>1,'texts'=>['Camille Test']]);
        try {
            $this->em->clear();
            $client=$this->client();
            $url=self::getContainer()->get('router')->generate('app_admin_attendance_import_review',['entite'=>$tenant,'token'=>$token]);
            $crawler=$client->request('GET',$url);
            self::assertSame(200,$client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('src="/dist/js/attendance-import.js', $client->getResponse()->getContent());
            self::assertStringContainsString("canvas.addEventListener('pointerdown'", $client->getResponse()->getContent(), 'Preview script must be embedded, independently of public asset deployment.');
            $previewUrl=$crawler->filter('#paper-page-link')->attr('href');
            $csrf=$crawler->filter('#paper-confirm input[name="_token"]')->attr('value');
            self::assertStringContainsString($target,$client->getResponse()->getContent());
            $client->request('GET',$previewUrl);
            self::assertSame(200,$client->getResponse()->getStatusCode());
            self::assertSame('image/png',$client->getResponse()->headers->get('Content-Type'));
            $body=['_token'=>$csrf,'confirm'=>'1','selections'=>json_encode([['target'=>$target,'page'=>1,'rect'=>[0.1,0.1,0.3,0.3]]])];
            $bad=$body;
            $bad['selections']=json_encode([
                ['target'=>$target,'page'=>1,'rect'=>[0.1,0.1,0.3,0.3]],
                ['target'=>str_replace(':AM',':PM',$target),'page'=>99,'rect'=>[0.1,0.1,0.3,0.3]],
            ]);
            $client->request('POST',$url,$bad);
            self::assertSame(200,$client->getResponse()->getStatusCode());
            self::assertSame(0,$this->em->getRepository(\App\Entity\AuditLog::class)->count(['event'=>'ATTENDANCE_PAPER_IMPORTED']),'Invalid selection must not partially import.');
            self::assertNull($this->em->getRepository(Emargement::class)->findOneBy(['session'=>$sessionId,'periode'=>DemiJournee::AM])->getSignatureDataUrl());
            $client->request('POST',$url,$body);
            self::assertSame(302,$client->getResponse()->getStatusCode(),$client->getResponse()->getContent());
            $entry=$this->em->getRepository(Emargement::class)->findOneBy(['session'=>$sessionId,'periode'=>DemiJournee::AM]);
            self::assertStringStartsWith('data:image/png;base64,',$entry->getSignatureDataUrl());
            self::assertNull($entry->getSignedAt(),'Import does not invent an electronic signing timestamp.');
            self::assertSame(1,$this->em->getRepository(\App\Entity\AuditLog::class)->count(['event'=>'ATTENDANCE_PAPER_IMPORTED']));
            $client->request('GET',$this->url('app_administrateur_dashboard_todo'));
            $alerts=json_decode($client->getResponse()->getContent(),true)['unsignedEmargements'];
            self::assertCount(1,$alerts);
            self::assertSame('Après-midi',$alerts[0]['periode']);
            $client->request('POST',$url,$body);
            self::assertSame(200,$client->getResponse()->getStatusCode());
            self::assertSame(1,$this->em->getRepository(\App\Entity\AuditLog::class)->count(['event'=>'ATTENDANCE_PAPER_IMPORTED']),'Repeated submission must not duplicate evidence.');
            $client->request('GET',$this->url('app_admin_attendance_import_index'));
            self::assertSame(200,$client->getResponse()->getStatusCode());
            self::assertStringContainsString('Test.pdf',$client->getResponse()->getContent());
            $client->catchExceptions(true);
            $client->request('POST',$url,['_token'=>'invalid']);
            self::assertSame(403,$client->getResponse()->getStatusCode());
            $other=$this->em->getRepository(Entite::class)->findOneBy(['nom'=>'Organisme B']);
            $client->request('GET',self::getContainer()->get('router')->generate('app_admin_attendance_import_document',['entite'=>$other->getId(),'token'=>$token,'page'=>1]));
            self::assertContains($client->getResponse()->getStatusCode(),[403,404]);
        } finally { (new \Symfony\Component\Filesystem\Filesystem())->remove($dir); }
    }

    private function piece(Session $session, SessionPieceType $type): void
    {
        $this->em->persist((new SessionPiece())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setType($type)->setFilename('test.pdf'));
    }

    private function session(string $code, Entite $entite, bool $subcontracted): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom($code)->setSlug(strtolower($code));
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre($code)->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setCode($code);
        if ($subcontracted) $session->setTypeFinancement(TypeFinancement::OUI)->setFormationIntituleLibre('Formation sous-traitée');
        else $session->setFormation($formation);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('yesterday 08:30'))->setDateFin(new \DateTimeImmutable('yesterday 17:00')));
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('tomorrow 08:30'))->setDateFin(new \DateTimeImmutable('tomorrow 17:00')));
        $inscription = (new Inscription())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setStagiaire($this->learner);
        $dossier = (new DossierInscription())->setInscription($inscription)->setEntite($entite)->setCreateur($this->admin);
        $piece = (new SessionPiece())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setType(SessionPieceType::CONVENTION_SIGNEE)->setFilename('convention-test.pdf');
        foreach ([$site, $formation, $session, $inscription, $dossier, $piece] as $entity) $this->em->persist($entity);
        return $session;
    }

    private function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()]);
    }
}
