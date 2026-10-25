<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{ContratFormateur, Entite, EntitePreferences, Formateur, Session, SessionJour, Site, Utilisateur, UtilisateurEntite, Inscription, Emargement, SessionPiece};
use App\Enum\{ContratFormateurStatus, DemiJournee, SessionPieceType, StatusSession};
use App\Service\TrainerReminder\TrainerReminderRunner;
use App\Service\Session\TrainerSessionFollowUp;
use App\Service\Email\MailerManager;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Psr\Log\NullLogger;

final class TrainerRemindersTest extends KernelTestCase
{
    private array $database;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $user;
    private Formateur $trainer;
    private Session $session;
    private ContratFormateur $contract;
    private EntitePreferences $preferences;
    private TrainerReminderRunner $runner;
    private array $messages = [];
    private bool $fail = false;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('trainer@reminder.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_FORMATEUR', 'TENANT_ADMIN']));
        // Ownership scenarios require two trainers in this organism.
        $plan = (new \App\Entity\Billing\Plan())->setCode('TRAINERREMINDERS')->setName('Isolation fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(2)->setMaxEntreprises(1)->setMaxProspects(50);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->trainer = (new Formateur())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user);
        $this->user->setFormateur($this->trainer);
        $site = (new Site())->setNom('Salle')->setSlug('reminder-test')->setEntite($this->entite)->setCreateur($this->user);
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('SES-REMINDER')->setSite($site)->setFormateur($this->trainer)->setStatus(StatusSession::IN_PROGRESS)->setFormationIntituleLibre('Mission test');
        $this->session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2026-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-01 17:00')));
        $this->contract = (new ContratFormateur())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setFormateur($this->trainer)->setNumero('CF-REMINDER');
        $this->preferences = (new EntitePreferences())->setEntite($this->entite)->setCreateur($this->user);
        $this->entite->setPreferences($this->preferences);
        foreach ([$this->trainer, $site, $this->session, $this->contract, $this->preferences] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $transport = $this->createMock(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function ($email): void { if ($this->fail) throw new \RuntimeException('Simulated transport failure'); $this->messages[] = $email; });
        $twig = self::getContainer()->get('twig'); $router = self::getContainer()->get('router');
        $this->runner = new TrainerReminderRunner($this->em, new TrainerSessionFollowUp(), new MailerManager($transport, $twig, $router), $twig, $router, new NullLogger());
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function configure(array $settings): void { $this->preferences->setTrainerReminders($settings); $this->em->flush(); }
    private function candidates(string $date = '2026-10-08 10:00 UTC'): array { return iterator_to_array($this->runner->candidates($this->entite, new \DateTimeImmutable($date)), false); }
    private function send(array $message, string $date = '2026-10-08 10:00 UTC'): string { return $this->runner->send($message, new \DateTimeImmutable($date)); }

    public function testMissingReportCanBeRemindedAndDisappearsAfterSubmission(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(function ($mail) {
            self::assertStringContainsString('Compléter mon compte rendu', $mail->getHtmlBody());
            self::assertStringContainsString('cid:trainer-compliance-qr', $mail->getHtmlBody());
            self::assertCount(1, $mail->getAttachments());
            self::assertSame('image', $mail->getAttachments()[0]->getMediaType());
            self::assertSame('png', $mail->getAttachments()[0]->getMediaSubtype());
        });
        $twig = self::getContainer()->get('twig'); $router = self::getContainer()->get('router');
        $service = new \App\Service\TrainerReminder\TrainerCompliance($this->em, new \App\Service\TrainerCharter\CharterManager($this->em), $router, new MailerManager($transport, $twig, $router), $twig, new NullLogger());
        $key = 'report:'.$this->session->getId().':'.$this->trainer->getId();
        self::assertArrayHasKey($key, $service->pending($this->entite));
        self::assertSame('sent', $service->send($this->entite, $key));
        self::assertSame('skipped', $service->send($this->entite, $key));
        foreach ([StatusSession::DRAFT, StatusSession::CANCELED, StatusSession::DONE] as $status) {
            $this->session->setStatus($status);
            self::assertArrayNotHasKey($key, $service->pending($this->entite));
        }
        $this->session->setStatus(StatusSession::IN_PROGRESS);
        $report = (new \App\Entity\RapportFormateur())->setEntite($this->entite)->setFormateur($this->trainer)->setSession($this->session);
        $this->session->getRapportFormateurs()->add($report);
        self::assertArrayNotHasKey($key, $service->pending($this->entite));
        self::assertSame('skipped', $service->send($this->entite, $key));
    }

    public function testAdministratorCanCorrectActualTrainerWithAudit(): void
    {
        $this->contract->setStatus(ContratFormateurStatus::SIGNE)->setSignatureAt(new \DateTimeImmutable('2026-09-30 10:00'))->setSignatureDataUrl('data:image/png;base64,contract-original');
        $replacementUser = (new Utilisateur())->setEmail('replacement@test.example')->setPassword('unused')->setPrenom('Actual')->setNom('Trainer');
        $replacement = (new Formateur())->setUtilisateur($replacementUser)->setEntite($this->entite)->setCreateur($this->user);
        $this->em->persist($replacementUser); $this->em->persist($replacement);
        $signature = (new Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->user)->setUtilisateur($this->user)->setRole('formateur')->setDateJour(new \DateTimeImmutable('2026-10-01'))->setPeriode(DemiJournee::AM)->setSignatureDataUrl('data:image/png;base64,original');
        $this->em->persist($signature); $this->em->flush();
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->user);
        $url = '/fr/administrateur/'.$this->entite->getId().'/session/'.$this->session->getId().'/corriger-intervenant';
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(2, $page->filter('select.tc-trainer-select'));
        self::assertStringContainsString('CF-REMINDER', $client->getResponse()->getContent());
        $form = $page->selectButton('Enregistrer la correction')->form([
            'form[source]' => $this->trainer->getId(), 'form[target]' => $replacement->getId(),
            'form[reason]' => 'Le remplaçant a assuré et signé la formation.', 'form[confirm]' => '1',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $signature = $this->em->find(Emargement::class, $signature->getId());
        $this->session = $this->em->find(Session::class, $this->session->getId());
        self::assertSame($replacementUser->getId(), $signature->getUtilisateur()->getId());
        self::assertSame('data:image/png;base64,original', $signature->getSignatureDataUrl());
        self::assertSame($replacement->getId(), $this->session->getFormateur()->getId());
        $this->contract = $this->em->find(ContratFormateur::class, $this->contract->getId());
        self::assertSame($this->trainer->getId(), $this->contract->getFormateur()->getId());
        self::assertSame($this->session->getId(), $this->contract->getSession()->getId());
        self::assertSame(ContratFormateurStatus::SIGNE, $this->contract->getStatus());
        self::assertSame('data:image/png;base64,contract-original', $this->contract->getSignatureDataUrl());
        self::assertSame('2026-09-30 10:00', $this->contract->getSignatureAt()->format('Y-m-d H:i'));
        self::assertSame(1, $this->em->getRepository(ContratFormateur::class)->count(['session'=>$this->session]));
        $page = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('CF-REMINDER', $page->filter('#session-contracts')->text());
        self::assertStringContainsString('Camille Test', $page->filter('#session-contracts')->text());
        self::assertSame(1, $this->em->getRepository(\App\Entity\AuditLog::class)->count(['event'=>'trainer_attendance_attribution_corrected']));
    }

    public function testManualComplianceTransportAndSignatureResolution(): void
    {
        $transport=$this->createMock(MailerInterface::class);
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function ($mail) { if ($this->fail) throw new \RuntimeException('Simulated failure'); $this->messages[]=$mail; });
        $twig=self::getContainer()->get('twig');$router=self::getContainer()->get('router');
        $service=new \App\Service\TrainerReminder\TrainerCompliance($this->em,new \App\Service\TrainerCharter\CharterManager($this->em),$router,new MailerManager($transport,$twig,$router),$twig,new NullLogger());
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE);$this->em->flush();
        $key='contract:'.$this->contract->getId().':'.$this->contract->getVersionNumero();
        self::assertArrayHasKey($key,$service->pending($this->entite));
        self::assertSame('sent',$service->send($this->entite,$key));
        self::assertSame('skipped',$service->send($this->entite,$key));
        self::assertStringContainsString('Consulter et signer',$this->messages[0]->getHtmlBody());
        $this->contract->setSignatureAt(new \DateTimeImmutable());$this->em->flush();
        self::assertArrayNotHasKey($key,$service->pending($this->entite));
        $this->fail=true;$key='diploma:'.$this->trainer->getId();
        self::assertSame('unknown',$service->send($this->entite,$key));
        self::assertSame('skipped',$service->send($this->entite,$key));
    }

    public function testPublishedCharterCanBeRequestedFromDashboardAndAppearsForTrainer(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send');
        $twig = self::getContainer()->get('twig'); $router = self::getContainer()->get('router');
        $manager = new \App\Service\TrainerCharter\CharterManager($this->em);
        $service = new \App\Service\TrainerReminder\TrainerCompliance($this->em, $manager, $router, new MailerManager($transport, $twig, $router), $twig, new NullLogger());
        $charter = new \App\Entity\TrainerCharter(); $charter->entite = $this->entite;
        $version = new \App\Entity\TrainerCharterVersion(); $version->charter = $charter; $version->title = 'Charte test';
        $this->em->persist($charter); $this->em->persist($version); $this->em->flush();
        $key = 'charter-request:'.$version->id.':'.$this->trainer->getId();
        self::assertArrayNotHasKey($key, $service->pending($this->entite));
        self::assertSame([], $manager->available($this->entite, $this->trainer));
        $version->publishedAt = new \DateTimeImmutable(); $this->em->flush();
        self::assertArrayHasKey($key, $service->pending($this->entite));
        self::assertSame([$version], $manager->available($this->entite, $this->trainer));
        self::assertSame('sent', $service->send($this->entite, $key));
        $signatures = $manager->forTrainer($this->entite, $this->trainer);
        self::assertCount(1, $signatures);
        $signature = $signatures[0];
        self::assertSame([], $manager->available($this->entite, $this->trainer));
        $row = $service->pending($this->entite)['charter:'.$signature->id];
        self::assertSame('sent', $row['history']['status']);
        self::assertFalse((bool) $row['available']);
        self::assertSame('skipped', $service->send($this->entite, $key));
        $signature->signedAt = new \DateTimeImmutable(); $this->em->flush();
        self::assertArrayNotHasKey('charter:'.$signature->id, $service->pending($this->entite));
    }

    public function testInitialNoticeDelayRepeatsAndNoDuplicates(): void
    {
        self::assertSame([], $this->candidates());
        $this->configure(['contractNotice' => true, 'contractReminder' => true, 'contractDelay' => 3, 'repeatDays' => 7, 'maxReminders' => 2]);
        self::assertSame([], $this->candidates(), 'A draft is never mailed');
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE); $this->em->flush();
        $notice = $this->candidates()[0];
        self::assertSame('contract_notice', $notice['kind']);
        self::assertSame('sent', $this->send($notice));
        self::assertSame('skipped', $this->send($notice));
        self::assertCount(1, $this->messages);
        self::assertStringContainsString('/fr/formateur/'.$this->entite->getId().'/contrat/'.$this->contract->getId().'/sign', $this->messages[0]->getHtmlBody());
        self::assertSame([], $this->candidates('2026-10-11 09:59 UTC'));
        $first = $this->candidates('2026-10-11 10:00 UTC')[0];
        self::assertSame('contract_reminder', $first['kind']);
        self::assertSame('sent', $this->send($first, '2026-10-11 10:00 UTC'));
        self::assertSame([], $this->candidates('2026-10-18 09:59 UTC'));
        self::assertSame('sent', $this->send($this->candidates('2026-12-01 10:00 UTC')[0], '2026-12-01 10:00 UTC'));
        self::assertSame([], $this->candidates('2027-01-01 10:00 UTC'), 'No backlog burst or reminders beyond maximum');
    }
    public function testSignatureClosedSessionInactiveMembershipAndTenantScope(): void
    {
        $this->configure(['contractNotice' => true]);
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE); $this->em->flush();
        $stale = $this->candidates()[0];
        $this->contract->setSignatureAt(new \DateTimeImmutable()); $this->em->flush();
        self::assertSame('skipped', $this->send($stale));
        $this->contract->setSignatureAt(null);
        foreach ([StatusSession::DONE, StatusSession::CANCELED] as $status) {
            $this->session->setStatus($status); $this->em->flush(); self::assertSame([], $this->candidates());
        }
        $this->session->setStatus(StatusSession::FULL); $this->em->flush();
        self::assertCount(1, $this->candidates(), 'Full capacity is not administrative completion');
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->user]);
        $membership->setStatus('suspended'); $this->em->flush();
        self::assertSame([], $this->candidates());
        $membership->setStatus('active');
        $other = (new Entite())->setNom('Autre')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($other); $this->trainer->setEntite($other); $this->em->flush();
        self::assertSame([], $this->candidates());
        self::assertCount(0, $this->messages);
    }
    public function testAmbiguousTransportDoesNotRetryAndNewVersionHasOwnCycle(): void
    {
        $this->configure(['contractNotice' => true, 'contractReminder' => true]);
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE); $this->em->flush();
        $this->fail = true;
        self::assertSame('unknown', $this->send($this->candidates()[0]));
        self::assertSame([], $this->candidates('2027-01-01'));
        $this->contract->incrementVersion(); $this->em->flush(); $this->fail = false;
        self::assertSame('sent', $this->send($this->candidates()[0]));
        self::assertCount(1, $this->messages);
    }
    public function testAfterTrainingPaperPresenceAndValidatedReportStopIndependently(): void
    {
        $learner = (new Utilisateur())->setEmail('learner@reminder.test')->setPassword('unused')->setPrenom('Alex')->setNom('Test');
        $inscription = (new Inscription())->setStagiaire($learner)->setEntite($this->entite)->setCreateur($this->user);
        $this->session->addInscription($inscription);
        $this->em->persist($learner); $this->em->persist($inscription);
        $this->configure(['attendanceReminder' => true, 'satisfactionReminder' => true]);
        self::assertSame([], $this->candidates('2026-10-03 14:59 UTC'));
        self::assertCount(1, $this->candidates('2026-10-03 15:00 UTC'));
        self::assertCount(2, $this->candidates());
        foreach ([DemiJournee::AM, DemiJournee::PM] as $period) {
            $inscription->enregistrerPresenceManuelle('2026-10-01:'.$period->value, 'paper', 'Feuille déposée', $this->user);
            $e = (new Emargement())->setEntite($this->entite)->setCreateur($this->user)->setUtilisateur($this->user)->setRole('trainer')->setDateJour(new \DateTimeImmutable('2026-10-01'))->setPeriode($period)->setSignedAt(new \DateTimeImmutable());
            $this->session->addEmargement($e); $this->em->persist($e);
        }
        $this->em->flush();
        self::assertSame(['satisfaction'], array_column($this->candidates(), 'kind'));
        $piece = (new SessionPiece())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->user)->setType(SessionPieceType::COMPTE_RENDU_STAGIAIRE)->setFilename('report.pdf');
        $this->session->addPiece($piece); $this->em->persist($piece); $this->em->flush();
        self::assertCount(1, $this->candidates(), 'Unvalidated upload does not close follow-up');
        $piece->setValide(true); $this->em->flush();
        self::assertSame([], $this->candidates());
    }
    public function testDisabledCategoryAndClosedAttendanceSkipExistingCandidates(): void
    {
        $this->configure(['attendanceReminder' => true]);
        $message = $this->candidates()[0];
        $this->session->cloturerEmargements('Administrateur'); $this->em->flush();
        self::assertSame('skipped', $this->send($message));
        $this->session->rouvrirEmargements();
        $this->session->setTypeFinancement(\App\Enum\TypeFinancement::OUI); $this->em->flush();
        self::assertCount(0, $this->candidates(), 'Subcontracted attendance is not chased');
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE);
        $this->configure(['contractNotice' => true]);
        $message = $this->candidates()[0];
        $this->configure([]);
        self::assertSame('skipped', $this->send($message));
        self::assertCount(0, $this->messages);
    }
    public function testReadyActionRequiresCsrfAndQueuesTheCorrectVersion(): void
    {
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->user);
        $router = self::getContainer()->get('router');
        $args = ['entite' => $this->entite->getId(), 'id' => $this->contract->getId()];
        $page = $client->request('GET', $router->generate('app_administrateur_formateurs_contrats_show', $args));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submit($page->selectButton('Prêt à signer')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $contract = $this->em->find(ContratFormateur::class, $this->contract->getId());
        self::assertSame(ContratFormateurStatus::ENVOYE, $contract->getStatus());
        self::assertCount(0, $this->messages, 'The web action never bypasses the scheduler and its delivery journal');
        $client->catchExceptions(true);
        $client->request('POST', $router->generate('app_administrateur_formateurs_contrats_ready', $args));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testSettingsFormAndTenantNavigation(): void
    {
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->user);
        $url = self::getContainer()->get('router')->generate('app_administrateur_preferences_formateurs_relances', ['entite' => $this->entite->getId()]);
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $page->selectButton('Enregistrer les paramètres')->form();
        $form['trainer_reminder_settings[contractNotice]']->tick();
        $form['trainer_reminder_settings[contractReminder]']->tick();
        $form['trainer_reminder_settings[contractDelay]'] = 5;
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($url, $client->getResponse()->headers->get('Location'));
        $this->preferences = $this->em->getRepository(EntitePreferences::class)->findOneBy(['entite' => $this->entite]);
        self::assertTrue($this->preferences->getTrainerReminders()['contractNotice']);
        self::assertSame(5, $this->preferences->getTrainerReminders()['contractDelay']);
        $page = $client->request('GET', $url); $form = $page->selectButton('Enregistrer les paramètres')->form();
        $form['trainer_reminder_settings[repeatDays]'] = 0;
        $client->submit($form);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotSame(0, $this->preferences->getTrainerReminders()['repeatDays']);
    }
}
