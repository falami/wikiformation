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
final class EvaluationAdminTest extends KernelTestCase
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

    public function testModelCreationInvalidSchemaAndStaleEditKeepOldDossiersIntact(): void
    {
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', $this->url('app_administrateur_evaluation_template_new'));
        $token = $page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url('app_administrateur_evaluation_template_new'), ['_token' => $token, 'title' => 'Nouveau parcours', 'schema' => json_encode($this->schema()), 'active' => '1']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->em->getRepository(EvaluationTemplate::class)->count([]));
        $created = $this->em->getRepository(EvaluationTemplate::class)->findOneBy(['title'=>'Nouveau parcours']);
        self::assertTrue($created->isActive());
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAssignment::class)->count([]));
        $this->template = $created;
        $route = $this->url('app_administrateur_evaluation_template_edit', ['id' => $this->template->getId()]);
        $page = $this->client->request('GET', $route);
        $form = ['_token' => $page->filter('input[name="_token"]')->attr('value'), 'modelVersion' => $page->filter('input[name="modelVersion"]')->attr('value'), 'title' => 'Version mise à jour', 'schema' => json_encode($this->schema()), 'active' => '1'];
        $this->client->request('POST', $route, $form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->template = $this->em->find(EvaluationTemplate::class, $this->template->getId());
        self::assertSame(2, $this->template->getVersion());
        self::assertSame('Validation des acquis', $this->dossier->getSnapshot()['title']);
        $this->client->request('POST', $route, array_replace($form, ['title' => 'Écrasement périmé']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ce modèle a été modifié', $this->client->getResponse()->getContent());
        $this->template = $this->em->find(EvaluationTemplate::class, $this->template->getId());
        self::assertSame('Version mise à jour', $this->template->getTitle());
        $this->client->request('POST', $route, array_replace($form, ['schema' => '"invalid"']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Le contenu du modèle est invalide', $this->client->getResponse()->getContent());
    }

    public function testBatchAssignmentIsIdempotentAndRejectsForeignReferencesAndUnassignedTrainer(): void
    {
        $second = (new EvaluationTemplate())->setEntite($this->tenant)->setCreateur($this->admin)->setTitle('Seconde évaluation')->setSchema($this->schema());
        $this->em->persist($second); $this->em->flush();
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', $this->url('app_administrateur_evaluation_assign', ['session' => $this->dossier->getSession()->getId()]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = ['_token' => $page->filter('input[name="_token"]')->attr('value'), 'session' => $this->dossier->getSession()->getId(), 'template' => $second->getId(), 'evaluator' => $this->trainer->getId(), 'inscriptions' => [$this->dossier->getInscription()->getId()]];
        $url = $this->url('app_administrateur_evaluation_assign');
        foreach ([1,2] as $_) { $this->client->request('POST', $url, $form); self::assertSame(302, $this->client->getResponse()->getStatusCode()); }
        self::assertSame(2, $this->em->getRepository(EvaluationDossier::class)->count([]));
        $this->client->request('POST', $url, array_replace($form, ['evaluator' => $this->outsider->getId()]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('formateur intervenant sur cette session', $this->client->getResponse()->getContent());
        $this->client->request('POST', $url, array_replace($form, ['inscriptions' => [['malformed']]]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->admin = $this->em->find(Utilisateur::class, $this->admin->getId());
        $other = (new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);
        $foreign = (new EvaluationTemplate())->setEntite($other)->setCreateur($this->admin)->setTitle('Modèle privé')->setSchema($this->schema());
        $this->em->persist($other); $this->em->persist($foreign); $this->em->flush();
        $this->client->request('POST', $url, array_replace($form, ['template' => $foreign->getId()]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->em->getRepository(EvaluationDossier::class)->count([]));
    }

    public function testRetakeRequiresPublicationAndKeepsOriginalFrozen(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->url('app_administrateur_evaluation_show'));
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try { $token = self::getContainer()->get('security.csrf.token_manager')->getToken('evaluation_retake_'.$this->dossier->getId())->getValue(); $this->client->getRequest()->getSession()->save(); } finally { $stack->pop(); }
        $route = $this->url('app_administrateur_evaluation_retake', ['id' => $this->dossier->getId()]);
        $form = ['_token'=>$token, 'evaluator'=>$this->trainer->getId()];
        $this->client->request('POST', $route, $form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EvaluationDossier::class)->count([]));
        $this->dossier = $this->em->find(EvaluationDossier::class, $this->dossier->getId());
        $this->workflow()->submitTheory($this->dossier, ['q1'=>'o2']);
        $this->workflow()->saveReview($this->dossier, ['q1'=>['points'=>0]], ['p1'=>['points'=>2]], 'defavorable', 'favorable');
        $this->workflow()->publish($this->dossier); $this->em->flush();
        foreach ([1,2] as $_) { $this->client->request('POST', $route, $form); self::assertSame(302, $this->client->getResponse()->getStatusCode()); }
        self::assertSame(2, $this->em->getRepository(EvaluationDossier::class)->count([]));
        $next = $this->em->getRepository(EvaluationDossier::class)->findOneBy(['previousDossier'=>$this->dossier]);
        self::assertEquals($this->dossier->getSnapshot(), $next->getSnapshot());
        self::assertSame([], $next->getTheoryAnswers());
        self::assertNull($next->getPublishedAt());
        self::assertNotNull($this->dossier->getPublishedAt());
    }

    public function testPublishedOwnershipSurvivesRegistrationChangesAndBlocksWrongRetake(): void
    {
        $this->workflow()->submitTheory($this->dossier, ['q1' => 'o2']);
        $this->workflow()->saveReview($this->dossier, ['q1' => ['points' => 0]], ['p1' => ['points' => 2]], 'defavorable', 'favorable');
        $this->workflow()->publish($this->dossier);
        $original = $this->dossier->getSession();
        $replacement = (new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setCode('SES-REPLACEMENT')
            ->setSite($original->getSite())->setFormation($original->getFormation())->setFormateur($original->getFormateur());
        $this->em->persist($replacement);
        $this->dossier->getInscription()->setStagiaire($this->outsider)->setSession($replacement);
        $this->em->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->url('app_administrateur_evaluation_index', ['q' => 'SES-EVAL-2026']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Camille Test', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('SES-REPLACEMENT', $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $this->url('app_administrateur_evaluation_show'));
        $retake = $page->filter('form[action$="/nouvelle-tentative"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url('app_administrateur_evaluation_retake', ['id' => $this->dossier->getId()]), ['_token' => $retake, 'evaluator' => $this->trainer->getId()]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EvaluationDossier::class)->count([]));
        $this->client->followRedirect();
        self::assertStringContainsString('L’inscription a changé de stagiaire ou de session', $this->client->getResponse()->getContent());
    }

    public function testAssignedModelCannotBeEditedAndDuplicateKeepsOwnership(): void
    {
        $this->client->loginUser($this->admin);
        $originalId = $this->template->getId();
        $route = $this->url('app_administrateur_evaluation_template_edit', ['id'=>$originalId]);
        $page = $this->client->request('GET', $route);
        self::assertSame(0, $page->filter('#evaluation-builder')->count());
        self::assertStringContainsString('protégés', $this->client->getResponse()->getContent());
        $this->client->request('POST', $route, ['title'=>'Écrasement interdit', 'schema'=>json_encode($this->schema())]);
        self::assertSame('Validation des acquis', $this->em->find(EvaluationTemplate::class, $originalId)->getTitle());
        $page = $this->client->request('GET', $route);
        $copyToken = $page->filter('form[action$="/dupliquer"] input')->attr('value');
        $this->client->request('POST', $this->url('app_administrateur_evaluation_template_lifecycle', ['id'=>$originalId,'action'=>'dupliquer']), ['_token'=>$copyToken]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $copy = $this->em->getRepository(EvaluationTemplate::class)->findOneBy(['sourceTemplate'=>$originalId]);
        self::assertNotNull($copy); self::assertFalse($copy->isActive()); self::assertNull($copy->getAssignedAt()); self::assertNull($copy->getTrainerOwner());
        self::assertEquals($this->template->getSchema(), $copy->getSchema());
    }

    public function testTrainerPublicationSharingAssignmentArchiveAndPrivateDrafts(): void
    {
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->trainer);
        $new = $this->url('app_formateur_evaluation_template_new');
        $page = $this->client->request('GET', $new);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $token = $page->filter('#evaluation-builder input[name="_token"]')->attr('value');
        $this->client->request('POST', $new, ['_token'=>$token,'title'=>'Expertise personnelle','schema'=>json_encode($this->schema())]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $model = $this->em->getRepository(EvaluationTemplate::class)->findOneBy(['title'=>'Expertise personnelle']);
        self::assertSame($this->trainer->getId(), $model->getTrainerOwner()->getId());
        $id = $model->getId();
        $library = self::getContainer()->get(\App\Service\Evaluation\EvaluationLibrary::class);
        self::assertFalse($library->available($model, $this->tenant));
        $route = $this->url('app_formateur_evaluation_template_edit', ['id'=>$id]);
        $page = $this->client->request('GET', $route);
        $token = $page->filter('#evaluation-builder input[name="_token"]')->attr('value');
        $this->client->request('POST', $route, ['_token'=>$token,'modelVersion'=>1,'title'=>'Expertise personnelle','schema'=>json_encode($this->schema()),'active'=>'1']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $model = $this->em->find(EvaluationTemplate::class, $id);
        self::assertFalse($library->available($model, $this->tenant));
        self::assertNotContains($model, $library->catalog($this->tenant));
        $permissions = $this->url('app_formateur_evaluation_template_permissions', ['id'=>$id]);
        $page = $this->client->request('GET', $permissions);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $permissionToken = $page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $permissions, ['_token'=>'invalid', 'organisms'=>[$this->tenant->getId()]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $permissions, ['_token'=>$permissionToken, 'organisms'=>['999999']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $permissions, ['_token'=>$permissionToken, 'organisms'=>[$this->tenant->getId()]]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $model = $this->em->find(EvaluationTemplate::class, $id);
        self::assertTrue($library->available($model, $this->tenant));
        self::assertContains($model, $library->catalog($this->tenant));
        $copy = $library->duplicate($model, $this->em->find(Utilisateur::class, $this->trainer->getId()));
        self::assertCount(0, $copy->getAuthorizedEntites());
        // Cross-tenant use requires both membership and explicit authorization.
        $this->admin = $this->em->find(Utilisateur::class, $this->admin->getId());
        $other = (new Entite())->setNom('Origine du formateur')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($other); $model->setEntite($other); $this->em->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->url('app_administrateur_evaluation_template_edit', ['id'=>$id]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $this->url('app_administrateur_evaluation_assign', ['session'=>$this->dossier->getSession()->getId()]));
        $token = $page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url('app_administrateur_evaluation_assign'), ['_token'=>$token,'session'=>$this->dossier->getSession()->getId(),'template'=>$id,'evaluator'=>$this->trainer->getId(),'inscriptions'=>[$this->dossier->getInscription()->getId()]]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $assigned = $this->em->getRepository(EvaluationDossier::class)->findOneBy(['template'=>$id]);
        self::assertNotNull($assigned);
        $model = $this->em->find(EvaluationTemplate::class, $id); self::assertNotNull($model->getAssignedAt());
        $this->client->loginUser($this->trainer);
        $this->client->request('POST', $permissions, ['_token'=>$permissionToken]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $model = $this->em->find(EvaluationTemplate::class, $id);
        self::assertFalse($library->available($model, $this->tenant));
        self::assertNotContains($model, $library->catalog($this->tenant));
        $page = $this->client->request('GET', $route);
        self::assertSame(0, $page->filter('#evaluation-builder')->count());
        $token = $page->filter('form[action$="/archiver"] input')->attr('value');
        $this->client->request('POST', $this->url('app_formateur_evaluation_template_lifecycle', ['id'=>$id,'action'=>'archiver']), ['_token'=>$token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $model = $this->em->find(EvaluationTemplate::class, $id);
        self::assertNotNull($model->getArchivedAt()); self::assertFalse($library->available($model, $this->tenant));
        $assigned = $this->em->find(EvaluationDossier::class, $assigned->getId());
        $this->workflow()->submitTheory($assigned, ['q1'=>'o1']);
        self::assertNotNull($assigned->getTheorySubmittedAt());
        self::assertSame('Expertise personnelle', $assigned->getSnapshot()['title']);
        $this->em->flush();
        foreach ([[$this->learner,'app_stagiaire_evaluation_show'],[$this->trainer,'app_formateur_evaluation_show'],[$this->admin,'app_administrateur_evaluation_show']] as [$user,$portal]) {
            $this->client->loginUser($user);
            $this->client->request('GET', $this->url($portal, ['id'=>$assigned->getId()]));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testPrivateModelsPartnerRolesAndCsrfAreEnforced(): void
    {
        $library = self::getContainer()->get(\App\Service\Evaluation\EvaluationLibrary::class);
        $shared = (new EvaluationTemplate())->setEntite($this->tenant)->setCreateur($this->trainer)->setTrainerOwner($this->trainer)->setTitle('Modèle du formateur')->setSchema($this->schema())->setActive(true);
        $private = (new EvaluationTemplate())->setEntite($this->tenant)->setCreateur($this->trainer)->setTrainerOwner($this->trainer)->setTitle('Brouillon secret')->setSchema($this->schema())->setActive(false);
        $this->em->persist($shared); $this->em->persist($private); $this->em->flush();
        self::assertFalse($library->available($shared, $this->tenant));
        $shared->setAuthorizedEntites([$this->tenant]); $this->em->flush();
        self::assertTrue($library->available($shared, $this->tenant));
        self::assertFalse($library->available($private, $this->tenant));
        self::assertNotContains($private, $library->catalog($this->tenant));
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$this->trainer,'entite'=>$this->tenant]);
        $membership->setRoles(['TENANT_STAGIAIRE']); $this->em->flush();
        self::assertFalse($library->available($shared, $this->tenant));
        self::assertNotContains($shared, $library->catalog($this->tenant));
        $membership->setRoles(['TENANT_FORMATEUR'])->setStatus(UtilisateurEntite::STATUS_SUSPENDED); $this->em->flush();
        self::assertFalse($library->available($shared, $this->tenant));
        $membership->setStatus(UtilisateurEntite::STATUS_ACTIVE); $this->em->flush();
        $this->client->catchExceptions(true); $this->client->loginUser($this->outsider);
        $this->client->request('GET', $this->url('app_formateur_evaluation_template_edit', ['id'=>$shared->getId()]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->trainer);
        $this->client->request('POST', $this->url('app_formateur_evaluation_template_lifecycle', ['id'=>$shared->getId(),'action'=>'archiver']), ['_token'=>'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em->find(EvaluationTemplate::class, $shared->getId())->getArchivedAt());
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
