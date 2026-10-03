<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\{Attestation, ConventionContrat, Entite, Formation, Inscription, Qcm, QcmAssignment, Session, SessionJour, Utilisateur};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Enum\{QcmPhase, StatusInscription};
use App\Service\Automation\{WorkflowActionHandler, WorkflowPortal};
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\AttestationNumberGenerator;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class WorkflowActionHandlerTest extends TestCase
{
    private EntityManagerInterface $em;
    private EntityRepository $repository;
    private MailerInterface $transport;
    private AttestationNumberGenerator $numbers;
    private WorkflowActionHandler $handler;
    private TrainingWorkflow $workflow;
    private Inscription $inscription;
    private ?QcmAssignment $qcmAssignment = null;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);
        $this->repository = $repo;
        $repo->method('findOneBy')->willReturnCallback(fn(array $criteria) => isset($criteria['phase']) ? $this->qcmAssignment : null);
        $this->em->method('getRepository')->willReturn($repo);
        $this->transport = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<p>Message de test</p>');
        $pdf = $this->createMock(PdfManager::class);
        $pdf->method('createPortraitBytes')->willReturn('%PDF-1.4 test');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://example.test/dossier');
        $this->numbers = $this->createMock(AttestationNumberGenerator::class);
        $this->numbers->method('nextForEntite')->willReturn('ATT-E1-2026-0001');
        $this->handler = new WorkflowActionHandler($this->em, new MailerManager($this->transport, $twig, $router), $pdf,
            $twig, $router, new WorkflowPortal($router, 'test-secret'), $this->numbers, new \App\Service\Automation\WorkflowCertificateStorage(sys_get_temp_dir() . '/no-workflow-certificates-test'), new \App\Service\Qcm\QcmAssigner($this->em));

        $entity = $this->id((new Entite())->setNom('Organisme test')->setEmail('of@example.test'), 1);
        $user = $this->id((new Utilisateur())->setEmail('learner@example.test')->setNom('Test')->setPrenom('Camille'), 3);
        $formation = $this->id((new Formation())->setEntite($entity)->setTitre('Formation test'), 4);
        $session = $this->id((new Session())->setEntite($entity)->setFormation($formation), 5);
        $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-01-01 08:30'))
            ->setDateFin(new \DateTimeImmutable('2025-01-01 17:00'))->setPauseMinutes(90));
        $convention = $this->id((new ConventionContrat())->setEntite($entity)->setSession($session), 6);
        $this->inscription = $this->id((new Inscription())->setEntite($entity)->setSession($session)->setStagiaire($user), 7);
        $convention->addInscription($this->inscription);
        $this->workflow = $this->id((new TrainingWorkflow())->setEntite($entity)->setSession($session)->setConvention($convention)
            ->setCreateur($user)->setContactEmail('client@example.test'), 8);
    }

    public function testCrossTenantConventionNeverSends(): void
    {
        $this->workflow->getConvention()->setEntite($this->id(new Entite(), 99));
        $this->transport->expects(self::never())->method('send');
        self::assertSame('blocked', $this->handler->handle($this->task('participants'))['status']);
    }

    public function testRemovedParticipantIsSkipped(): void
    {
        $this->transport->expects(self::never())->method('send');
        self::assertSame('skipped', $this->handler->handle($this->task('convocation')->setTargetId(1234))['status']);
    }

    public function testAttestationRequiresCompletedAttendance(): void
    {
        $this->transport->expects(self::never())->method('send');
        $this->em->expects(self::never())->method('persist');
        self::assertSame('blocked', $this->handler->handle($this->task('attestation'))['status']);
        $this->inscription->setStatus(StatusInscription::TERMINE);
        self::assertSame('blocked', $this->handler->handle($this->task('attestation'))['status']);
        self::assertFalse($this->inscription->isReussi());
    }

    public function testIssuingAttestationPreservesFailureAndUsesNetAttendanceHours(): void
    {
        $this->inscription->setStatus(StatusInscription::TERMINE)->setTauxAssiduite(50);
        $this->em->expects(self::once())->method('persist')->with(self::callback(static fn($a) => $a instanceof Attestation && !$a->isReussi() && $a->getDureeHeures() === 3.5));
        $this->transport->expects(self::once())->method('send');
        $result = $this->handler->handle($this->task('attestation'));
        self::assertSame('sent', $result['status']);
        self::assertFalse($this->inscription->getAttestation()->isReussi());
        self::assertFalse($this->inscription->isReussi());
        self::assertSame(hash('sha256', '%PDF-1.4 test'), $result['snapshot']['sha256']);
    }

    public function testExistingAttestationIsNeverReissuedOrChanged(): void
    {
        $this->inscription->setStatus(StatusInscription::TERMINE)->setTauxAssiduite(100);
        $a = (new Attestation())->setEntite($this->workflow->getEntite())->setInscription($this->inscription)
            ->setNumero('ATT-EXISTANT')->setDureeHeures(6)->setReussi(true)->setDateDelivrance(new \DateTimeImmutable('2025-01-01'));
        $this->inscription->setAttestation($a);
        $this->em->expects(self::never())->method('persist');
        $this->transport->expects(self::once())->method('send');
        self::assertSame('sent', $this->handler->handle($this->task('attestation'))['status']);
        self::assertSame($a, $this->inscription->getAttestation());
        self::assertSame(6.0, $a->getDureeHeures());
    }

    public function testConvocationWithoutLearnerEmailGoesOnlyToClientContact(): void
    {
        $this->inscription->getStagiaire()->setEmail('');
        $this->transport->expects(self::once())->method('send')->with(self::callback(static fn($message) => $message->getTo()[0]->getAddress() === 'client@example.test'), self::anything());
        $result = $this->handler->handle($this->task('convocation'));
        self::assertSame('sent', $result['status']);
        self::assertSame('client@example.test', $result['snapshot']['to']);
    }

    public function testQuestionnairesRequireExplicitRelevantAssignment(): void
    {
        $this->transport->expects(self::never())->method('send');
        $this->em->expects(self::never())->method('persist');
        self::assertSame('blocked', $this->handler->handle($this->task('satisfaction'))['status']);
        self::assertSame('blocked', $this->handler->handle($this->task('evaluation'))['status']);
    }

    public function testEvaluationUsesAssignedPostQcmWithoutCreatingAnotherAssignment(): void
    {
        $this->qcmAssignment = $this->assignedQcm();
        $this->em->expects(self::never())->method('persist');
        $this->transport->expects(self::once())->method('send');
        $result = $this->handler->handle($this->task('evaluation'));
        self::assertSame('sent', $result['status']);
        self::assertSame(22, $result['snapshot']['assignmentId']);
    }

    public function testEvaluationCannotSendForeignOrInactiveQcm(): void
    {
        $this->qcmAssignment = $this->assignedQcm();
        $qcm = $this->qcmAssignment->getQcm();
        $qcm->setEntite($this->id(new Entite(), 999));
        $this->transport->expects(self::never())->method('send');
        self::assertSame('blocked', $this->handler->handle($this->task('evaluation'))['status']);
        $qcm->setEntite($this->workflow->getEntite())->setIsActive(false);
        self::assertSame('blocked', $this->handler->handle($this->task('evaluation'))['status']);
    }

    public function testEvaluationCannotSendAssignmentFromAnotherSession(): void
    {
        $this->qcmAssignment = $this->assignedQcm()->setSession($this->id((new Session())->setEntite($this->workflow->getEntite()), 998));
        $this->transport->expects(self::never())->method('send');
        self::assertSame('blocked', $this->handler->handle($this->task('evaluation'))['status']);
    }

    public function testTransportFailureEscapesWithoutMarkingTaskSent(): void
    {
        $this->transport->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Ambiguous delivery'));
        $task = $this->task('convocation')->setStatus('processing');
        try {
            $this->handler->handle($task);
            self::fail('Transport failures must reach the durable runner.');
        } catch (\RuntimeException $e) {
            self::assertSame('Ambiguous delivery', $e->getMessage());
            self::assertSame('processing', $task->getStatus());
        }
    }

    public function testOptOutSuppressesRenewalWithoutReadingAttestations(): void
    {
        $this->workflow->setRenewalEnabled(true)->setValidityMonths(24)->setRenewalOptOut(true);
        $this->em->expects(self::never())->method('createQueryBuilder');
        $this->transport->expects(self::never())->method('send');
        self::assertSame('skipped', $this->handler->handle($this->task('renewal'))['status']);
    }

    public function testNoProfessionalCertificateIsInvented(): void
    {
        $this->inscription->setStatus(StatusInscription::TERMINE)->setReussi(true)->setTauxAssiduite(100);
        $this->transport->expects(self::never())->method('send');
        self::assertSame('blocked', $this->handler->handle($this->task('certificate'))['status']);
    }

    public function testAutomaticImportRequiresExplicitWorkflowSetting(): void
    {
        $this->repository->expects(self::never())->method('findBy');
        self::assertSame('blocked', $this->handler->handle($this->task('participants_import'))['status']);
    }

    public function testImportWithoutCollectedPeopleDoesNotCreateAccounts(): void
    {
        $this->workflow->setOptions(['autoImportParticipants' => true]);
        $this->workflow->getConvention()->setEffectifPrevisionnel(8);
        $this->repository->method('findBy')->willReturn([]);
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('wrapInTransaction');
        $result = $this->handler->handle($this->task('participants_import'));
        self::assertSame('blocked', $result['status']);
        self::assertSame(1, $result['snapshot']['enrolled']);
        self::assertSame(8, $result['snapshot']['expected']);
    }

    public function testAlreadyEnrolledGroupNeedsNoImport(): void
    {
        $this->workflow->setOptions(['autoImportParticipants' => true]);
        $this->workflow->getConvention()->setEffectifPrevisionnel(1);
        $this->repository->method('findBy')->willReturn([]);
        $this->em->expects(self::never())->method('wrapInTransaction');
        self::assertSame('done', $this->handler->handle($this->task('participants_import'))['status']);
    }

    public function testNewAccountInvitationUsesExpiringTokenWithoutLoggingIt(): void
    {
        $this->inscription->setMeta(['workflowNewAccount' => true]);
        $user = $this->inscription->getStagiaire()->setEntite($this->workflow->getEntite())->setIsVerified(false);
        $this->em->expects(self::once())->method('flush');
        $this->transport->expects(self::once())->method('send');
        $result = $this->handler->handle($this->task('account'));
        self::assertSame('sent', $result['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $user->getResetToken());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $user->getResetTokenExpiresAt());
        self::assertStringNotContainsString($user->getResetToken(), json_encode($result['snapshot']));
    }

    public function testExistingAccountNeverReceivesPasswordResetFromWorkflow(): void
    {
        $this->transport->expects(self::never())->method('send');
        $this->em->expects(self::never())->method('flush');
        self::assertSame('skipped', $this->handler->handle($this->task('account'))['status']);
        self::assertNull($this->inscription->getStagiaire()->getResetToken());
    }

    private function assignedQcm(): QcmAssignment
    {
        $qcm = $this->id((new Qcm())->setEntite($this->workflow->getEntite())->setTitre('Évaluation adaptée'), 21);
        return $this->id((new QcmAssignment())->setEntite($this->workflow->getEntite())->setSession($this->workflow->getSession())
            ->setInscription($this->inscription)->setPhase(QcmPhase::POST)->setQcm($qcm), 22);
    }

    private function task(string $action): WorkflowTask
    {
        return (new WorkflowTask())->setWorkflow($this->workflow)->setAction($action)->setTargetId($this->inscription->getId());
    }

    private function id(object $object, int $id): mixed
    {
        (new \ReflectionProperty($object, 'id'))->setValue($object, $id);
        return $object;
    }
}
