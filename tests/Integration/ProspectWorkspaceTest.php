<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Controller\Administrateur\ProspectWorkspaceController;
use App\Entity\{Entite, Utilisateur, Prospect, EmailLog, ProspectInteraction};
use App\Enum\{ProspectStatus, InteractionChannel};
use App\Service\Prospect\ProspectFollowUp;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class ProspectWorkspaceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;
    private Entite $tenant;
    private Utilisateur $user;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('crm@example.test')->setPassword('unused')->setNom('Test')->setPrenom('CRM');
        $this->tenant = (new Entite())->setNom('Test')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->tenant); $this->em->flush();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $key) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function prospect(string $name, ?Entite $tenant = null): Prospect
    {
        $p = (new Prospect())->setNom($name)->setPrenom('Contact')->setCreateur($this->user)->setEntite($tenant ?? $this->tenant)->setEmail('contact@example.test');
        $this->em->persist($p); $this->em->flush(); return $p;
    }
    private function workspace(array $query): array
    {
        $parameters = [];
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function ($view, $context) use (&$parameters) { $parameters = $context; return 'ok'; });
        $container = new Container(); $container->set('twig', $twig);
        $controller = new ProspectWorkspaceController(); $controller->setContainer($container);
        $controller->workspace($this->tenant, new Request($query), $this->em->getRepository(Prospect::class), $this->em);
        return $parameters;
    }
    public function testQueueExcludesClosedAndOtherTenantAndIncludesTodaysActions(): void
    {
        $due = $this->prospect('Due')->setNextActionAt(new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Paris')));
        $this->prospect('Closed')->setStatus(ProspectStatus::WON)->setNextActionAt(new \DateTimeImmutable('yesterday'));
        $other = (new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        $this->prospect('Hidden', $other)->setNextActionAt(new \DateTimeImmutable('yesterday'));
        $this->em->flush();
        $data = $this->workspace(['filter' => 'due']);
        self::assertCount(1, $data['rows']); self::assertSame($due, $data['rows'][0]['p']);
        self::assertEquals(2, $data['stats']['total']); self::assertEquals(1, $data['stats']['due']);
    }
    public function testOnlySuccessfulMailWithoutLaterReplyIsWaiting(): void
    {
        $p = $this->prospect('Waiting');
        $log = (new EmailLog())->setEntite($this->tenant)->setCreateur($this->user)->setProspect($p)->setToEmail('contact@example.test')->setSubject('Test')->setStatus('FAILED')->setSentAt(new \DateTimeImmutable('-2 days'));
        $this->em->persist($log); $this->em->flush();
        self::assertCount(0, $this->workspace(['filter' => 'waiting'])['rows']);
        $log->setStatus('SENT'); $this->em->flush();
        self::assertCount(1, $this->workspace(['filter' => 'waiting'])['rows']);
        $reply = (new ProspectInteraction())->setEntite($this->tenant)->setCreateur($this->user)->setProspect($p)->setActorProspect($p)->setChannel(InteractionChannel::EMAIL)->setTitle('Réponse')->setOccurredAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($reply); $this->em->flush();
        self::assertCount(0, $this->workspace(['filter' => 'waiting'])['rows']);
        $log->setSentAt(new \DateTimeImmutable()); $this->em->flush();
        self::assertCount(1, $this->workspace(['filter' => 'waiting'])['rows']);
    }
    public function testSearchRhAndPagination(): void
    {
        for ($i = 0; $i < 62; ++$i) $this->prospect('Person '.$i)->setPoste('Responsable RH')->setSociete('Acme');
        $this->em->flush();
        $first = $this->workspace(['view'=>'contacts','filter'=>'rh','q'=>'Acme']);
        self::assertCount(60, $first['rows']); self::assertTrue($first['hasNext']);
        self::assertCount(2, $this->workspace(['view'=>'contacts','page'=>2])['rows']);
    }
    public function testFollowupDatesRejectInvalidCalendarDate(): void
    {
        self::assertSame('2026-11-06 09:00', ProspectFollowUp::date('2026-11-06')->format('Y-m-d H:i'));
        $this->expectException(\InvalidArgumentException::class);
        ProspectFollowUp::date('2026-02-30');
    }
    private function actionContainer(bool $validCsrf = true): Container
    {
        $container = new Container();
        $csrf = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($validCsrf); $container->set('security.csrf.token_manager', $csrf);
        $storage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->user, 'main', ['ROLE_USER']));
        $container->set('security.token_storage', $storage);
        $router = $this->createMock(\Symfony\Component\Routing\RouterInterface::class);
        $router->method('generate')->willReturn('/workspace'); $container->set('router', $router);
        $request = new Request(); $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $stack = new \Symfony\Component\HttpFoundation\RequestStack(); $stack->push($request); $container->set('request_stack', $stack);
        return $container;
    }
    public function testReplyRecordsHistoryAndClearsOldReminder(): void
    {
        $p = $this->prospect('Reply')->setNextActionAt(new \DateTimeImmutable('yesterday')); $this->em->flush();
        $controller = new ProspectWorkspaceController(); $controller->setContainer($this->actionContainer());
        $controller->followup($this->tenant, $p, new Request([], ['_token'=>'valid','action'=>'reply','note'=>'Besoin de formation confirmé']), $this->em);
        self::assertNull($p->getNextActionAt()); self::assertSame(ProspectStatus::CONTACTED, $p->getStatus());
        $history = $this->em->getRepository(ProspectInteraction::class)->findOneBy(['prospect'=>$p]);
        self::assertSame($p, $history->getActorProspect()); self::assertNull($history->getActor());
    }
    public function testForeignTenantCannotBeUpdated(): void
    {
        $other = (new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user); $this->em->persist($other); $this->em->flush();
        $p = $this->prospect('Other', $other);
        $controller = new ProspectWorkspaceController(); $controller->setContainer($this->actionContainer());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->followup($this->tenant, $p, new Request([], ['action'=>'note','note'=>'forbidden']), $this->em);
    }
    public function testInvalidCsrfCannotUpdateProspect(): void
    {
        $p = $this->prospect('Protected');
        $controller = new ProspectWorkspaceController(); $controller->setContainer($this->actionContainer(false));
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $controller->followup($this->tenant, $p, new Request([], ['action'=>'note','note'=>'forbidden']), $this->em);
    }
    public function testCustomEmailIsSentOnceAndSchedulesReminder(): void
    {
        $p = $this->prospect('Mail');
        $transport = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $transport->expects(self::once())->method('send');
        $controller = $this->emailController($transport);
        $request = new Request([], ['_token'=>'valid','to'=>'recipient@example.test','templateId'=>'0','subject'=>'Formation','message'=>'Un échange ? <script>alert(1)</script>','idemKey'=>'test-mail-unique','followupDays'=>'7']);
        self::assertSame(200, $controller->send($this->tenant, $p, $request)->getStatusCode());
        self::assertSame(200, $controller->send($this->tenant, $p, $request)->getStatusCode());
        self::assertSame(ProspectStatus::CONTACTED, $p->getStatus());
        self::assertSame((new \DateTimeImmutable('+7 days', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'), $p->getNextActionAt()->format('Y-m-d'));
        $log = $this->em->getRepository(EmailLog::class)->findOneBy(['prospect'=>$p]);
        self::assertSame('SENT', $log->getStatus()); self::assertNull($log->getTemplate());
        self::assertStringContainsString('&lt;script&gt;', $log->getBodyHtmlSnapshot());
        self::assertSame(1, $this->em->getRepository(ProspectInteraction::class)->count(['prospect'=>$p]));
    }
    public function testFailedEmailDoesNotChangeFollowupOrResendSameKey(): void
    {
        $p = $this->prospect('Failure');
        $transport = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $transport->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Test failure'));
        $controller = $this->emailController($transport);
        $request = new Request([], ['_token'=>'valid','to'=>'recipient@example.test','subject'=>'Formation','message'=>'Test','idemKey'=>'test-failure','followupDays'=>'7']);
        self::assertSame(500, $controller->send($this->tenant, $p, $request)->getStatusCode());
        self::assertSame(409, $controller->send($this->tenant, $p, $request)->getStatusCode());
        self::assertNull($p->getNextActionAt()); self::assertSame(ProspectStatus::NEW, $p->getStatus());
        self::assertSame('FAILED', $this->em->getRepository(EmailLog::class)->findOneBy(['prospect'=>$p])->getStatus());
    }
    public function testGroupFollowupReportsIndividualSuccessAndRejection(): void
    {
        $open = $this->prospect('Open');
        $closed = $this->prospect('Closed')->setStatus(ProspectStatus::WON);
        $this->em->flush();
        $controller = new ProspectWorkspaceController(); $controller->setContainer($this->actionContainer());
        $request = new Request([], ['_token'=>'valid', 'action'=>'schedule', 'date'=>'2026-12-10', 'note'=>'Campagne de suivi']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $success = $controller->followup($this->tenant, $open, $request, $this->em);
        $failure = $controller->followup($this->tenant, $closed, $request, $this->em);
        self::assertSame(200, $success->getStatusCode());
        self::assertTrue(json_decode($success->getContent(), true)['ok']);
        self::assertSame(422, $failure->getStatusCode());
        self::assertFalse(json_decode($failure->getContent(), true)['ok']);
        self::assertSame('2026-12-10', $open->getNextActionAt()->format('Y-m-d'));
        self::assertNull($closed->getNextActionAt());
        self::assertSame(1, $this->em->getRepository(ProspectInteraction::class)->count(['prospect'=>$open]));
        self::assertSame(0, $this->em->getRepository(ProspectInteraction::class)->count(['prospect'=>$closed]));
    }
    public function testGroupEmailsHaveSeparateLogsAndRecipients(): void
    {
        $first = $this->prospect('First')->setEmail('first@example.test');
        $second = $this->prospect('Second')->setEmail('second@example.test');
        $this->em->flush();
        $recipients = [];
        $transport = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function ($email) use (&$recipients) {
            self::assertCount(1, $email->getTo());
            $recipients[] = $email->getTo()[0]->getAddress();
        });
        $controller = $this->emailController($transport);
        foreach ([$first, $second] as $p) {
            $request = new Request([], ['_token'=>'valid','to'=>$p->getEmail(),'subject'=>'Formation','message'=>'Message commun','idemKey'=>'group-'.$p->getId(),'followupDays'=>'3']);
            self::assertSame(200, $controller->send($this->tenant, $p, $request)->getStatusCode());
            self::assertSame(200, $controller->send($this->tenant, $p, $request)->getStatusCode());
            self::assertSame(1, $this->em->getRepository(EmailLog::class)->count(['prospect'=>$p]));
            self::assertSame(1, $this->em->getRepository(ProspectInteraction::class)->count(['prospect'=>$p]));
        }
        self::assertSame(['first@example.test', 'second@example.test'], $recipients);
    }

    public function testKanbanStatusChangePersistsHistoryAndClearsClosedReminder(): void
    {
        $p = $this->prospect('Kanban')->setNextActionAt(new \DateTimeImmutable('tomorrow'));
        $this->em->flush();
        $controller = new ProspectWorkspaceController(); $controller->setContainer($this->actionContainer());
        $request = new Request([], ['_token'=>'valid', 'action'=>'status', 'status'=>'won']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        self::assertSame(200, $controller->followup($this->tenant, $p, $request, $this->em)->getStatusCode());
        $this->em->refresh($p);
        self::assertSame(ProspectStatus::WON, $p->getStatus());
        self::assertNull($p->getNextActionAt());
        $history = $this->em->getRepository(ProspectInteraction::class)->findOneBy(['prospect'=>$p]);
        self::assertSame('Étape : Nouveau → Gagné', $history->getTitle());
        $request->request->set('status', 'converted');
        self::assertSame(422, $controller->followup($this->tenant, $p, $request, $this->em)->getStatusCode());
        self::assertSame(ProspectStatus::WON, $p->getStatus());
        self::assertSame(1, $this->em->getRepository(ProspectInteraction::class)->count(['prospect'=>$p]));
    }

    private function emailController(\Symfony\Component\Mailer\MailerInterface $transport): \App\Controller\Administrateur\ProspectEmailController
    {
        $container = $this->actionContainer();
        $twig = new Environment(new \Twig\Loader\ArrayLoader(['emails/prospect_generic_send.html.twig'=>'{{ message|raw }}']));
        $container->set('twig', $twig);
        $mailer = new \App\Service\Email\MailerManager($transport, $twig, $container->get('router'));
        $controller = new \App\Controller\Administrateur\ProspectEmailController($this->em, $this->em->getRepository(\App\Entity\EmailTemplate::class), $this->em->getRepository(EmailLog::class), $mailer, new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()));
        $controller->setContainer($container); return $controller;
    }

}
