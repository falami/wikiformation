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

final class ConventionDossierTest extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
{
    private $client;
    private array $database;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $user;
    private Formateur $trainer;
    private Session $session;
    private ContratFormateur $contract;
    private EntitePreferences $preferences;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client=self::createClient();
        $this->client->disableReboot();
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
        $this->em->getConnection()->executeStatement('CREATE TABLE notification_receipt (entite_id INTEGER, utilisateur_id INTEGER, item_key VARCHAR(190), read_at DATETIME, archived INTEGER DEFAULT 0, PRIMARY KEY(entite_id, utilisateur_id, item_key))');
        self::getContainer()->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->user,'main',$this->user->getRoles()));
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$name) {
            if ($this->database[$i]===null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL']=$this->database[$i];
        }
    }


    public function testDossierIsScopedAndDownloadableWithoutSendingEmail(): void
    {
        $sent=[];
        $mailer=$this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function($message) use (&$sent) { $sent[]=$message; });
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class,$mailer);
        $companies=[]; $learners=[]; $inscriptions=[];
        foreach (['Alice','Bruno'] as $name) {
            $company=(new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Entreprise '.$name)->setEmail(strtolower($name).'@company.test');
            $u=(new Utilisateur())->setEmail(strtolower($name).'@dossier.test')->setPassword('unused')->setPrenom($name)->setNom('Exemple');
            $i=(new Inscription())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setStagiaire($u)->setEntreprise($company);
            $this->session->addInscription($i);
            foreach ([$company,$u,$i] as $e) $this->em->persist($e);
            $companies[]=$company;$learners[]=$u;$inscriptions[]=$i;
        }
        $c=(new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setEntreprise($companies[0])->setNumero('CC-DOSSIER')->setIntituleFormation('Mission test');
        $c->addInscription($inscriptions[0]);$this->em->persist($c);
        $template=(new \App\Entity\SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Appréciations');
        $chapter=(new \App\Entity\SatisfactionChapter())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Bilan');
        $question=(new \App\Entity\SatisfactionQuestion())->setEntite($this->entite)->setCreateur($this->user)->setLibelle('Votre avis')->setType(\App\Enum\SatisfactionQuestionType::TEXTAREA);
        $template->addChapter($chapter);$chapter->addQuestion($question);
        foreach ([$template,$chapter,$question] as $e) $this->em->persist($e);
        $this->em->flush();
        foreach ($learners as $u) {
            $a=(new \App\Entity\SatisfactionAssignment())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setStagiaire($u)->setTemplate($template);
            $attempt=(new \App\Entity\SatisfactionAttempt())->setEntite($this->entite)->setCreateur($this->user)->setAssignment($a)->setSubmittedAt(new \DateTimeImmutable())->setAnswers([$question->getId()=>'Très utile pour '.$u->getPrenom()]);
            $a->setAttempt($attempt);$this->em->persist($a);$this->em->persist($attempt);
        }
        $this->em->flush();
        self::getContainer()->get('twig')->addGlobal('entite',$this->entite);
        $service=self::getContainer()->get(\App\Service\Convention\ConventionDossier::class);
        $inventory=$service->inventory($c);
        self::assertSame(['user:'.$learners[0]->getId()=>'Alice Exemple'],$inventory['people']);
        self::assertCount(1,$inventory['assignments']);
        $files=$service->documents($c,\Symfony\Component\HttpFoundation\Request::create('/'));
        self::assertCount(4,$files);
        foreach ($files as $filename=>$bytes) { self::assertStringStartsWith('%PDF',$bytes);self::assertStringNotContainsString('Bruno',$filename); }
        $archive=$service->archive($files);self::assertStringStartsWith('PK',$archive);
        if (getenv('ATTENDANCE_PDF_REVIEW')) foreach ($files as $filename=>$bytes) file_put_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/review-'.$filename,$bytes);
        $this->client->loginUser($this->user);
        $url='/fr/administrateur/'.$this->entite->getId().'/conventions/'.$c->getId().'/dossier';
        $this->client->request('GET',$url);self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1','Mission test');
        $token=$this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $this->session->setStatus(StatusSession::CANCELED);$this->em->flush();
        $this->client->request('POST',$url,['_token'=>$token,'reviewed'=>'1']);self::assertResponseRedirects();self::assertCount(0,$sent);
        $session=$this->em->find(Session::class,$this->session->getId());$session->setStatus(StatusSession::DONE);$this->em->flush();
        $this->client->request('POST',$url,['_token'=>$token,'reviewed'=>'1']);self::assertResponseRedirects();
        self::assertCount(1,$sent);self::assertSame('alice@company.test',$sent[0]->getTo()[0]->getAddress());
        self::assertCount(1,$sent[0]->getAttachments());self::assertStringStartsWith('PK',$sent[0]->getAttachments()[0]->getBody());
        self::assertSame(1,$this->em->getRepository(\App\Entity\EmailLog::class)->count(['status'=>'SENT']));
        $this->client->request('POST',$url,['_token'=>'invalid','reviewed'=>'1']);self::assertResponseStatusCodeSame(403);
        // Even a tenant administrator in enterprise view cannot retrieve another company's pack.
        $actor=$this->em->find(Utilisateur::class,$this->user->getId());$actor->setEntreprise($this->em->find(\App\Entity\Entreprise::class,$companies[1]->getId()));$this->em->flush();$this->client->loginUser($actor);
        $this->client->request('GET','/fr/entreprise/'.$this->entite->getId().'/documents/convention/'.$c->getId().'/dossier');self::assertResponseStatusCodeSame(404);
        $actor=$this->em->find(Utilisateur::class,$this->user->getId());$actor->setEntreprise($this->em->find(\App\Entity\Entreprise::class,$companies[0]->getId()));$this->em->flush();$this->client->loginUser($actor);
        $this->client->request('GET','/fr/entreprise/'.$this->entite->getId().'/documents/convention/'.$c->getId().'/dossier');self::assertResponseIsSuccessful();
        $this->client->request('POST','/fr/entreprise/'.$this->entite->getId().'/documents/conventions/ajax');self::assertResponseIsSuccessful();
        self::assertStringContainsString('/dossier',json_decode($this->client->getResponse()->getContent(),true)['data'][0]['actions']);
        $this->client->request('POST','/fr/entreprise/'.$this->entite->getId().'/documents/devis/ajax');self::assertResponseIsSuccessful();
        $session=$this->em->find(Session::class,$this->session->getId());
        $convention=$this->em->find(\App\Entity\ConventionContrat::class,$c->getId());
        $session->setTypeFinancement(\App\Enum\TypeFinancement::OUI);
        self::assertCount(2,$service->documents($convention,\Symfony\Component\HttpFoundation\Request::create('/')));
        // Inconsistent roster fails closed rather than leaking another company's learner.
        $c->addInscription($inscriptions[1]);
        $this->expectException(\DomainException::class);$service->inventory($c);
    }
}
