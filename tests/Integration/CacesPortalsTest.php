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
final class CacesPortalsTest extends KernelTestCase
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
    private \App\Entity\CacesAttempt $caces;

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
        $library=self::getContainer()->get(\App\Service\Caces\CacesLibrary::class);
        $exam=(new \App\Entity\CacesExam())->setEntite($this->tenant)->setSession($session)->setCreateur($this->admin)->setTheoryTester($this->trainer)->setPracticeTester($this->trainer)->setRecommendation('489')->setCategory('3')->setLocation('Centre test')->setTheorySnapshot($library->find($this->tenant,'r489-v1-202412-g1'))->setPracticeSnapshot($library->find($this->tenant,'r489-cat3-pratique-202607'));
        $this->caces=(new \App\Entity\CacesAttempt())->setEntite($this->tenant)->setExam($exam)->setInscription($inscription)->setLearner($this->learner);
        $this->em->persist($exam);$this->em->persist($this->caces);$this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot(); $this->client->catchExceptions(false);
    }

    public function testPracticeScoresAutosaveWithoutSigningAndSurviveReload(): void
    {
        $this->client->loginUser($this->trainer);
        $id = $this->caces->getId();
        $q = $this->caces->getExam()->getPracticeSnapshot()['schema']['themes'][0]['questions'][0];
        $page = $this->client->request('GET', $this->url('app_caces_show', ['id'=>$id]));
        $token = $page->filter('[data-practice-clock] input[name="_token"]')->attr('value');
        $url = $this->url('app_caces_practice_draft', ['id'=>$id]);
        $payload = ['_token'=>$token, 'revision'=>0, 'scores'=>[$q['id']=>'0'], 'equipment'=>'Chariot test'];
        $this->client->request('POST', $url, $payload);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->caces = $this->em->find(\App\Entity\CacesAttempt::class, $id);
        $this->em->refresh($this->caces);
        self::assertSame('0', $this->caces->getPracticeDraft()['values']['scores['.$q['id'].']']);
        self::assertNull($this->caces->getPracticeAt());
        self::assertSame([], $this->caces->getPracticeResult());
        self::assertSame([], $this->caces->getSignatures());
        $page = $this->client->request('GET', $this->url('app_caces_show', ['id'=>$id]));
        $saved = json_decode($page->filter('[data-practice-saved]')->attr('data-practice-saved'), true);
        self::assertSame('0', $saved['values']['scores['.$q['id'].']']);
        $this->client->request('POST', $url, $payload);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $payload['revision']=1;
        $payload['scores'][$q['id']]='999';
        $this->client->request('POST', $url, $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload['scores'][$q['id']]='';
        $this->client->request('POST', $url, $payload);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->caces = $this->em->find(\App\Entity\CacesAttempt::class, $id);
        $this->em->refresh($this->caces);
        self::assertSame('', $this->caces->getPracticeDraft()['values']['scores['.$q['id'].']']);
        $this->caces->setPracticeAt(new \DateTimeImmutable()); $this->em->flush();
        $payload['revision']=2;
        $this->client->request('POST', $url, $payload);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testUnusedGridCanBeDeletedButAssignedGridIsProtected(): void
    {
        $this->client->loginUser($this->admin);
        $template = (new \App\Entity\CacesTemplate())->setEntite($this->tenant)->setTitle('Grille à supprimer');
        $this->em->persist($template); $this->em->flush();
        $id = $template->getId();
        $page = $this->client->request('GET', $this->url('app_caces_index'));
        $action = $this->url('app_caces_delete', ['id'=>$id]);
        $token = $page->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        $this->caces->getExam()->setTheorySnapshot(['key'=>'custom-'.$id]);
        $this->em->flush();
        $this->client->request('POST', $action, ['_token'=>$token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->em->find(\App\Entity\CacesTemplate::class, $id));
        $page = $this->client->request('GET', $this->url('app_caces_index'));
        self::assertCount(0, $page->filter('form[action="'.$action.'"]'));
        $exam = $this->em->find(\App\Entity\CacesExam::class, $this->caces->getExam()->getId());
        $exam->setTheorySnapshot(['key'=>'official']);
        $this->em->flush();
        $this->client->request('POST', $action, ['_token'=>$token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\CacesTemplate::class)->count(['id'=>$id]));
    }

    public function testGridDeletionRequiresCsrfAndTenantOwnership(): void
    {
        $this->client->loginUser($this->admin);
        $template = (new \App\Entity\CacesTemplate())->setEntite($this->tenant)->setTitle('Protégée');
        $this->em->persist($template); $this->em->flush();
        $id = $template->getId();
        $action = $this->url('app_caces_delete', ['id'=>$id]);
        $page = $this->client->request('GET', $this->url('app_caces_index'));
        $token = $page->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        $this->client->catchExceptions(true);
        $this->client->request('POST', $action, ['_token'=>'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $template = $this->em->find(\App\Entity\CacesTemplate::class, $id);
        $template->setEntite(null);
        $this->em->flush();
        $this->client->request('POST', $action, ['_token'=>$token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Entity\CacesTemplate::class)->count(['id'=>$id]));
    }

    public function testAdminPagesRenderAndExistingEvaluationRemainsIntact(): void
    {
        $this->client->loginUser($this->admin);
        foreach(['app_caces_index','app_caces_assign','app_caces_model','app_administrateur_evaluation_index'] as $route){$this->client->request('GET',$this->url($route));self::assertSame(200,$this->client->getResponse()->getStatusCode(),$route);if($route==='app_caces_assign' && getenv('CACES_QA_OUTPUT')) file_put_contents(getenv('CACES_QA_OUTPUT').'/multi-assign.html',$this->client->getResponse()->getContent());}
        $this->client->request('GET',$this->url('app_caces_show',['id'=>$this->caces->getId()]));self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $this->client->request('GET',$this->url('app_caces_export'));self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame('Validation des acquis',$this->template->getTitle());
    }
    public function testLearnerCanStartSaveAndSubmitWithoutAnswerKeyLeak(): void
    {
        $this->client->loginUser($this->learner);$url=$this->url('app_caces_theory',['id'=>$this->caces->getId()]);
        $page=$this->client->request('GET',$url);self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'start']);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $page=$this->client->followRedirect();self::assertCount(200,$page->filter('input[type="radio"]'));self::assertStringNotContainsString('"correct"',$this->client->getResponse()->getContent());
        $token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'save','answers'=>['t1q1'=>'B']]);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $this->caces=$this->em->find(\App\Entity\CacesAttempt::class,$this->caces->getId());self::assertSame(['t1q1'=>'B'],$this->caces->getAnswers());
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'submit','answers'=>['t1q1'=>'B']]);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();self::assertSame(200,$this->client->getResponse()->getStatusCode());$this->caces=$this->em->find(\App\Entity\CacesAttempt::class,$this->caces->getId());self::assertSame(1,$this->caces->getTheoryResult()['score']);
    }
    public function testOtherLearnersAndUnassignedTrainersCannotReadRecordsOrSources(): void
    {
        $this->client->catchExceptions(true);$this->client->loginUser($this->outsider);
        foreach(['app_caces_theory','app_caces_show','app_caces_source','app_caces_document'] as $route){$this->client->request('GET',$this->url($route,['id'=>$this->caces->getId(),'page'=>1,'type'=>'synthese']));self::assertContains($this->client->getResponse()->getStatusCode(),[403,404],$route);}
        $this->client->request('GET',$this->url('app_caces_model'));self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }
    public function testCsrfCannotStartTest(): void
    {
        $this->client->catchExceptions(true);$this->client->loginUser($this->learner);
        $this->client->request('POST',$this->url('app_caces_theory',['id'=>$this->caces->getId()]),['_token'=>'bad','action'=>'start']);self::assertSame(403,$this->client->getResponse()->getStatusCode());self::assertNull($this->caces->getStartedAt());
    }
    public function testSignedJourneyProducesDocumentsAndFreezesCertificate(): void
    {
        $id=$this->caces->getId();$url=$this->url('app_caces_theory',['id'=>$id]);
        $this->client->loginUser($this->learner);$page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'start']);
        $answers=[];foreach($this->caces->getExam()->getTheorySnapshot()['schema']['themes'] as $t)foreach($t['questions'] as $q)$answers[$q['id']]=$q['correct'];
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'submit','answers'=>$answers]);
        $sig='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=';
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'profile','signature'=>$sig,'birthDate'=>'1990-01-01','birthPlace'=>'Paris','address'=>'Adresse test','phone'=>'0100000000','company'=>'Test','drivingExperience'=>'Non','trainingExperience'=>'Oui']);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $show=$this->url('app_caces_show',['id'=>$id]);$this->client->loginUser($this->trainer);$page=$this->client->request('GET',$show);self::assertSame(200,$this->client->getResponse()->getStatusCode());$token=$page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST',$show,['_token'=>$token,'action'=>'theorySign','signature'=>$sig]);
        $scores=[];foreach($this->caces->getExam()->getPracticeSnapshot()['schema']['themes'] as $t)foreach($t['questions'] as $q)$scores[$q['id']]=$q['points'];
        $this->client->request('POST',$show,['_token'=>$token,'action'=>'practice','signature'=>$sig,'scores'=>$scores,'durationSeconds'=>'3500','practiceDate'=>date('Y-m-d'),'equipment'=>'Engin test']);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->admin);$page=$this->client->request('GET',$show);$token=$page->filter('input[name="_token"]')->first()->attr('value');$this->client->request('POST',$show,['_token'=>$token,'action'=>'certify']);
        $a=$this->em->find(\App\Entity\CacesAttempt::class,$id);self::assertNotEmpty($a->getCertificate());self::assertCount(3,$a->getSignatures());self::assertSame(100,$a->getPracticeResult()['score']);
        foreach(['synthese','attestation','carte'] as $type){
            $page=$this->client->request('GET',$this->url('app_caces_document',['id'=>$id,'type'=>$type]));
            self::assertSame(200,$this->client->getResponse()->getStatusCode());
            if ($type === 'carte') {
                $html = $this->client->getResponse()->getContent();
                self::assertStringNotContainsString('workspace-shell', $html);
                self::assertStringNotContainsString('workspace-layout.css', $html);
                $stylesheets = $page->filter('link[rel="stylesheet"]')->extract(['href']);
                self::assertCount(1, $stylesheets, 'The printable card must be isolated from administrative layout styles.');
                self::assertStringContainsString('/dist/css/caces-card.css', $stylesheets[0]);
                self::assertStringContainsString('03.3237295', $page->text());
                self::assertStringContainsString('AIPR', $page->text());
                self::assertCount(1, $page->filter('img[src*="assurance-maladie-risques-professionnels.png"]'));
                foreach ($page->filter('.cc-page') as $face) {
                    $text = $face->textContent;
                    self::assertStringContainsString('CACES', $text);
                    self::assertStringContainsString($a->getCertificate()['learner'], $text);
                    self::assertStringContainsString($a->getCertificate()['number'], $text);
                    self::assertStringContainsString('Toute copie doit comporter les deux faces', $text);
                }
                self::assertCount(2, $page->filter('.cc-page'));
                self::assertCount(9, $page->filter('.cc-table tbody tr'));
                self::assertCount(1, $page->filter('[data-card-certified="3"]'));
                self::assertCount(24, $page->filter('.cc-crossed'));
                self::assertStringContainsString($a->getCertificate()['number'], $page->filter('.cc-numbers')->text());
                self::assertStringContainsString($a->getCertificate()['practiceTester'], $page->filter('[data-card-certified="3"]')->text());
            }
        }
    }
    public function testSessionAcceptsTesterWhoIsNotItsTrainer(): void
    {
        $base=$this->caces->getExam()->getSession();
        $other=(new Formateur())->setUtilisateur($this->outsider)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($other);
        $session=(new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setCode('CACES-OTHER')->setFormation($base->getFormation())->setSite($base->getSite())->setFormateur($base->getFormateur());
        $registration=(new Inscription())->setSession($session)->setStagiaire($this->learner)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($session);$this->em->persist($registration);$this->em->flush();
        $this->client->loginUser($this->admin);$url=$this->url('app_caces_assign');$page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'session'=>$session->getId(),'recommendation'=>'489','category'=>'3','location'=>'Centre test','theoryTester'=>$this->trainer->getId(),'practiceTester'=>$this->outsider->getId(),'pool'=>'official','practice'=>'r489-cat3-pratique-202607-referentiel']);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());$exam=$this->em->getRepository(\App\Entity\CacesExam::class)->findOneBy(['session'=>$session]);self::assertNotNull($exam);self::assertSame($this->outsider->getId(),$exam->getPracticeTester()->getId());self::assertNotSame($base->getFormateur()->getUtilisateur()->getId(),$exam->getPracticeTester()->getId());
    }
    public function testExternalExtensionRecordsEvidenceWithoutInventingScore(): void
    {
        $this->client->loginUser($this->admin);$url=$this->url('app_caces_show',['id'=>$this->caces->getId()]);$page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'externalReuse','initialDate'=>(new \DateTimeImmutable('-3 months'))->format('Y-m-d'),'certificateReference'=>'EXT-123','certificateIssuer'=>'Centre externe','evidenceChecked'=>'1']);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());$a=$this->em->find(\App\Entity\CacesAttempt::class,$this->caces->getId());self::assertTrue($a->getTheoryResult()['passed']);self::assertNull($a->getTheoryResult()['score']);self::assertSame('EXT-123',$a->getTheoryResult()['externalEvidence']['reference']);
        $this->client->followRedirect();self::assertSame(200,$this->client->getResponse()->getStatusCode());
    }
    public function testPracticeCreationContextAndSessionReassignment(): void
    {
        $id=$this->caces->getId();$sessionId=$this->caces->getExam()->getSession()->getId();$oldId=$this->trainer->getId();$newId=$this->outsider->getId();
        $other=(new Formateur())->setUtilisateur($this->outsider)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($other);
        $this->caces->setPracticeAt(new \DateTimeImmutable())->setPracticeResult(['score'=>100,'passed'=>true,'themes'=>[],'reasons'=>[]])->setSignatures(['practiceTester'=>['name'=>'Testeur initial','at'=>(new \DateTimeImmutable())->format(DATE_ATOM),'image'=>'data:image/png;base64,AA==']]);$this->em->flush();
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url('app_caces_model'),['kind'=>'practice','recommendation'=>'482','category'=>'A']);
        self::assertSame('practice',$page->filter('#kind option[selected]')->attr('value'));
        self::assertSame('482',$page->filter('#recommendation option[selected]')->attr('value'));
        $page=$this->client->request('GET',$this->url('app_caces_show',['id'=>$id]));
        $token=$page->filter('#assignedTester')->ancestors()->filter('form')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$this->url('app_caces_practice_tester',['id'=>$sessionId]),['_token'=>$token,'practiceTester'=>$newId]);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $attempt=$this->em->find(\App\Entity\CacesAttempt::class,$id);
        self::assertSame($newId,$attempt->getExam()->getPracticeTester()->getId());
        self::assertSame($oldId,$attempt->getPracticeResult()['testerId']);
        self::assertSame('Testeur initial',$attempt->getPracticeResult()['testerName']);
        self::assertCount(1,$attempt->getExam()->getPracticeSnapshot()['testerChanges']);
    }

    public function testPracticalLibraryIsAvailableAndTesterCanScoreOutOfTen(): void
    {
        $library=self::getContainer()->get(\App\Service\Caces\CacesLibrary::class);
        $template=$library->find($this->tenant,'r489-cat1a-pratique-202607');
        $id=$this->caces->getId();$this->caces->getExam()->setCategory('1A')->setPracticeSnapshot($template);$this->em->flush();
        $this->client->loginUser($this->trainer);
        $page=$this->client->request('GET',$this->url('app_caces_show',['id'=>$id]));
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertCount(2,$page->filter('select[name^="scores"] option[value="10"]'));
        self::assertStringContainsString('Circuler à vide',$page->text());
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url('app_caces_assign'));
        self::assertCount(37,$page->filter('[data-field=template] option[data-rec]'));
        $page=$this->client->request('GET',$this->url('app_caces_model'),['copy'=>$template['key']]);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('R489A-juillet2026.pdf',$page->text());
        $this->client->request('GET',$this->url('app_caces_model_source',['key'=>$template['key'],'page'=>34]));
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame('image/png',$this->client->getResponse()->headers->get('Content-Type'));
        if(getenv('CACES_QA_OUTPUT')) {
            $this->client->request('GET',$this->url('app_caces_model'),['copy'=>$template['key']]);
            file_put_contents(getenv('CACES_QA_OUTPUT').'/qa-caces-practice-local.html',$this->client->getResponse()->getContent());
            $this->client->loginUser($this->trainer);$this->client->request('GET',$this->url('app_caces_show',['id'=>$id]));
            file_put_contents(getenv('CACES_QA_OUTPUT').'/qa-caces-score-local.html',$this->client->getResponse()->getContent());
        }

    }

    public function testOptionalRenderedQaFixture(): void
    {
        if(!getenv('CACES_QA_OUTPUT')){self::assertTrue(true);return;}
        $this->client->loginUser($this->admin);$this->client->request('GET',$this->url('app_caces_index'));
        file_put_contents(getenv('CACES_QA_OUTPUT').'/index.html',str_replace(['href="/dist/','src="/dist/'],['href="http://localhost:8888/dist/','src="http://localhost:8888/dist/'],$this->client->getResponse()->getContent()));self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->learner);$this->client->request('GET',$this->url('app_caces_learner'));
        file_put_contents(getenv('CACES_QA_OUTPUT').'/qa-caces-learner-local.html',$this->client->getResponse()->getContent());
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
    }
    public function testMultipleCategoriesShareOneTheoryAndKeepIndependentTesters(): void
    {
        [$session,$registration]=$this->multiSession();
        $other=(new Formateur())->setUtilisateur($this->outsider)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($other);$this->em->flush();
        $payload=$this->multiPayload($session);
        $payload['practices'][1]['tester']=$this->outsider->getId();
        $this->postMulti($payload);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $exams=$this->em->getRepository(\App\Entity\CacesExam::class)->findBy(['session'=>$session],['id'=>'ASC']);
        self::assertCount(3,$exams);
        self::assertSame($exams[0]->getTheorySnapshot(),$exams[2]->getTheorySnapshot());
        self::assertSame($this->outsider->getId(),$exams[1]->getPracticeTester()->getId());
        $attempts=array_map(fn($exam)=>$this->em->getRepository(\App\Entity\CacesAttempt::class)->findOneBy(['exam'=>$exam]),$exams);
        [$source,$second,$third]=$attempts;
        self::assertSame($source,$second->getTheorySource());self::assertSame($source,$third->getTheorySource());
        $this->client->loginUser($this->learner);
        $page=$this->client->request('GET',$this->url('app_caces_learner'));
        self::assertCount(2,$page->filter('.cl-test-card')); // Existing fixture + one shared theory.
        $this->client->request('GET',$this->url('app_caces_theory',['id'=>$second->getId()]));
        self::assertSame(307,$this->client->getResponse()->getStatusCode());
        $url=$this->url('app_caces_theory',['id'=>$source->getId()]);
        $page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'start']);
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'submit','answers'=>[]]);
        $source=$this->em->find(\App\Entity\CacesAttempt::class,$source->getId());
        $second=$this->em->find(\App\Entity\CacesAttempt::class,$second->getId());
        $third=$this->em->find(\App\Entity\CacesAttempt::class,$third->getId());
        $this->em->refresh($source);
        self::assertFalse($second->getTheoryResult()['passed']);self::assertSame($source->getSubmittedAt(),$third->getSubmittedAt());
        $sig='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=';
        $this->client->loginUser($this->trainer);
        $show=$this->url('app_caces_show',['id'=>$second->getId()]);
        $page=$this->client->request('GET',$show);$token=$page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST',$show,['_token'=>$token,'action'=>'theorySign','signature'=>$sig]);
        $source=$this->em->find(\App\Entity\CacesAttempt::class,$source->getId());
        $second=$this->em->find(\App\Entity\CacesAttempt::class,$second->getId());
        $third=$this->em->find(\App\Entity\CacesAttempt::class,$third->getId());
        $this->em->refresh($source);
        self::assertArrayHasKey('theoryTester',$third->getSignatures());
        self::assertArrayNotHasKey('practiceTester',$third->getSignatures());
        $this->client->catchExceptions(true);
        $this->client->request('POST',$show,['_token'=>$token,'action'=>'practice']);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url('app_caces_document',['id'=>$source->getId(),'type'=>'synthese']));
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Cat. 1A',$page->text());self::assertStringContainsString('Cat. 1B',$page->text());self::assertStringContainsString('Cat. 2B',$page->text());
        self::assertCount(4,$page->filter('.cx-document-sheet'));
        if(getenv('CACES_QA_OUTPUT')) file_put_contents(getenv('CACES_QA_OUTPUT').'/multi-synthesis.html',$this->client->getResponse()->getContent());
    }

    public function testInvalidMultiAssignmentIsAtomicAndPreservesForm(): void
    {
        [$session]=$this->multiSession();
        $payload=$this->multiPayload($session);
        $payload['practices'][2]['category']='1A';
        $this->postMulti($payload);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Une catégorie ne peut être ajoutée',$this->client->getResponse()->getContent());
        self::assertSame(0,$this->em->getRepository(\App\Entity\CacesExam::class)->count(['session'=>$session]));
        $payload=$this->multiPayload($session);$payload['practices'][2]['learners']=[$this->caces->getInscription()->getId()];
        $this->postMulti($payload);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne fait pas partie',$this->client->getResponse()->getContent());
        self::assertSame(0,$this->em->getRepository(\App\Entity\CacesExam::class)->count(['session'=>$session]));
        $payload=$this->multiPayload($session);$payload['practices'][2]['template']='r489-cat1a-pratique-202607';
        $this->postMulti($payload);
        self::assertSame(0,$this->em->getRepository(\App\Entity\CacesExam::class)->count(['session'=>$session]));
    }

    public function testCategoryAssignmentsCanTargetDifferentLearnersAndRejectResubmission(): void
    {
        [$session,$registration]=$this->multiSession();
        $other=(new Inscription())->setSession($session)->setStagiaire($this->outsider)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($other);$this->em->flush();
        $payload=$this->multiPayload($session);$payload['practices'][0]['learners']=[$registration->getId()];$payload['practices'][1]['learners']=[$other->getId()];
        $this->postMulti($payload);
        $exams=$this->em->getRepository(\App\Entity\CacesExam::class)->findBy(['session'=>$session],['id'=>'ASC']);
        self::assertCount(3,$exams);
        self::assertSame(1,$this->em->getRepository(\App\Entity\CacesAttempt::class)->count(['exam'=>$exams[0]]));
        self::assertSame(1,$this->em->getRepository(\App\Entity\CacesAttempt::class)->count(['exam'=>$exams[1]]));
        self::assertSame(2,$this->em->getRepository(\App\Entity\CacesAttempt::class)->count(['exam'=>$exams[2]]));
        $this->postMulti($payload);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame(3,$this->em->getRepository(\App\Entity\CacesExam::class)->count(['session'=>$session]));
    }

    public function testSharedSuccessCertifiesEachCategoryAndFreezesSharedIdentity(): void
    {
        [$session]=$this->multiSession();$this->postMulti($this->multiPayload($session));
        $exams=$this->em->getRepository(\App\Entity\CacesExam::class)->findBy(['session'=>$session],['id'=>'ASC']);
        $attempts=array_map(fn($e)=>$this->em->getRepository(\App\Entity\CacesAttempt::class)->findOneBy(['exam'=>$e]),$exams);
        $source=$attempts[0];$sourceId=$source->getId();
        $signature=['name'=>'Signature QA','at'=>(new \DateTimeImmutable())->format(DATE_ATOM),'image'=>'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='];
        $source->setTheoryResult(['score'=>100,'passed'=>true,'themes'=>[],'reasons'=>[]])->setSubmittedAt(new \DateTimeImmutable('today'))->setProfile(['birthDate'=>'1990-01-01'])->setSignatures(['learner'=>$signature,'theoryTester'=>$signature]);
        foreach($attempts as $a) $a->setPracticeAt(new \DateTimeImmutable('today'))->setPracticeResult(['score'=>100,'passed'=>true,'themes'=>[],'reasons'=>[]])->setSignatures($a->getSignatures()+['practiceTester'=>$signature]);
        $this->em->flush();
        // Issue a dependent category first: its shared identity must then be frozen.
        $ids=[$attempts[1]->getId(),$attempts[2]->getId(),$sourceId];
        foreach($ids as $id){
            $this->client->loginUser($this->admin);$url=$this->url('app_caces_show',['id'=>$id]);
            $page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->first()->attr('value');
            $this->client->request('POST',$url,['_token'=>$token,'action'=>'certify']);
            $a=$this->em->find(\App\Entity\CacesAttempt::class,$id);
            self::assertNotEmpty($a->getCertificate());
            self::assertCount(3,$a->getSignatures());
            if($id===$ids[0]) {
                $this->client->loginUser($this->learner);$url=$this->url('app_caces_theory',['id'=>$sourceId]);
                $page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->first()->attr('value');
                $this->client->request('POST',$url,['_token'=>$token,'action'=>'profile','signature'=>$signature['image'],'birthDate'=>'1991-01-01','birthPlace'=>'Paris','address'=>'Adresse','phone'=>'0100000000','company'=>'Test','drivingExperience'=>'Oui','trainingExperience'=>'Oui']);
                $source=$this->em->find(\App\Entity\CacesAttempt::class,$sourceId);
                self::assertSame('1990-01-01',$source->getProfile()['birthDate']);
            }
        }
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url('app_caces_document',['id'=>$sourceId,'type'=>'synthese']));
        self::assertSame(3,substr_count($page->filter('.syn-overview')->text(),'Obtenue'));
    }

    public function testReassigningOneCategoryPreservesOtherTesters(): void
    {
        [$session]=$this->multiSession();
        $other=(new Formateur())->setUtilisateur($this->outsider)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($other);$this->em->flush();
        $this->postMulti($this->multiPayload($session));
        $exams=$this->em->getRepository(\App\Entity\CacesExam::class)->findBy(['session'=>$session],['id'=>'ASC']);
        $a=$this->em->getRepository(\App\Entity\CacesAttempt::class)->findOneBy(['exam'=>$exams[0]]);
        $page=$this->client->request('GET',$this->url('app_caces_show',['id'=>$a->getId()]));
        $token=$page->filter('#assignedTester')->ancestors()->filter('form')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$this->url('app_caces_practice_tester',['id'=>$session->getId()]),['_token'=>$token,'exam'=>$exams[0]->getId(),'practiceTester'=>$this->outsider->getId()]);
        foreach($exams as $i=>$exam){$current=$this->em->find(\App\Entity\CacesExam::class,$exam->getId());self::assertSame($i===0?$this->outsider->getId():$this->trainer->getId(),$current->getPracticeTester()->getId());}
    }
    private function multiSession(): array
    {
        $base=$this->caces->getExam()->getSession();
        $session=(new Session())->setEntite($this->tenant)->setCreateur($this->admin)->setCode('MULTI-CACES')->setFormation($base->getFormation())->setSite($base->getSite())->setFormateur($base->getFormateur());
        $registration=(new Inscription())->setSession($session)->setStagiaire($this->learner)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($session);$this->em->persist($registration);$this->em->flush();
        return [$session,$registration];
    }
    private function multiPayload(Session $session): array
    {
        return ['session'=>$session->getId(),'recommendation'=>'489','location'=>'Centre multi','theoryTester'=>$this->trainer->getId(),'pool'=>'official','practices'=>array_map(fn($cat)=>['category'=>$cat,'tester'=>$this->trainer->getId(),'template'=>'r489-cat'.strtolower($cat).'-pratique-202607'],['1A','1B','2B'])];
    }
    private function postMulti(array $payload): void
    {
        $this->client->loginUser($this->admin);
        $url=$this->url('app_caces_assign');$page=$this->client->request('GET',$url);
        $payload['_token']=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,$payload);
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
