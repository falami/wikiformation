<?php
namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Formation, Formateur, Site, Utilisateur, UtilisateurEntite, Facture, Paiement, Avoir, Inscription, Qcm, QcmAssignment, Session, Devis, ConventionContrat};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Enum\{FactureStatus, StatusSession, StatusInscription, QcmPhase};
use App\Service\Automation\{QuickWorkflowCreator, WorkflowPlanner, WorkflowBilling, WorkflowRunner, WorkflowActionHandler, WorkflowCertificateStorage, WorkflowPortal, WorkflowTime};
use App\Service\Sequence\SequenceNumberManager;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{Test\KernelTestCase, KernelBrowser};
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;
use Symfony\Component\Filesystem\Filesystem;

final class TrainingWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entity;
    private Utilisateur $admin;
    private array $data;
    private array $database;
    private \PHPUnit\Framework\MockObject\MockObject $mailer;
    private string $certificateDirectory;
    private WorkflowCertificateStorage $certificates;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $numbers = $this->createMock(SequenceNumberManager::class); $counter = 0;
        $numbers->method('next')->willReturnCallback(static function () use (&$counter) { return [2026, ++$counter]; });
        self::getContainer()->set(SequenceNumberManager::class, $numbers);
        $this->mailer = $this->createMock(MailerInterface::class);
        self::getContainer()->set(MailerInterface::class, $this->mailer);
        $this->certificateDirectory = sys_get_temp_dir().'/workflow-integration-certificates-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->certificateDirectory, 0700);
        $this->certificates = new WorkflowCertificateStorage($this->certificateDirectory.'/private');
        self::getContainer()->set(WorkflowCertificateStorage::class, $this->certificates);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin-workflow@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test');
        $this->entity = (new Entite())->setNom('Organisme test')->setEmail('of@example.test')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin,$this->entity] as $e) $this->em->persist($e);
        $this->em->flush(); $this->admin->setEntite($this->entity);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->entity)->setUtilisateur($this->admin)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setName('Test')->setCode('WORKFLOW_TEST'); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entity)->setPlan($plan)->setStatus('active'));
        $f = (new Formation())->setEntite($this->entity)->setCreateur($this->admin)->setTitre('SST MAC')->setSlug('sst-test');
        $company = (new Entreprise())->setEntite($this->entity)->setCreateur($this->admin)->setRaisonSociale('Entreprise test');
        $site = (new Site())->setEntite($this->entity)->setCreateur($this->admin)->setNom('Nîmes')->setSlug('nimes-test');
        $trainer = (new Formateur())->setEntite($this->entity)->setCreateur($this->admin)->setUtilisateur($this->admin);
        foreach ([$f,$company,$site,$trainer] as $e) $this->em->persist($e);
        $this->em->flush();
        $d = new \DateTimeImmutable('+40 days');
        $this->data = ['formation'=>$f,'entreprise'=>$company,'site'=>$site,'formateur'=>$trainer,'dates'=>implode(',',[$d->format('Y-m-d'),$d->modify('+1 day')->format('Y-m-d'),$d->modify('+2 days')->format('Y-m-d')]),'hours'=>7,'startTime'=>'08:30','price'=>1100,'vat'=>20,'participants'=>8,'contactEmail'=>'client@example.test','validityMonths'=>24];
    }
    protected function tearDown(): void
    { parent::tearDown(); (new Filesystem())->remove($this->certificateDirectory); foreach (['ENV','SERVER'] as $n=>$key) { if ($this->database[$n] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']); else $GLOBALS['_'.$key]['DATABASE_URL']=$this->database[$n]; } }
    private function create(): TrainingWorkflow { return self::getContainer()->get(QuickWorkflowCreator::class)->create($this->entity,$this->admin,$this->data); }

    public function testQuickCreationKeepsGroupPriceAnonymousHeadcountAndNetDuration(): void
    {
        $w=$this->create();
        self::assertNotNull($w->getId()); self::assertFalse($w->isEnabled());
        self::assertSame(8,$w->getConvention()->getEffectifTotal()); self::assertCount(0,$w->getConvention()->getInscriptions());
        self::assertSame(21.0,$w->getSession()->getDureeFormationHeures());
        self::assertSame(110000,$w->getConvention()->getDevis()->getMontantHtCents());
        self::assertSame(132000,$w->getConvention()->getDevis()->getMontantTtcCents());
        foreach ($w->getSession()->getJours() as $j) { self::assertSame('17:00',$j->getDateFin()->format('H:i')); self::assertSame(90,$j->getPauseMinutes()); }
    }
    public function testHalfDayHasNoLunchAndEarlierStartMovesEnd(): void
    {
        $this->data['hours']=3.5; $this->data['startTime']='08:00';
        $w=$this->create(); self::assertSame(10.5,$w->getSession()->getDureeFormationHeures());
        foreach ($w->getSession()->getJours() as $j) { self::assertSame('11:30',$j->getDateFin()->format('H:i')); self::assertSame(0,$j->getPauseMinutes()); }
    }

    public function testSelectedPostQcmIsAssignedAndEmailedOnlyOnce(): void
    {
        $qcm = $this->qcm($this->entity);
        $this->data['postQcm'] = $qcm;
        $w = $this->create()->setEnabled(true); $i = $this->enroll($w);
        self::assertSame($qcm->getId(), $w->getOptions()['postQcmId']);
        $task = $this->dueTask($w, 'evaluation', $i->getId());
        $this->mailer->expects(self::once())->method('send');
        $runner = self::getContainer()->get(WorkflowRunner::class);
        self::assertSame('sent', $runner->run($task, new \DateTimeImmutable()));
        $assignment = $this->em->getRepository(QcmAssignment::class)->findOneBy(['inscription' => $i]);
        self::assertNotNull($assignment);
        self::assertSame(QcmPhase::POST, $assignment->getPhase());
        self::assertSame($qcm->getId(), $assignment->getQcm()->getId());
        self::assertSame($this->entity->getId(), $assignment->getEntite()->getId());
        self::assertSame('unchanged', $runner->run($task, new \DateTimeImmutable()));
        self::assertSame(1, $this->em->getRepository(QcmAssignment::class)->count(['inscription' => $i]));
    }

    public function testQuickCreationRejectsForeignQcmBeforeCreatingAnyDossier(): void
    {
        $foreign = (new Entite())->setNom('Autre organisme QCM')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($foreign); $this->em->flush();
        $this->data['postQcm'] = $this->qcm($foreign);
        try { $this->create(); self::fail('A foreign QCM cannot be configured.'); }
        catch (\DomainException $error) { self::assertStringContainsString('QCM actif de cet organisme', $error->getMessage()); }
        self::assertTrue($this->em->isOpen());
        self::assertSame(0, $this->em->getRepository(TrainingWorkflow::class)->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\Devis::class)->count([]));
    }

    public function testSettingsRejectForeignQcmBeforeChangingContactOrEnabling(): void
    {
        $w = $this->create(); $id = $w->getId(); $email = $w->getContactEmail();
        $foreign = (new Entite())->setNom('Autre organisme QCM')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($foreign); $this->em->flush(); $qcm = $this->qcm($foreign);
        $base = '/fr/administrateur/'.$this->entity->getId().'/dossiers-automatises/'.$id;
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->admin);
        $crawler = $client->request('GET', $base);
        self::assertSame(0, $crawler->filter('#workflow-post-qcm option[value="'.$qcm->getId().'"]')->count());
        $token = $crawler->filter('form[action$="/reglages"] input[name="_token"]')->first()->attr('value');
        $client->request('POST', $base.'/reglages', ['action'=>'save', 'email'=>'changed@example.test', 'enabled'=>'1', 'postQcmId'=>$qcm->getId(), '_token'=>$token]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->em->clear(); $current = $this->em->find(TrainingWorkflow::class, $id);
        self::assertFalse($current->isEnabled()); self::assertSame($email, $current->getContactEmail());
        self::assertNull($current->getOptions()['postQcmId']);
    }

    public function testExistingDifferentEvaluationIsNotReplaced(): void
    {
        $chosen = $this->qcm($this->entity); $existing = $this->qcm($this->entity);
        $this->data['postQcm'] = $chosen;
        $w = $this->create(); $i = $this->enroll($w);
        $assignment = (new QcmAssignment())->setEntite($this->entity)->setCreateur($this->admin)->setSession($w->getSession())
            ->setInscription($i)->setQcm($existing)->setPhase(QcmPhase::POST);
        $this->em->persist($assignment); $this->em->flush();
        $this->mailer->expects(self::never())->method('send');
        $result = self::getContainer()->get(WorkflowActionHandler::class)->handle((new WorkflowTask())->setWorkflow($w)->setAction('evaluation')->setTargetId($i->getId()));
        self::assertSame('blocked', $result['status']);
        self::assertSame($existing->getId(), $assignment->getQcm()->getId());
        self::assertSame(1, $this->em->getRepository(QcmAssignment::class)->count(['inscription' => $i]));
    }

    private function qcm(Entite $entity): Qcm
    {
        $qcm = (new Qcm())->setEntite($entity)->setCreateur($this->admin)->setTitre('QCM choisi pour cette formation');
        $this->em->persist($qcm); $this->em->flush(); return $qcm;
    }
    public function testPlannerIsIdempotentAndMovesOnlyUnsentTasks(): void
    {
        $w=$this->create(); $planner=self::getContainer()->get(WorkflowPlanner::class);
        $tasks=$planner->synchronize($w); $n=count($tasks); $first=$tasks[0]; $due=$first->getDueAt();
        $first->setStatus('sent'); $this->em->flush();
        foreach ($w->getSession()->getJours() as $day) { $day->setDateDebut($day->getDateDebut()->modify('+7 days')); $day->setDateFin($day->getDateFin()->modify('+7 days')); }
        $this->em->flush(); self::assertCount($n,$planner->synchronize($w)); self::assertEquals($due,$first->getDueAt());
        self::assertSame('2028-02-29',WorkflowPlanner::addMonths(new \DateTimeImmutable('2026-12-31'),14)->format('Y-m-d'));
    }
    public function testInvoiceRequiresSignatureAndIsCreatedOnlyOnce(): void
    {
        $w=$this->create(); $handler=self::getContainer()->get(WorkflowBilling::class);
        $task=(new WorkflowTask())->setWorkflow($w)->setAction('invoice');
        self::assertSame('blocked',$handler->handle($task)['status']);
        $w->getConvention()->setDateSignatureEntreprise(new \DateTimeImmutable()); $this->em->flush();
        self::assertSame('done',$handler->handle($task)['status']); $id=$w->getFacture()->getId();
        self::assertSame('done',$handler->handle($task)['status']); self::assertSame($id,$w->getFacture()->getId());
        self::assertSame(1,$this->em->getRepository(Facture::class)->count([])); self::assertSame(132000,$w->getFacture()->getMontantTtcCents());
    }
    public function testPaymentsAndCreditsPreventUnpaidReminder(): void
    {
        $invoice=(new Facture())->setMontantTtcCents(10000);
        $invoice->addPaiement((new Paiement())->setMontantCents(7000));
        $invoice->addAvoir((new Avoir())->setMontantTtcCents(3000));
        self::assertSame(0,WorkflowBilling::outstanding($invoice));
        $invoice->setStatus(FactureStatus::CANCELED); self::assertSame(0,WorkflowBilling::outstanding($invoice));
    }
    public function testRunnerSendsOnceAndNeverRetriesAmbiguousDelivery(): void
    {
        $w=$this->create()->setEnabled(true); $this->em->flush();
        $task=(new WorkflowTask())->setWorkflow($w)->setTaskKey('participants:all')->setAction('participants')->setDueAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($task);$this->em->flush();
        $this->mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Network uncertain'));
        $runner=self::getContainer()->get(WorkflowRunner::class);
        self::assertSame('unknown',$runner->run($task,new \DateTimeImmutable()));
        self::assertSame('unchanged',$runner->run($task,new \DateTimeImmutable()));
    }
    public function testCancelledSessionDoesNotSend(): void
    {
        $w=$this->create()->setEnabled(true); $w->getSession()->setStatus(StatusSession::CANCELED);
        $task=(new WorkflowTask())->setWorkflow($w)->setTaskKey('participants:all')->setAction('participants')->setDueAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($task);$this->em->flush();$this->mailer->expects(self::never())->method('send');
        self::assertSame('skipped',self::getContainer()->get(WorkflowRunner::class)->run($task,new \DateTimeImmutable()));
    }
    public function testQuickFormHttpSubmissionCreatesSessionQuoteAndConventionWithoutSendingMail(): void
    {
        $this->mailer->expects(self::never())->method('send');
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $crawler = $client->request('GET', '/fr/administrateur/'.$this->entity->getId().'/dossiers-automatises/nouveau');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Préparer mon dossier')->form($this->quickFormValues());
        self::assertNotEmpty($form['quick_workflow[_token]']->getValue());
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        foreach ([Session::class, Devis::class, ConventionContrat::class, TrainingWorkflow::class] as $class) {
            self::assertSame(1, $this->em->getRepository($class)->count([]), $class.' must be created once by the HTTP form.');
        }
        $w = $this->em->getRepository(TrainingWorkflow::class)->findOneBy([]);
        self::assertStringEndsWith('/dossiers-automatises/'.$w->getId(), $client->getResponse()->headers->get('Location'));
        self::assertFalse($w->isEnabled()); self::assertSame(8, $w->getConvention()->getEffectifTotal());
        self::assertSame(21.0, $w->getSession()->getDureeFormationHeures());
        self::assertSame(110000, $w->getConvention()->getDevis()->getMontantHtCents());
        self::assertGreaterThan(0, $this->em->getRepository(WorkflowTask::class)->count(['workflow'=>$w]));
    }

    public function testQuickFormRejectsForeignFormationEvenWithValidCsrf(): void
    {
        $foreignUser = (new Utilisateur())->setEmail('foreign-form@example.test')->setPassword('unused')->setPrenom('Autre')->setNom('Compte');
        $foreignEntity = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($foreignUser);
        $foreignFormation = (new Formation())->setEntite($foreignEntity)->setCreateur($foreignUser)->setTitre('Formation hors organisme')->setSlug('foreign-form');
        foreach ([$foreignUser, $foreignEntity, $foreignFormation] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $foreignId = $foreignFormation->getId();
        $this->mailer->expects(self::never())->method('send');
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $url = '/fr/administrateur/'.$this->entity->getId().'/dossiers-automatises/nouveau';
        $crawler = $client->request('GET', $url);
        self::assertSame(0, $crawler->filter('#quick_workflow_formation option[value="'.$foreignId.'"]')->count());
        $form = $crawler->selectButton('Préparer mon dossier')->form($this->quickFormValues());
        self::assertNotEmpty($form['quick_workflow[_token]']->getValue());
        // Tamper with the HTTP payload while keeping the real token and all other valid field values.
        $payload = $form->getPhpValues(); $payload['quick_workflow']['formation'] = (string) $foreignId;
        $client->request('POST', $url, $payload);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Symfony rejects invalid entity choices as an unprocessable form.');
        $this->em->clear();
        foreach ([Session::class, Devis::class, ConventionContrat::class, TrainingWorkflow::class, WorkflowTask::class] as $class) {
            self::assertSame(0, $this->em->getRepository($class)->count([]), $class.' must not be created for a foreign formation.');
        }
        self::assertSame('Formation hors organisme', $this->em->find(Formation::class, $foreignId)->getTitre());
    }

    private function quickFormValues(): array
    {
        return [
            'quick_workflow[formation]' => (string) $this->data['formation']->getId(),
            'quick_workflow[entreprise]' => (string) $this->data['entreprise']->getId(),
            'quick_workflow[formateur]' => (string) $this->data['formateur']->getId(),
            'quick_workflow[site]' => (string) $this->data['site']->getId(),
            'quick_workflow[dates]' => $this->data['dates'], 'quick_workflow[startTime]' => '08:30',
            'quick_workflow[hours]' => '7', 'quick_workflow[price]' => '1100,00',
            'quick_workflow[vat]' => '20', 'quick_workflow[participants]' => '8',
            'quick_workflow[contactEmail]' => 'client@example.test', 'quick_workflow[validityMonths]' => '24',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daylightSavingSchedules')]
    public function testScheduleRoundTripStoresUtcAndRendersFrenchWallTime(string $dates, array $expected): void
    {
        $this->data['dates'] = $dates;
        $w = $this->create(); $id = $w->getId();
        $planner = self::getContainer()->get(WorkflowPlanner::class);
        $planner->synchronize($w);
        $defaultZone = date_default_timezone_get();
        try {
            // A different PHP timezone on the worker must not change the persisted task instant.
            foreach (['UTC', 'Europe/Paris'] as $phpZone) {
                date_default_timezone_set($phpZone); $this->em->clear();
                $w = $this->em->find(TrainingWorkflow::class, $id);
                $tasks = $planner->synchronize($w);
                foreach ($expected as $action=>$utc) {
                    $task = array_values(array_filter($tasks, static fn($task) => $task->getAction() === $action))[0];
                    self::assertSame($utc, $task->getDueAt()->format('Y-m-d H:i'));
                    self::assertSame('UTC', $task->getDueAt()->getTimezone()->getName());
                    self::assertSame($utc.':00', $this->em->getConnection()->fetchOne('SELECT due_at FROM workflow_task WHERE id = ?', [$task->getId()]));
                    if ($action === 'convention' || $action === 'invoice') {
                        $twig = self::getContainer()->get('twig');
                        self::assertSame('09:00', $twig->createTemplate("{{ due|date('H:i', 'Europe/Paris') }}")->render(['due'=>$task->getDueAt()]));
                    }
                }
                self::assertSame('08:30', $w->getSession()->getDateDebut()->format('H:i'));
                self::assertSame('17:00', $w->getSession()->getDateFin()->format('H:i'));
                $conventionTask = array_values(array_filter($tasks, static fn($task) => $task->getAction() === 'convention'))[0];
                $processed = new \DateTimeImmutable('2026-10-13 11:00:00', new \DateTimeZone('Europe/Paris'));
                $conventionTask->setProcessedAt($processed); $this->em->flush(); $taskId = $conventionTask->getId();
                $this->em->clear();
                self::assertSame('2026-10-13 09:00:00', $this->em->find(WorkflowTask::class, $taskId)->getProcessedAt()->format('Y-m-d H:i:s'));
            }
        } finally { date_default_timezone_set($defaultZone); }
    }

    public static function daylightSavingSchedules(): iterable
    {
        yield 'fall: J-30 in summer, training in winter' => ['2026-11-12,2026-11-13,2026-11-14', ['convention'=>'2026-10-13 07:00','attendance'=>'2026-11-12 07:30','invoice'=>'2026-11-15 08:00']];
        yield 'spring: J-30 in winter, training in summer' => ['2027-03-29,2027-03-30,2027-03-31', ['convention'=>'2027-02-27 08:00','attendance'=>'2027-03-29 06:30','invoice'=>'2027-04-01 07:00']];
    }

    public function testPortalAndRunnerRespectFrenchSessionTimeAfterReload(): void
    {
        $this->data['dates'] = '2027-07-10';
        $w = $this->create()->setEnabled(true); $id = $w->getId();
        $task = $this->dueTask($w, 'participants'); $taskId = $task->getId();
        $this->em->clear(); $w = $this->em->find(TrainingWorkflow::class, $id);
        $portal = self::getContainer()->get(WorkflowPortal::class); $token = $portal->token($w);
        self::assertTrue($portal->canSign($w, new \DateTimeImmutable('2027-07-10 06:29:59 UTC')));
        self::assertFalse($portal->canSign($w, new \DateTimeImmutable('2027-07-10 06:30:00 UTC')));
        self::assertSame('2027-08-09 17:00 +02:00', $portal->expiresAt($w)->format('Y-m-d H:i P'));
        self::assertTrue($portal->isValid($w, $token, new \DateTimeImmutable('2027-08-09 14:59:59 UTC')));
        self::assertFalse($portal->isValid($w, $token, new \DateTimeImmutable('2027-08-09 15:00:01 UTC')));
        $this->mailer->expects(self::never())->method('send');
        self::assertSame('skipped', self::getContainer()->get(WorkflowRunner::class)->run($this->em->find(WorkflowTask::class, $taskId), new \DateTimeImmutable('2027-07-10 15:00:01 UTC')));
    }

    public function testInvoiceReminderDateKeepsNineAmParisAfterReload(): void
    {
        $w = $this->create(); $w->getConvention()->setDateSignatureEntreprise(new \DateTimeImmutable()); $this->em->flush();
        self::getContainer()->get(WorkflowBilling::class)->handle((new WorkflowTask())->setWorkflow($w)->setAction('invoice'));
        $w->getFacture()->setDateEmission(new \DateTimeImmutable('2027-03-10')); $this->em->flush(); $id = $w->getId(); $this->em->clear();
        $schedule = self::getContainer()->get(WorkflowPlanner::class)->schedule($this->em->find(TrainingWorkflow::class, $id));
        $due = array_values(array_filter($schedule, static fn($row) => $row[0] === 'reminder'))[0][2];
        self::assertSame('2027-04-09 07:00 UTC', $due->format('Y-m-d H:i T'));
        self::assertSame('09:00', $due->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));
    }

    public function testAdminPagesRender(): void
    {
        $w=$this->create(); self::getContainer()->get(WorkflowPlanner::class)->synchronize($w);
        $client=new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        foreach (['','/nouveau','/'.$w->getId(),'/'.$w->getId().'/qr'] as $suffix) {
            $client->request('GET','/fr/administrateur/'.$this->entity->getId().'/dossiers-automatises'.$suffix);
            self::assertSame(200,$client->getResponse()->getStatusCode(),$suffix);
            if ($directory = getenv('WF_PREVIEW_DIR')) {
                $name = match($suffix) { ''=>'index', '/nouveau'=>'new', '/'.$w->getId().'/qr'=>'qr', default=>'show' };
                $html = str_replace('<head>', '<head><base href="http://localhost:8888/">', $client->getResponse()->getContent());
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
        }
    }

    public function testOtherTenantCannotReadOrMutateWorkflowAndOwnTenantCannotUseForeignId(): void
    {
        $own = $this->create();
        $foreign = $this->otherTenantWorkflow();
        $ownId = $own->getId(); $foreignId = $foreign->getId();
        $ownEntity = $this->entity->getId(); $foreignEntity = $foreign->getEntite()->getId();
        $originalNonce = $foreign->getPortalNonce();
        $this->mailer->expects(self::never())->method('send');
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->admin);
        foreach (['', '/nouveau', '/'.$foreignId, '/'.$foreignId.'/qr'] as $suffix) {
            $client->request('GET', '/fr/administrateur/'.$foreignEntity.'/dossiers-automatises'.$suffix);
            self::assertSame(403, $client->getResponse()->getStatusCode(), 'No membership in the requested entity: '.$suffix);
        }
        foreach (['/'.$foreignId, '/'.$foreignId.'/qr'] as $suffix) {
            $client->request('GET', '/fr/administrateur/'.$ownEntity.'/dossiers-automatises'.$suffix);
            self::assertSame(404, $client->getResponse()->getStatusCode(), 'Foreign workflow IDs must not disclose a dossier: '.$suffix);
        }
        foreach ([$foreignEntity=>403, $ownEntity=>404] as $entityId=>$expected) {
            $client->request('POST', '/fr/administrateur/'.$entityId.'/dossiers-automatises/'.$foreignId.'/reglages', ['action'=>'rotate', '_token'=>'forged']);
            self::assertSame($expected, $client->getResponse()->getStatusCode());
        }
        $this->em->clear();
        self::assertSame($originalNonce, $this->em->find(TrainingWorkflow::class, $foreignId)->getPortalNonce());
        self::assertFalse($this->em->find(TrainingWorkflow::class, $ownId)->isEnabled());
        self::assertSame(2, $this->em->getRepository(TrainingWorkflow::class)->count([]));
    }

    public function testSettingsRequireCsrfAndValidChangesRevokeOldPortalLinkWithoutMail(): void
    {
        $w = $this->create(); $id = $w->getId();
        $base = '/fr/administrateur/'.$this->entity->getId().'/dossiers-automatises/'.$id;
        $nonce = $w->getPortalNonce(); $email = $w->getContactEmail();
        $portal = self::getContainer()->get(WorkflowPortal::class); $oldToken = $portal->token($w);
        $this->mailer->expects(self::never())->method('send');
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->admin);
        foreach (['save', 'rotate'] as $action) {
            $client->request('POST', $base.'/reglages', ['action'=>$action, 'email'=>'changed@example.test', 'enabled'=>'1', '_token'=>'forged']);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $this->em->clear(); $current = $this->em->find(TrainingWorkflow::class, $id);
            self::assertSame($nonce, $current->getPortalNonce()); self::assertFalse($current->isEnabled()); self::assertSame($email, $current->getContactEmail());
        }
        $crawler = $client->request('GET', $base);
        $token = $crawler->filter('form[action$="/reglages"] input[name="_token"]')->first()->attr('value');
        $client->request('POST', $base.'/reglages', ['action'=>'save', 'email'=>'changed@example.test', 'enabled'=>'1', 'renewalEnabled'=>'1', '_token'=>$token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear(); $current = $this->em->find(TrainingWorkflow::class, $id);
        self::assertTrue($current->isEnabled()); self::assertTrue($current->isRenewalEnabled()); self::assertSame('changed@example.test', $current->getContactEmail());
        $client->request('POST', $base.'/reglages', ['action'=>'rotate', '_token'=>$token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear(); $current = $this->em->find(TrainingWorkflow::class, $id);
        self::assertNotSame($nonce, $current->getPortalNonce()); self::assertFalse($portal->isValid($current, $oldToken));
        self::assertTrue($portal->isValid($current, $portal->token($current)));
        $current->setRenewalOptOut(true); $this->em->flush();
        $client->request('POST', $base.'/reglages', ['action'=>'save', 'email'=>'changed@example.test', 'enabled'=>'1', 'renewalEnabled'=>'1', '_token'=>$token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear(); $current = $this->em->find(TrainingWorkflow::class, $id);
        self::assertTrue($current->isRenewalOptOut());
        self::assertFalse($current->isRenewalEnabled(), 'Settings cannot override the client unsubscribe choice.');
    }

    public function testCertificateRemainsBlockedUntilValidatedPdfThenSendsExactlyOnce(): void
    {
        $w = $this->create()->setEnabled(true); $i = $this->enroll($w);
        $task = $this->dueTask($w, 'certificate', $i->getId());
        $runner = self::getContainer()->get(WorkflowRunner::class);
        $expectedBytes = null;
        $this->mailer->expects(self::once())->method('send')->with(self::callback(static function ($message) use (&$expectedBytes, $i): bool {
            return $message instanceof Email && $message->getTo()[0]->getAddress() === $i->getStagiaire()->getEmail()
                && count($message->getAttachments()) === 1 && $message->getAttachments()[0]->getBody() === $expectedBytes;
        }), self::anything());
        self::assertSame('blocked', $runner->run($task, new \DateTimeImmutable()));
        $i->setStatus(StatusInscription::TERMINE)->setReussi(true); $this->em->flush();
        self::assertSame('blocked', $runner->run($task, new \DateTimeImmutable()), 'Completing the course must never fabricate the certificate.');
        $path = $this->deposit($w, $i, new \DateTimeImmutable('today'));
        $expectedBytes = file_get_contents($path);
        self::assertSame('sent', $runner->run($task, new \DateTimeImmutable()));
        self::assertSame(hash_file('sha256', $path), $task->getSnapshot()['sha256']);
        self::assertSame(1, $task->getSnapshot()['version']);
        self::assertSame('unchanged', $runner->run($task, new \DateTimeImmutable()));
    }

    public function testTamperedCertificateCannotBeSentOrScheduledForRenewal(): void
    {
        $w = $this->create()->setEnabled(true)->setRenewalEnabled(true);
        $i = $this->enroll($w)->setStatus(StatusInscription::TERMINE)->setReussi(true);
        $path = $this->deposit($w, $i, new \DateTimeImmutable('-23 months'));
        file_put_contents($path, '%PDF-1.4 tampered');
        $task = $this->dueTask($w, 'certificate', $i->getId());
        $this->mailer->expects(self::never())->method('send');
        self::assertSame('blocked', self::getContainer()->get(WorkflowRunner::class)->run($task, new \DateTimeImmutable()));
        self::assertCount(0, array_filter(self::getContainer()->get(WorkflowPlanner::class)->schedule($w), static fn($row) => $row[0] === 'renewal'));
    }

    public function testRenewalUsesCertificateIssueDateAndSendsExactlyOnce(): void
    {
        $w = $this->create()->setEnabled(true)->setRenewalEnabled(true);
        $i = $this->enroll($w)->setStatus(StatusInscription::TERMINE)->setReussi(true);
        $planner = self::getContainer()->get(WorkflowPlanner::class);
        self::assertCount(0, array_filter($planner->schedule($w), static fn($row) => $row[0] === 'renewal'), 'A passing grade alone is not a certificate.');
        $issued = new \DateTimeImmutable('today -23 months');
        $this->deposit($w, $i, $issued);
        $tasks = $planner->synchronize($w);
        $renewals = array_values(array_filter($tasks, static fn($task) => $task->getAction() === 'renewal'));
        self::assertCount(1, $renewals); $task = $renewals[0];
        self::assertEquals(WorkflowTime::utc(WorkflowPlanner::addMonths(WorkflowTime::date($issued->format('Y-m-d')), 22)->setTime(9, 0)), $task->getDueAt());
        self::assertNotEquals($w->getSession()->getDateFin()->modify('+22 months')->setTime(9, 0), $task->getDueAt());
        $this->mailer->expects(self::once())->method('send')->with(self::callback(static fn($message) => $message instanceof Email
            && $message->getTo()[0]->getAddress() === 'client@example.test' && str_contains($message->getTextBody(), '1 collaborateur(s)')
            && str_contains($message->getTextBody(), '/preferences')), self::anything());
        $runner = self::getContainer()->get(WorkflowRunner::class);
        self::assertSame('sent', $runner->run($task, new \DateTimeImmutable()));
        self::assertSame([$i->getStagiaire()->getId()], $task->getSnapshot()['participants']);
        self::assertSame([WorkflowPlanner::addMonths($issued, 24)->format('d/m/Y')], $task->getSnapshot()['expiries']);
        self::assertStringNotContainsString('/preferences', json_encode($task->getSnapshot()), 'Private opt-out URL is not copied into the audit snapshot.');
        self::assertSame('unchanged', $runner->run($task, new \DateTimeImmutable()));
    }

    public function testRenewalIsSuppressedForExpiredRenewedOrOptedOutCertificates(): void
    {
        $w = $this->create()->setEnabled(true)->setRenewalEnabled(true);
        $i = $this->enroll($w)->setStatus(StatusInscription::TERMINE)->setReussi(true);
        $this->deposit($w, $i, new \DateTimeImmutable('today -25 months'));
        $task = (new WorkflowTask())->setWorkflow($w)->setAction('renewal');
        $handler = self::getContainer()->get(WorkflowActionHandler::class);
        $this->mailer->expects(self::never())->method('send');
        self::assertSame('skipped', $handler->handle($task)['status'], 'Expired certificates are not upcoming renewals.');
        $this->deposit($w, $i, new \DateTimeImmutable('today -23 months'), 'Correction de la date');
        $newer = $this->create(); $newerInscription = $this->enroll($newer, $i->getStagiaire())->setStatus(StatusInscription::TERMINE)->setReussi(true);
        $this->deposit($newer, $newerInscription, new \DateTimeImmutable('today'));
        self::assertSame('skipped', $handler->handle($task)['status'], 'A newer validated certificate already fulfills renewal.');
        $newerInscription->setReussi(false); $w->setRenewalOptOut(true); $this->em->flush();
        self::assertSame('skipped', $handler->handle($task)['status'], 'Client opt-out always wins.');
    }

    private function enroll(TrainingWorkflow $w, ?Utilisateur $user = null): Inscription
    {
        if (!$user) {
            $user = (new Utilisateur())->setEmail('learner-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Stagiaire')->setEntite($w->getEntite());
            $this->em->persist($user);
        }
        $i = (new Inscription())->setEntite($w->getEntite())->setSession($w->getSession())->setCreateur($w->getCreateur())->setStagiaire($user);
        $w->getConvention()->addInscription($i); $this->em->persist($i); $this->em->flush();
        return $i;
    }

    private function deposit(TrainingWorkflow $w, Inscription $i, \DateTimeImmutable $issued, ?string $reason = null): string
    {
        $source = $this->certificateDirectory.'/upload-'.bin2hex(random_bytes(6)).'.pdf';
        file_put_contents($source, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% validated certificate ".$i->getId()."\n%%EOF");
        $path = $this->certificates->store(new UploadedFile($source, 'certificat.pdf', 'application/pdf', null, true), $w, $i, $w->getCreateur(), $issued, $reason);
        $this->em->flush();
        return $path;
    }

    private function dueTask(TrainingWorkflow $w, string $action, ?int $target = null): WorkflowTask
    {
        $task = (new WorkflowTask())->setWorkflow($w)->setTaskKey($action.':'.($target ?? 'all'))->setAction($action)->setTargetId($target)->setDueAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($task); $this->em->flush();
        return $task;
    }

    private function otherTenantWorkflow(): TrainingWorkflow
    {
        $user = (new Utilisateur())->setEmail('other-admin@example.test')->setPassword('unused')->setPrenom('Autre')->setNom('Admin');
        $entity = (new Entite())->setNom('Autre organisme privé')->setEmail('other-of@example.test')->setPublic(false)->setCreateur($user);
        $this->em->persist($user); $this->em->persist($entity); $this->em->flush(); $user->setEntite($entity);
        $this->em->persist((new UtilisateurEntite())->setEntite($entity)->setUtilisateur($user)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'WORKFLOW_TEST']);
        $this->em->persist((new EntiteSubscription())->setEntite($entity)->setPlan($plan)->setStatus('active'));
        $data = $this->data;
        $data['formation'] = (new Formation())->setEntite($entity)->setCreateur($user)->setTitre('Formation privée')->setSlug('private-test');
        $data['entreprise'] = (new Entreprise())->setEntite($entity)->setCreateur($user)->setRaisonSociale('Client privé');
        $data['site'] = (new Site())->setEntite($entity)->setCreateur($user)->setNom('Autre site')->setSlug('other-site');
        $data['formateur'] = (new Formateur())->setEntite($entity)->setCreateur($user)->setUtilisateur($user);
        foreach (['formation','entreprise','site','formateur'] as $key) $this->em->persist($data[$key]);
        $this->em->flush();
        return self::getContainer()->get(QuickWorkflowCreator::class)->create($entity, $user, $data);
    }
}
