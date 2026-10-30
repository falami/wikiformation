<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite,Formateur,TrainerCharter,TrainerCharterVersion,TrainerCharterSignature};
use App\Service\TrainerCharter\CharterManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class TrainerComplianceTest extends KernelTestCase
{
    private array $database;private $em;private $client;private int $entiteId;private int $adminId;private int $trainerId;private int $userId;
    protected function setUp(): void {
        $this->database=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];$_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        self::bootKernel();$this->em=self::getContainer()->get('doctrine')->getManager();(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $admin=(new Utilisateur())->setEmail('admin@example.test')->setPassword('unused')->setPrenom('Alice')->setNom('Admin')->setRoles(['ROLE_USER']);
        $user=(new Utilisateur())->setEmail('trainer@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin')->setRoles(['ROLE_USER']);
        $e=(new Entite())->setNom('Organisme Démonstration')->setPublic(false)->setCreateur($admin);
        foreach([$admin,$user,$e] as $x)$this->em->persist($x);$this->em->flush();
        foreach([[$admin,['TENANT_ADMIN']],[$user,['TENANT_FORMATEUR']]] as [$u,$roles]){$u->setEntite($e);$this->em->persist((new UtilisateurEntite())->setUtilisateur($u)->setEntite($e)->setCreateur($admin)->setRoles($roles));}
        $plan = (new \App\Entity\Billing\Plan())->setCode('TEST')->setName('Test')->setMaxFormateurs(10);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($e)->setPlan($plan)->setStatus('active'));
        $t=(new Formateur())->setUtilisateur($user)->setEntite($e)->setCreateur($admin);$user->setFormateur($t);$this->em->persist($t);$this->em->flush();
        $this->entiteId=$e->getId();$this->adminId=$admin->getId();$this->trainerId=$t->getId();$this->userId=$user->getId();
        $this->client=self::getContainer()->get('test.client');$this->client->disableReboot();$this->client->catchExceptions(false);$this->login($this->adminId);
    }
    protected function tearDown(): void {parent::tearDown();foreach(['ENV','SERVER'] as $i=>$n){if($this->database[$i]===null)unset($GLOBALS['_'.$n]['DATABASE_URL']);else $GLOBALS['_'.$n]['DATABASE_URL']=$this->database[$i];}}
    private function login(int $id): void {$this->client->loginUser($this->em->find(Utilisateur::class,$id));}
    private function base(): string {return '/fr/administrateur/'.$this->entiteId.'/formateur/chartes';}
    private function published(): TrainerCharterVersion {
        $c=new TrainerCharter();$c->entite=$this->em->find(Entite::class,$this->entiteId);$v=new TrainerCharterVersion();$v->charter=$c;$v->title='Charte qualité des formateurs';$v->chapters=CharterManager::starter();$v->effectiveAt=new \DateTimeImmutable('2026-10-15');$v->publishedAt=new \DateTimeImmutable();$v->issuer=['name'=>'Organisme Démonstration'];$v->digest=$v->hash();$this->em->persist($c);$this->em->persist($v);$this->em->flush();return $v;
    }
    private function requestSignature(TrainerCharterVersion $v): int {
        $page=$this->client->request('GET',$this->base().'/'.$v->id.'/suivi');self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $token=$page->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$this->base().'/'.$v->id.'/suivi',['_token'=>$token,'trainers'=>[(string)$this->trainerId]]);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        return $this->em->getRepository(TrainerCharterSignature::class)->findOneBy([])->id;
    }
    public function testPendingDocumentsCooldownAndTenantIsolation(): void {
        $service=self::getContainer()->get(\App\Service\TrainerReminder\TrainerCompliance::class);
        $entite=$this->em->find(Entite::class,$this->entiteId);
        $key='diploma:'.$this->trainerId;
        $rows=$service->pending($entite);
        $this->client->request('GET','/fr/administrateur/'.$this->entiteId.'/dashboard');
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Les documents de vos formateurs',$this->client->getResponse()->getContent());
        self::assertCount(1,$rows);self::assertTrue((bool)$rows[$key]['available']);
        $version=$this->published();$id=$this->requestSignature($version);
        self::assertArrayHasKey('charter:'.$id,$service->pending($entite));
        $signature=$this->em->find(TrainerCharterSignature::class,$id);$signature->signedAt=new \DateTimeImmutable();$this->em->flush();
        self::assertArrayNotHasKey('charter:'.$id,$service->pending($entite));
        $db=$this->em->getConnection();
        $db->insert('trainer_reminder_delivery',['entite_id'=>$this->entiteId,'scope_key'=>'manual:'.$key,'sequence_number'=>1,'kind'=>'manual','recipient'=>'trainer@example.test','subject'=>'Test','status'=>'sent','created_at'=>gmdate('Y-m-d H:i:s'),'sent_at'=>gmdate('Y-m-d H:i:s')]);
        self::assertFalse((bool)$service->pending($entite)[$key]['available']);
        self::assertSame('skipped',$service->send($entite,$key));
        $page=$this->client->request('GET','/fr/administrateur/'.$this->entiteId.'/dashboard');
        self::assertCount(1,$page->filter('[data-reminder-all="tblTrainerCompliance"]'));
        $token=$page->filter('#trainer-compliance input[name="_token"]')->first()->attr('value');
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key,'_token'=>$token],[],['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame(['lastReminderAt'=>null,'sent'=>0,'skipped'=>1,'failed'=>0],json_decode($this->client->getResponse()->getContent(),true));

        $db->update('trainer_reminder_delivery',['created_at'=>gmdate('Y-m-d H:i:s',time()-90000)],['scope_key'=>'manual:'.$key]);
        self::assertTrue((bool)$service->pending($entite)[$key]['available']);
        $db->update('trainer_reminder_delivery',['status'=>'unknown'],['scope_key'=>'manual:'.$key]);
        self::assertFalse((bool)$service->pending($entite)[$key]['available']);
        $other=(new Entite())->setNom('Autre')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->adminId));$this->em->persist($other);$this->em->flush();
        self::assertSame([],$service->pending($other));self::assertSame('skipped',$service->send($other,$key));
        $this->client->catchExceptions(true);
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key,'_token'=>'invalid']);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->login($this->userId);
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key]);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $html=self::getContainer()->get('twig')->render('emails/trainer_compliance.html.twig',$rows[$key]+['entite'=>$entite,'subject'=>'Test']);
        self::assertStringContainsString('Déposer mes justificatifs',$html);
    }
    private function questionnaire(): \App\Entity\FormateurSatisfactionAssignment
    {
        $e = $this->em->find(Entite::class, $this->entiteId);
        $admin = $this->em->find(Utilisateur::class, $this->adminId);
        $trainer = $this->em->find(Formateur::class, $this->trainerId);
        $suffix = $this->em->getRepository(\App\Entity\Session::class)->count([]);
        $site = (new \App\Entity\Site())->setNom('Salle')->setSlug('questionnaire-'.$suffix)->setEntite($e)->setCreateur($admin);
        $session = (new \App\Entity\Session())->setEntite($e)->setCreateur($admin)->setCode('SES-OLD-'.$suffix)
            ->setSite($site)->setFormateur($trainer)->setStatus(\App\Enum\StatusSession::IN_PROGRESS);
        $session->addJour((new \App\Entity\SessionJour())->setCreateur($admin)->setEntite($e)->setDateDebut(new \DateTimeImmutable('-30 days 09:00'))
            ->setDateFin(new \DateTimeImmutable('-30 days 17:00')));
        $template = (new \App\Entity\FormateurSatisfactionTemplate())->setEntite($e)->setCreateur($admin)->setTitre('Évaluation');
        $a = (new \App\Entity\FormateurSatisfactionAssignment())->setEntite($e)->setCreateur($admin)
            ->setSession($session)->setFormateur($trainer->getUtilisateur())->setTemplate($template);
        foreach ([$site, $session, $template, $a] as $entity) $this->em->persist($entity);
        $this->em->flush();
        return $a;
    }

    public function testExpiredQuestionnaireAppearsAndManualReminderReopensWithoutChangingAnswers(): void
    {
        $a = $this->questionnaire();
        $e = $this->em->find(Entite::class, $this->entiteId);
        $e->setEmail('school@example.test');
        $access = new \App\Service\Satisfaction\SatisfactionAccess();
        self::assertFalse($access->canFillTrainer($a));
        $mail = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $mail->expects(self::once())->method('send')->with(self::callback(fn($email) =>
            str_contains($email->getHtmlBody(), 'Compléter mon questionnaire')
            && str_contains($email->getTextBody(), '/satisfaction/session/'.$a->getSession()->getId().'/fill')), null);
        $twig = self::getContainer()->get('twig');
        $router = self::getContainer()->get('router');
        $service = new \App\Service\TrainerReminder\TrainerCompliance($this->em,
            self::getContainer()->get(CharterManager::class), $router,
            new \App\Service\Email\MailerManager($mail, $twig, $router), $twig, new \Psr\Log\NullLogger());
        $key = 'trainer-questionnaire:'.$a->getId().':'.$this->userId;
        self::assertTrue($service->pending($e)[$key]['reopens']);
        self::assertSame('sent', $service->send($e, $key));
        self::assertTrue($access->canFillTrainer($a));
        self::assertNull($a->getAttempt());
        self::assertSame('skipped', $service->send($e, $key));
        $attempt = (new \App\Entity\FormateurSatisfactionAttempt())->setAssignment($a)->setEntite($e)->setCreateur($a->getFormateur())->setSubmittedAt(new \DateTimeImmutable());
        $a->setAttempt($attempt);
        $this->em->persist($attempt); $this->em->flush();
        self::assertFalse($access->canFillTrainer($a));
        self::assertArrayNotHasKey($key, $service->pending($e));
    }

    public function testManualReopeningRequiresCsrfAndDoesNotCreateAnAttempt(): void
    {
        $a = $this->questionnaire();
        $url = '/fr/administrateur/'.$this->entiteId.'/formateur-satisfaction/assignments/'.$a->getId().'/reouvrir';
        $this->client->catchExceptions(true);
        $this->client->request('POST', $url, ['_token'=>'wrong']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($a->getReopenedUntil());
        $this->client->request('POST', '/fr/administrateur/'.$this->entiteId.'/formateur-satisfaction/assignments/ajax', ['draw'=>1, 'length'=>10]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $fragment = new \Symfony\Component\DomCrawler\Crawler($rows[0]['actions']);
        $token = $fragment->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $url, ['_token'=>$token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $a = $this->em->find(\App\Entity\FormateurSatisfactionAssignment::class, $a->getId());
        self::assertTrue((new \App\Service\Satisfaction\SatisfactionAccess())->canFillTrainer($a));
        self::assertNull($a->getAttempt());
        $this->login($this->userId);
        $this->client->request('GET', '/fr/formateur/'.$this->entiteId.'/satisfaction/session/'.$a->getSession()->getId().'/fill');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function replacementTrainer(): Formateur
    {
        $e = $this->em->find(Entite::class, $this->entiteId);
        $admin = $this->em->find(Utilisateur::class, $this->adminId);
        $user = (new Utilisateur())->setEmail('replacement@example.test')->setPassword('unused')->setPrenom('Replacement')->setNom('Trainer');
        $trainer = (new Formateur())->setUtilisateur($user)->setEntite($e)->setCreateur($admin);
        $user->setFormateur($trainer);
        $this->em->persist($user); $this->em->persist($trainer); $this->em->flush();
        return $trainer;
    }

    private function synchronizeTrainer(\App\Entity\Session $session): void
    {
        (new \App\Service\FormateurSatisfaction\FormateurSatisfactionAssigner($this->em))->synchronizeReplacement(
            $session, $this->em->find(Utilisateur::class, $this->adminId),
            $this->em->find(Entite::class, $this->entiteId), [$this->userId]);
        $this->em->flush();
    }

    public function testReplacementTransfersUntouchedQuestionnaireAndReopensAccess(): void
    {
        $a = $this->questionnaire(); $id = $a->getId();
        $a->setIsRequired(false);
        $replacement = $this->replacementTrainer(); $userId = $replacement->getUtilisateur()->getId();
        $a->getSession()->setFormateur($replacement);
        $this->synchronizeTrainer($a->getSession());
        $this->em->clear();
        $a = $this->em->find(\App\Entity\FormateurSatisfactionAssignment::class, $id);
        self::assertSame($userId, $a->getFormateur()->getId());
        self::assertFalse($a->isRequired());
        self::assertNull($a->getAttempt());
        self::assertTrue((new \App\Service\Satisfaction\SatisfactionAccess())->canFillTrainer($a));
        $this->synchronizeTrainer($a->getSession());
        self::assertCount(1, $this->em->getRepository(\App\Entity\FormateurSatisfactionAssignment::class)->findAll());
    }

    public function testReplacementPreservesStartedAndSubmittedAttempts(): void
    {
        foreach ([false, true] as $submitted) {
            $a = $this->questionnaire();
            $attempt = (new \App\Entity\FormateurSatisfactionAttempt())->setAssignment($a)->setEntite($a->getEntite())->setCreateur($a->getFormateur());
            if ($submitted) $attempt->setSubmittedAt(new \DateTimeImmutable());
            $a->setAttempt($attempt); $this->em->persist($attempt); $this->em->flush();
            $replacement = $this->em->getRepository(Formateur::class)->findOneBy(['utilisateur'=>$this->adminId]);
            if (!$replacement) {
                $replacement = (new Formateur())->setUtilisateur($this->em->find(Utilisateur::class, $this->adminId))->setEntite($a->getEntite())->setCreateur($a->getCreateur());
                $this->em->persist($replacement); $this->em->flush();
            }
            $a->getSession()->setFormateur($replacement);
            $this->synchronizeTrainer($a->getSession());
            self::assertSame($this->userId, $a->getFormateur()->getId());
            self::assertSame($attempt, $a->getAttempt());
            $rows = $this->em->getRepository(\App\Entity\FormateurSatisfactionAssignment::class)->findBy(['session'=>$a->getSession()]);
            self::assertCount(2, $rows);
            $new = array_values(array_filter($rows, fn($row) => $row !== $a))[0];
            self::assertSame($this->adminId, $new->getFormateur()->getId());
            self::assertNull($new->getAttempt());
        }
    }

    public function testExistingReplacementAssignmentDoesNotCreateDuplicate(): void
    {
        $a = $this->questionnaire(); $obsoleteId = $a->getId();
        $replacement = $this->replacementTrainer();
        $existing = (new \App\Entity\FormateurSatisfactionAssignment())->setEntite($a->getEntite())->setCreateur($a->getCreateur())
            ->setSession($a->getSession())->setTemplate($a->getTemplate())->setFormateur($replacement->getUtilisateur());
        $this->em->persist($existing); $this->em->flush(); $existingId = $existing->getId();
        $a->getSession()->setFormateur($replacement);
        $this->synchronizeTrainer($a->getSession()); $this->em->clear();
        self::assertNull($this->em->find(\App\Entity\FormateurSatisfactionAssignment::class, $obsoleteId));
        self::assertNotNull($this->em->find(\App\Entity\FormateurSatisfactionAssignment::class, $existingId));
        self::assertCount(1, $this->em->getRepository(\App\Entity\FormateurSatisfactionAssignment::class)->findAll());
    }

    public function testPrincipalChangeKeepsQuestionnaireWhenOriginalTrainerStillTeaches(): void
    {
        $a = $this->questionnaire();
        foreach ($a->getSession()->getJours() as $day) $day->setFormateur($this->em->find(Formateur::class, $this->trainerId));
        $a->getSession()->setFormateur($this->replacementTrainer());
        $this->synchronizeTrainer($a->getSession());
        self::assertSame($this->userId, $a->getFormateur()->getId());
        self::assertNull($a->getReopenedUntil());
        self::assertCount(1, $this->em->getRepository(\App\Entity\FormateurSatisfactionAssignment::class)->findAll());
    }

}
