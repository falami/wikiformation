<?php

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Enum\{DemiJournee, SessionPieceType, StatusSession, TypeFinancement};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Attendance alerts are tested against an isolated database, including legacy records. */
final class QcmFormationAssignmentTest extends KernelTestCase
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

    public function testOptionalModelsAndRemovingAssignments(): void
    {
        $manager = self::getContainer()->get(\App\Service\Qcm\QcmAssignmentManager::class);
        $repo = $this->em->getRepository(\App\Entity\QcmAssignment::class);
        $inscription = $this->internal->getInscriptions()->first();
        if (!$inscription) $inscription = $this->em->getRepository(Inscription::class)->findOneBy(['session' => $this->internal]);
        $qcm = (new \App\Entity\Qcm())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Excel');
        $this->em->persist($qcm); $this->em->flush();
        $manager->ensurePreAndPostAssignments($inscription, $this->admin, $this->entite);
        self::assertSame(0, $repo->count([]), 'No fallback to the latest active questionnaire.');
        $formation = $this->internal->getFormation();
        $formation->setQcmPre($qcm);
        $manager->ensurePreAndPostAssignments($inscription, $this->admin, $this->entite);
        self::assertSame(1, $repo->count([]));
        $formation->setQcmPost($qcm);
        $manager->ensurePreAndPostAssignments($inscription, $this->admin, $this->entite);
        $manager->ensurePreAndPostAssignments($inscription, $this->admin, $this->entite);
        self::assertSame(2, $repo->count([]));
        $assignment = $repo->findOneBy(['phase' => \App\Enum\QcmPhase::PRE]);
        $attempt = (new \App\Entity\QcmAttempt())->setAssignment($assignment)->setCreateur($this->admin)->setEntite($this->entite);
        $assignment->setAttempt($attempt);
        $this->em->persist($attempt); $this->em->flush();
        $inscriptionId = $inscription->getId();
        $url = self::getContainer()->get('router')->generate('app_administrateur_qcm_assignment_remove', ['entite' => $this->entite->getId(), 'id' => $assignment->getId()]);
        $client = $this->client();
        $client->catchExceptions(true);
        $attempt->setSubmittedAt(new \DateTimeImmutable())->setScorePoints(3)->setMaxPoints(4)->setScorePercent(75);
        $this->em->flush();
        $resultUrl = self::getContainer()->get('router')->generate('app_administrateur_qcm_attempt_show', ['entite' => $this->entite->getId(), 'attempt' => $attempt->getId()]);
        $client->request('GET', $resultUrl);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('75,0 %', $client->getResponse()->getContent());
        self::assertStringContainsString('3 / 4 points', $client->getResponse()->getContent());
        self::assertStringContainsString('Détail des réponses', $client->getResponse()->getContent());
        $ajaxUrl = self::getContainer()->get('router')->generate('app_administrateur_qcm_assignment_ajax', ['entite' => $this->entite->getId()]);
        $client->request('POST', $ajaxUrl, ['length' => 25]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $rows = json_decode($client->getResponse()->getContent(), true)['data'];
        $result = array_values(array_filter($rows, fn($r) => $r['assignmentId'] === $assignment->getId()))[0];
        self::assertStringContainsString('75,0 %', $result['result']);
        self::assertStringContainsString($resultUrl, $result['actions']);

        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', $url, ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, $repo->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAttempt::class)->count([]));
        $inscription = $this->em->find(Inscription::class, $inscriptionId);
        $manager->ensurePreAndPostAssignments($inscription, $this->em->find(Utilisateur::class, $this->admin->getId()), $this->em->find(Entite::class, $this->entite->getId()));
        self::assertSame(1, $repo->count([]), 'Removed assignments must not return.');
        self::assertSame(1, $this->em->getRepository(\App\Entity\Qcm::class)->count([]));
    }

    public function testInactiveOrForeignModelsAreNotAssignedAndSelectorsAreOptional(): void
    {
        $foreign = $this->em->getRepository(Entite::class)->findOneBy(['nom' => 'Organisme B']);
        $inactive = (new \App\Entity\Qcm())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Inactif')->setIsActive(false);
        $other = (new \App\Entity\Qcm())->setEntite($foreign)->setCreateur($this->admin)->setTitre('Autre organisme');
        $this->em->persist($inactive); $this->em->persist($other); $this->em->flush();
        $formation = $this->internal->getFormation();
        $formation->setQcmPre($inactive)->setQcmPost($other);
        $inscription = $this->em->getRepository(Inscription::class)->findOneBy(['session' => $this->internal]);
        self::getContainer()->get(\App\Service\Qcm\QcmAssignmentManager::class)->ensurePreAndPostAssignments($inscription, $this->admin, $this->entite);
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAssignment::class)->count([]));
        $formation->setQcmPre(null)->setQcmPost(null);
        $form = self::getContainer()->get('form.factory')->create(\App\Form\Administrateur\FormationType::class, $formation, ['entite' => $this->entite]);
        foreach (['qcmPre', 'qcmPost'] as $field) {
            self::assertFalse($form->get($field)->isRequired());
            self::assertSame('Aucun test de niveau', $form->get($field)->getConfig()->getOption('placeholder'));
            $form->get($field)->submit((string) $other->getId());
            self::assertFalse($form->get($field)->isSynchronized());
        }
    }

    public function testBatchActionsAreProtectedAndIdempotent(): void
    {
        $qcm = (new \App\Entity\Qcm())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Test groupé');
        $this->em->persist($qcm);
        $this->internal->getFormation()->setQcmPre($qcm)->setQcmPost($qcm);
        $ins = $this->em->getRepository(Inscription::class)->findOneBy(['session' => $this->internal]);
        self::getContainer()->get(\App\Service\Qcm\QcmAssignmentManager::class)->ensurePreAndPostAssignments($ins, $this->admin, $this->entite);
        $repo = $this->em->getRepository(\App\Entity\QcmAssignment::class);
        $ids = array_map(fn($a) => $a->getId(), $repo->findAll());
        $router = self::getContainer()->get('router');
        $url = $router->generate('app_administrateur_qcm_assignment_batch', ['entite' => $this->entite->getId()]);
        $client = $this->client(); $client->catchExceptions(true);
        $crawler = $client->request('GET', $router->generate('app_administrateur_qcm_assignment_index', ['entite' => $this->entite->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#qcmBatchModal')->attr('data-token');
        $client->request('POST', $url, ['action' => 'attempt', 'ids' => json_encode($ids), '_token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, ['action' => 'remove', 'ids' => '["invalid"]', '_token' => $token]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame(2, $repo->count([]));
        foreach ([2, 0] as $expected) {
            $client->request('POST', $url, ['action' => 'attempt', 'ids' => json_encode($ids), '_token' => $token]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame($expected, json_decode($client->getResponse()->getContent(), true)['changed']);
        }
        $client->request('POST', $url, ['action' => 'remove', 'ids' => json_encode($ids), '_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $repo->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAttempt::class)->count([]));
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

}
