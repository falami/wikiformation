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

final class TrainerProfileTest extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
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

    public function testProfileSaveAndPlanning(): void {
        $this->client->loginUser($this->user);
        $url='/fr/formateur/'.$this->entite->getId().'/profil';
        $crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();
        $token=$crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'save','subjects'=>['Excel','Excel','Management'],'days'=>['1','2','3','4','5'],'declaredAvailable'=>'1']);self::assertResponseRedirects();
        $profile=$this->em->getRepository(\App\Entity\TrainerProfile::class)->findOneBy(['formateur'=>$this->trainer]);self::assertSame(['Excel','Management'],$profile->subjects);
        $this->client->request('GET','/fr/administrateur/'.$this->entite->getId().'/formateurs/disponibilites?subject=Excel&date=2026-10-01');self::assertResponseIsSuccessful();self::assertSelectorTextContains('.tp-matrix','Camille');
        $this->client->request('POST',$url,['_token'=>'invalid','action'=>'save','subjects'=>['Wrong']]);self::assertResponseStatusCodeSame(403);
    }
    public function testDiplomaUploadAndTenantIsolation():void {
        $this->client->loginUser($this->user);$url='/fr/formateur/'.$this->entite->getId().'/profil';
        $crawler=$this->client->request('GET',$url);$token=$crawler->filter('input[name="_token"]')->first()->attr('value');
        $tmp=tempnam(sys_get_temp_dir(),'diploma');file_put_contents($tmp,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aHtQAAAAASUVORK5CYII='));
        $file=new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp,'diploma.png','image/png',null,true);
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'upload','title'=>'Certification test'],['diploma'=>$file]);self::assertResponseRedirects();
        $d=$this->em->getRepository(\App\Entity\TrainerDiploma::class)->findOneBy(['formateur'=>$this->trainer]);self::assertNotNull($d);
        try {
            $this->client->request('GET','/fr/formateur/'.$this->entite->getId().'/diplomes/'.$d->id);self::assertResponseIsSuccessful();
            $other=(new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->user->getId()));$this->em->persist($other);$this->em->flush();
            $this->client->request('GET','/fr/formateur/'.$other->getId().'/diplomes/'.$d->id);self::assertResponseStatusCodeSame(404);
            $this->client->request('POST',$url,['_token'=>$token,'action'=>'deleteDiploma','diplomaId'=>$d->id]);self::assertResponseRedirects();
        } finally { $path=self::getContainer()->getParameter('kernel.project_dir').'/var/storage/trainer-diplomas/'.$d->filename;if(is_file($path))unlink($path); }
    }
    public function testOccupiedAndUnknownDays():void {
        $service=new \App\Service\TrainerProfile\Availability($this->em);$p=new \App\Entity\TrainerProfile();$p->formateur=$this->trainer;
        $days=$service->days($this->trainer,$p,new \DateTimeImmutable('2026-10-01',new \DateTimeZone('Europe/Paris')),2);
        self::assertSame('busy',$days[0]['parts']['AM']['state']);self::assertSame('unknown',$days[1]['parts']['AM']['state']);
        $p->declaredAvailable=true;$p->unavailable=[['start'=>'2026-10-02','end'=>'2026-10-02','period'=>'PM']];
        $days=$service->days($this->trainer,$p,new \DateTimeImmutable('2026-10-02',new \DateTimeZone('Europe/Paris')),1);
        self::assertSame('free',$days[0]['parts']['AM']['state']);self::assertSame('busy',$days[0]['parts']['PM']['state']);
        $p->calendarSecret='connected';$p->syncError='unavailable';$days=$service->days($this->trainer,$p,new \DateTimeImmutable('2026-10-02'),1);self::assertSame('unknown',$days[0]['parts']['AM']['state']);
    }
}
