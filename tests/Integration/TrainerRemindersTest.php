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
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
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
