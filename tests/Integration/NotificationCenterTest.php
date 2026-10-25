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

final class NotificationCenterTest extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
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
        // Ownership scenarios require two companies in this organism.
        $plan = (new \App\Entity\Billing\Plan())->setCode('NOTIFICATIONCENTER')->setName('Isolation fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(1)->setMaxEntreprises(2)->setMaxProspects(50);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
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

    public function testDiplomaAndCharterNotifications(): void
    {
        $diploma = new \App\Entity\TrainerDiploma();
        $diploma->formateur = $this->trainer;
        $diploma->title = 'Qualification Excel';
        $diploma->filename = 'diploma.pdf';
        $charter = new \App\Entity\TrainerCharter();
        $charter->entite = $this->entite;
        $version = new \App\Entity\TrainerCharterVersion();
        $version->charter = $charter;
        $version->title = 'Charte qualité';
        $signature = new \App\Entity\TrainerCharterSignature();
        $signature->version = $version;
        $signature->trainer = $this->trainer;
        foreach ([$diploma, $charter, $version, $signature] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $repo = $this->em->getRepository(\App\Entity\NotificationEvent::class);
        self::assertSame(1, $repo->count(['sourceType'=>'TrainerDiploma']));
        self::assertSame(0, $repo->count(['sourceType'=>'TrainerCharterSignature']));
        $signature->signedAt = new \DateTimeImmutable();
        $this->em->flush();
        $signature->evidence = ['checked'=>true];
        $diploma->title = 'Titre corrigé';
        $this->em->flush();
        self::assertSame(1, $repo->count(['sourceType'=>'TrainerDiploma']));
        self::assertSame(1, $repo->count(['sourceType'=>'TrainerCharterSignature']));
        $center = self::getContainer()->get(\App\Service\Notification\NotificationCenter::class);
        $events = $repo->findBy(['sourceType'=>['TrainerDiploma','TrainerCharterSignature']]);
        $keys = array_map(fn($event)=>'event:'.$event->id, $events);
        $admin = array_values(array_filter($center->items($this->entite,$this->user,'admin'),fn($item)=>in_array($item['key'],$keys,true)));
        self::assertCount(2,$admin);
        foreach ($admin as $item) {
            self::assertStringContainsString('Camille Test',$item['detail']);
            self::assertFalse($item['read']);
            self::assertMatchesRegularExpression('~/(formateurs/disponibilites|formateur/chartes/\d+/suivi)~',$item['url']);
        }
        self::assertSame([],array_values(array_filter($center->items($this->entite,$this->user,'formateur'),fn($item)=>in_array($item['key'],$keys,true))));
    }

    public function testJournalAndRoleScopes(): void
    {
        $center=self::getContainer()->get(\App\Service\Notification\NotificationCenter::class);
        $admin=$center->items($this->entite,$this->user,'admin');
        self::assertNotEmpty($admin);
        $trainer=$center->items($this->entite,$this->user,'formateur');
        self::assertSame([],array_values(array_filter($trainer,fn($i)=>$i['category']==='contracts')));
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE);$this->em->flush();
        $count=$this->em->getRepository(\App\Entity\NotificationEvent::class)->count([]);
        $this->em->flush();
        self::assertSame($count,$this->em->getRepository(\App\Entity\NotificationEvent::class)->count([]));
        $trainer=$center->items($this->entite,$this->user,'formateur');
        self::assertNotEmpty(array_filter($trainer,fn($i)=>$i['category']==='contracts' && $i['state']==='action'));
        foreach (['stagiaire','entreprise','of'] as $space) if (isset($center->spaces($this->entite)[$space])) self::assertSame([],$center->items($this->entite,$this->user,$space));
        $this->contract->setStatus(ContratFormateurStatus::RESILIE);$this->em->flush();
        self::assertSame([],array_values(array_filter($center->items($this->entite,$this->user,'formateur'),fn($i)=>$i['category']==='contracts' && $i['state']==='action')));
    }
    public function testPendingAttendanceAndReadStateAreIndependent(): void
    {
        $center=self::getContainer()->get(\App\Service\Notification\NotificationCenter::class);
        $rows=$center->items($this->entite,$this->user,'formateur');
        $pending=array_values(array_filter($rows,fn($i)=>$i['category']==='attendance' && $i['state']==='action'));
        self::assertNotEmpty($pending);
        $this->em->getConnection()->insert('notification_receipt',['entite_id'=>$this->entite->getId(),'utilisateur_id'=>$this->user->getId(),'item_key'=>$pending[0]['key'],'read_at'=>'2026-10-12 10:00:00','archived'=>0]);
        $rows=$center->items($this->entite,$this->user,'formateur');
        $read=array_values(array_filter($rows,fn($i)=>$i['key']===$pending[0]['key']))[0];
        self::assertTrue($read['read']);self::assertSame('action',$read['state']);
        foreach ([StatusSession::DONE,StatusSession::CANCELED] as $status) {
            $this->session->setStatus($status);$this->em->flush();
            self::assertSame([],array_values(array_filter($center->items($this->entite,$this->user,'formateur'),fn($i)=>$i['category']==='attendance' && $i['state']==='action')));
        }
    }
    public function testLearnerAndCompanyCannotSeeOtherPeoplesDocuments(): void
    {
        $company=(new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Company A');
        $otherCompany=(new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Company B');
        $this->user->setEntreprise($company);
        $other=(new Utilisateur())->setEmail('other@notification.test')->setPassword('unused')->setPrenom('Other')->setNom('Learner');
        foreach([$company,$otherCompany,$other] as $e) $this->em->persist($e);
        $this->em->flush();
        foreach([[$this->user,$company],[$other,$otherCompany]] as [$person,$enterprise]) {
            $i=(new Inscription())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setStagiaire($person)->setEntreprise($enterprise);
            $this->session->addInscription($i);$this->em->persist($i);
            $event=new \App\Entity\NotificationEvent();$event->entite=$this->entite;$event->session=$this->session;$event->subject=$person;$event->title=$person===$other?'Private B':'Own A';$event->sourceType='PieceDossier';
            $this->em->persist($event);
        }
        $this->em->flush();
        $center=self::getContainer()->get(\App\Service\Notification\NotificationCenter::class);
        foreach(['stagiaire','entreprise'] as $space) {
            $items=$center->items($this->entite,$this->user,$space);
            self::assertContains('Own A',array_column($items,'title'));
            self::assertNotContains('Private B',array_column($items,'title'));
            self::assertSame([],array_values(array_filter($items,fn($i)=>str_contains($i['detail'],'Other Learner'))));
        }
        $otherTenant=(new Entite())->setNom('Other tenant')->setPublic(false)->setCreateur($this->user);$this->em->persist($otherTenant);
        $event=new \App\Entity\NotificationEvent();$event->entite=$otherTenant;$event->title='Other tenant private';$this->em->persist($event);$this->em->flush();
        self::assertNotContains('Other tenant private',array_column($center->items($this->entite,$this->user,'admin'),'title'));
    }

    public function testTrainerCanDeclareAndUndoAbsence(): void
    {
        $learner = (new Utilisateur())->setEmail('absent@example.test')->setPassword('unused')->setPrenom('Absent')->setNom('Test');
        $ins = (new Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->user)->setStagiaire($learner);
        $this->em->persist($learner); $this->em->persist($ins); $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET','/fr/formateur/'.$this->entite->getId().'/dashboard');
        preg_match('/absenceChoice,_token:("[^"\n]+")/', $this->client->getResponse()->getContent(), $match);
        $token = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $url = '/fr/formateur/'.$this->entite->getId().'/emargement/absence';
        $data = ['_token'=>$token,'inscription'=>$ins->getId(),'date'=>'2026-10-01','period'=>'DAY','status'=>'absent'];
        $this->client->request('POST',$url,$data);
        self::assertResponseIsSuccessful();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $ins = $this->em->find(Inscription::class, $data['inscription']);
        self::assertSame('absent',$ins->getPresencesManuelles()['2026-10-01:AM']['status']);
        self::assertSame('absent',$ins->getPresencesManuelles()['2026-10-01:PM']['status']);
        $pdfData = self::getContainer()->get(\App\Service\Attendance\AttendancePdfData::class)->build($ins->getSession(),new \Symfony\Component\HttpFoundation\Request());
        $rows = $pdfData['linesByDate']['2026-10-01'];
        self::assertTrue(array_values(array_filter($rows,fn($r)=>$r['id']==='user:'.$learner->getId()))[0]['am']['absent']);
        $data['period']='AM'; $data['status']='unknown';
        $this->client->request('POST',$url,$data);
        self::assertResponseIsSuccessful();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $ins = $this->em->find(Inscription::class, $data['inscription']);
        self::assertSame('unknown',$ins->getPresencesManuelles()['2026-10-01:AM']['status']);
        self::assertCount(1,$ins->getPresencesManuelles()['2026-10-01:AM']['history']);
        $data['_token']='invalid'; $this->client->request('POST',$url,$data);
        self::assertResponseStatusCodeSame(403);
    }

    public function testActivityAuthorIsSeparateFromSubject(): void
    {
        $event = new \App\Entity\NotificationEvent();
        $event->entite = $this->entite;
        $event->session = $this->session;
        $event->subject = $this->user;
        $event->actor = 'Alex Administration';
        $event->title = 'Dossier modifié';
        $this->em->persist($event); $this->em->flush();
        $items = self::getContainer()->get(\App\Service\Notification\NotificationCenter::class)->items($this->entite, $this->user, 'admin');
        $item = array_values(array_filter($items, fn($item) => $item['key'] === 'event:'.$event->id))[0];
        self::assertSame('Alex Administration', $item['actor']);
        self::assertStringContainsString('Camille Test', $item['detail']);
        self::assertStringNotContainsString('Alex Administration', $item['detail']);
    }

    public function testCenterAndFeedRender(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET','/fr/notifications/'.$this->entite->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-notification-root]');
        self::assertSelectorExists('[data-notification-center]');
        $csrf=$this->client->getCrawler()->filter('[data-notification-root]')->attr('data-csrf');
        $this->client->request('GET','/fr/notifications/'.$this->entite->getId().'/feed?space=admin');
        self::assertResponseIsSuccessful();
        $data=json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('counts',$data);
        self::assertNotEmpty($data['items']);
        $this->client->request('POST','/fr/notifications/'.$this->entite->getId().'/state',[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['action'=>'read_all','space'=>'admin']));
        self::assertResponseStatusCodeSame(403);
        foreach(['read','archive','restore','unread'] as $action) {
            $this->client->request('POST','/fr/notifications/'.$this->entite->getId().'/state',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$csrf],json_encode(['action'=>$action,'space'=>'admin','key'=>$data['items'][0]['key']]));
            self::assertResponseIsSuccessful();
        }
        $receipt=$this->em->getConnection()->fetchAssociative('SELECT * FROM notification_receipt');
        self::assertNull($receipt['read_at']);self::assertSame(0,(int)$receipt['archived']);
        $this->client->request('POST','/fr/notifications/'.$this->entite->getId().'/state',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$csrf],json_encode(['action'=>'read','space'=>'admin','key'=>'inaccessible:event']));
        self::assertResponseStatusCodeSame(404);
    }

}
