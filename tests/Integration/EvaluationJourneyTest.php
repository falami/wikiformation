<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, EvaluationDossier, EvaluationTemplate, Formateur, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Service\Evaluation\{EvaluationWorkflow, EvaluationResultNotifier, WorkAtHeightPreset};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/** End-to-end requests use only isolated SQLite and a mocked mail transport. */
final class EvaluationJourneyTest extends KernelTestCase
{
    private array $database;
    private EntityManagerInterface $em;
    private $client;
    private Entite $tenant;
    private Utilisateur $admin;
    private Utilisateur $trainer;
    private Utilisateur $learner;
    private Utilisateur $outsider;
    private EvaluationDossier $dossier;
    private EvaluationTemplate $template;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(MailerInterface::class, $this->createMock(MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = $this->user('admin', 'Alice');
        $this->trainer = $this->user('trainer', 'Sam');
        $this->learner = $this->user('learner', 'Camille');
        $this->outsider = $this->user('outsider', 'Autre');
        $this->tenant = (new Entite())->setNom('Académie Horizon')->setPublic(false)->setCreateur($this->admin)->setEmail('formation@example.test')->setCouleurPrincipal('#233342')->setCouleurSecondaire('#d9b257');
        $this->em->persist($this->tenant); $this->em->flush();
        foreach ([[$this->admin, ['TENANT_ADMIN']], [$this->trainer, ['TENANT_FORMATEUR']], [$this->learner, ['TENANT_STAGIAIRE']], [$this->outsider, ['TENANT_STAGIAIRE', 'TENANT_FORMATEUR']]] as [$user, $roles]) {
            $user->setEntite($this->tenant);
            $this->em->persist((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->tenant)->setCreateur($this->admin)->setRoles($roles)->setStatus('active'));
        }
        $plan = (new \App\Entity\Billing\Plan())->setCode('evaluation-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->tenant)->setPlan($plan)->setStatus('active'));
        $formateur = (new Formateur())->setUtilisateur($this->trainer)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->trainer->setFormateur($formateur);
        $site = (new Site())->setNom('Centre Horizon')->setSlug('evaluation-centre')->setEntite($this->tenant)->setCreateur($this->admin);
        $formation = (new Formation())->setTitre('Travail en hauteur')->setSlug('hauteur-test')->setEntite($this->tenant)->setCreateur($this->admin);
        $session = (new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setCode('SES-EVAL-2026')->setSite($site)->setFormation($formation)->setFormateur($formateur);
        $session->addJour((new SessionJour())->setEntite($this->tenant)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('2026-11-02 09:00'))->setDateFin(new \DateTimeImmutable('2026-11-02 17:00')));
        $inscription = (new Inscription())->setSession($session)->setStagiaire($this->learner)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->template = (new EvaluationTemplate())->setEntite($this->tenant)->setCreateur($this->admin)->setTitle('Validation des acquis')->setSchema($this->schema());
        $this->dossier = (new EvaluationDossier())->setEntite($this->tenant)->setCreateur($this->admin)->setTemplate($this->template)->setInscription($inscription)->setEvaluator($this->trainer)->setSnapshot($this->workflow()->snapshotTemplate($this->template));
        foreach ([$formateur, $site, $formation, $session, $inscription, $this->template, $this->dossier] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot(); $this->client->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testAdminScreensAndPresetAreAvailableAlongsideLegacyQcm(): void
    {
        $this->client->loginUser($this->admin);
        foreach (['app_administrateur_evaluation_index', 'app_administrateur_evaluation_template_new', 'app_administrateur_evaluation_assign', 'app_administrateur_evaluation_show'] as $route) {
            $this->client->request('GET', $this->url($route));
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $route);
        }
        $this->client->request('GET', $this->url('app_administrateur_evaluation_template_new', ['preset' => 'height']));
        self::assertStringContainsString('Travail en hauteur', $this->client->getResponse()->getContent());
        $schema = $this->workflow()->validateTemplate((new WorkAtHeightPreset())->schema());
        self::assertCount(15, $schema['theory']); self::assertCount(8, $schema['practice']);
        self::assertSame(20.0, array_sum(array_column($schema['theory'], 'points')));
        self::assertSame(20.0, array_sum(array_column($schema['practice'], 'points')));
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAssignment::class)->count([]));
        $this->client->request('GET', $this->url('app_administrateur_qcm_assignment_index'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testLearnerSubmissionTrainerPublicationAndSingleNotification(): void
    {
        $this->client->loginUser($this->learner);
        $page = $this->client->request('GET', $this->url('app_stagiaire_evaluation_show'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('"correct"', $this->client->getResponse()->getContent());
        $token = $page->filter('input[name="_token"]')->attr('value');
        $version = $page->filter('input[name="version"]')->attr('value');
        $this->client->request('POST', $this->url('app_stagiaire_evaluation_show'), ['_token' => $token, 'version' => $version, 'answers' => ['q1' => 'o1'], 'submission_confirmed' => '1']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->dossier = $this->em->find(EvaluationDossier::class, $this->dossier->getId());
        $this->em->refresh($this->dossier);
        self::assertNotNull($this->dossier->getTheorySubmittedAt());
        self::assertNull($this->dossier->getPublishedAt());
        $this->client->request('GET', $this->url('app_stagiaire_evaluation_show'));
        self::assertStringNotContainsString('theoryGrading', $this->client->getResponse()->getContent());

        $this->client->loginUser($this->trainer);
        $page = $this->client->request('GET', $this->url('app_formateur_evaluation_show'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = ['_token' => $page->filter('input[name="_token"]')->attr('value'), 'version' => $page->filter('input[name="version"]')->attr('value'),
            'theory' => ['q1' => ['points' => '2', 'comment' => 'Acquis']], 'practice' => ['p1' => ['points' => '2', 'comment' => 'Geste maîtrisé']],
            'theory_verdict' => 'favorable', 'practice_verdict' => 'favorable', 'action' => 'publish', 'validation_confirmed' => '1'];
        $this->client->request('POST', $this->url('app_formateur_evaluation_show'), $form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->dossier = $this->em->find(EvaluationDossier::class, $this->dossier->getId());
        $this->em->refresh($this->dossier);
        self::assertTrue($this->dossier->isSuccessful());
        self::assertNotNull($this->dossier->getNotificationSentAt());
        $sent = $this->dossier->getNotificationSentAt();
        self::assertTrue(self::getContainer()->get(EvaluationResultNotifier::class)->notify($this->dossier));
        self::assertEquals($sent, $this->dossier->getNotificationSentAt());
        self::assertSame(1, $this->em->getRepository(\App\Entity\EmailLog::class)->count([]));

        $this->client->loginUser($this->learner);
        $this->client->request('GET', $this->url('app_stagiaire_evaluation_show'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('20', $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->url('app_evaluation_attestation'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->url('app_evaluation_results_pdf'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }

    public function testOwnershipTenantCsrfAndUnpublishedCertificateAreProtected(): void
    {
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->outsider);
        foreach (['app_stagiaire_evaluation_show', 'app_formateur_evaluation_show', 'app_evaluation_attestation'] as $route) {
            $this->client->request('GET', $this->url($route));
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], $route);
        }
        $this->client->loginUser($this->learner);
        $this->client->request('POST', $this->url('app_stagiaire_evaluation_show'), ['_token' => 'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_evaluation_attestation'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_evaluation_results_pdf'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $other = (new Entite())->setNom('Autre organisme')->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))->setPublic(false);
        $this->em->persist($other); $this->em->flush();
        $this->client->request('GET', $this->url('app_stagiaire_evaluation_show', ['entite' => $other->getId()]));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        self::assertNull($this->dossier->getTheorySubmittedAt());
    }

    public function testUnfavorableResultHasReportButNoCertificateAndSnapshotSurvivesEdits(): void
    {
        $workflow = $this->workflow();
        $workflow->submitTheory($this->dossier, ['q1' => 'o2']);
        $workflow->saveReview($this->dossier, ['q1' => ['points' => 0]], ['p1' => ['points' => 2]], 'defavorable', 'favorable');
        $workflow->publish($this->dossier); $this->em->flush();
        $this->template->setTitle('Titre modifié'); $this->learner->setPrenom('Prénom modifié'); $this->em->flush();
        self::assertSame('Camille Test', $this->dossier->getPublicationRecord()['learnerName']);
        self::assertSame('Validation des acquis', $this->dossier->getPublicationRecord()['title']);
        $this->client->loginUser($this->learner); $this->client->catchExceptions(true);
        $this->client->request('GET', $this->url('app_evaluation_attestation'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_evaluation_results_pdf'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function user(string $email, string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($email.'@example.test')->setPassword('unused')->setPrenom($name)->setNom('Test')->setRoles(['ROLE_USER']);
        $this->em->persist($user); return $user;
    }
    private function workflow(): EvaluationWorkflow { return self::getContainer()->get(EvaluationWorkflow::class); }
    private function schema(): array
    {
        return ['theory' => [['id' => 'q1', 'label' => 'Choisissez la bonne réponse', 'type' => 'single', 'points' => 2, 'options' => [['id' => 'o1', 'label' => 'Oui'], ['id' => 'o2', 'label' => 'Non']], 'correct' => ['o1']]],
            'practice' => [['id' => 'p1', 'label' => 'Vérifier son matériel', 'points' => 2, 'critical' => true, 'allowNa' => false]], 'theoryThreshold' => 10, 'practiceThreshold' => 10];
    }
    private function url(string $route, array $extra = []): string
    {
        $params = ['entite' => $this->tenant->getId(), '_locale' => 'fr'];
        if (in_array($route, ['app_administrateur_evaluation_show', 'app_stagiaire_evaluation_show', 'app_formateur_evaluation_show', 'app_evaluation_attestation', 'app_evaluation_results_pdf'], true)) $params['id'] = $this->dossier->getId();
        return self::getContainer()->get('router')->generate($route, array_replace($params, $extra));
    }
}
