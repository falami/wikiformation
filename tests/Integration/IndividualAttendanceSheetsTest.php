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

final class IndividualAttendanceSheetsTest extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
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


    public function testIndividualSheetsKeepOnlyOneLearnerAndTheAssignedTrainer(): void
    {
        $people=[];
        foreach (['Alice','Bruno'] as $name) {
            $u=(new Utilisateur())->setEmail(strtolower($name).'@attendance.test')->setPassword('unused')->setPrenom($name)->setNom('Exemple');
            $i=(new Inscription())->setEntite($this->entite)->setCreateur($this->user)->setSession($this->session)->setStagiaire($u);
            $this->session->addInscription($i);$this->em->persist($u);$this->em->persist($i);$people[]=$u;
        }
        $this->em->flush();
        $days=[['ymd'=>'2026-10-01','label'=>'01/10/2026','horaires'=>['09:00 à 17:00']]];
        foreach ([2,3] as $day) {
            $date=sprintf('2026-10-%02d',$day);
            $this->session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable($date.' 09:00'))->setDateFin(new \DateTimeImmutable($date.' 17:00')));
            $days[]=['ymd'=>$date,'label'=>sprintf('%02d/10/2026',$day),'horaires'=>['09:00 à 17:00']];
        }
        $sheets=(new \App\Service\Attendance\IndividualAttendanceSheets())->build($this->session,$days,[]);
        self::assertCount(2,$sheets);
        self::assertCount(3,$sheets[0]['days']);
        self::assertCount(3,$sheets[0]['lines']);
        foreach($sheets as $index=>$sheet) {
            $rows=$sheet['lines']['2026-10-01'];
            self::assertCount(2,$rows);
            self::assertSame($people[$index]->getPrenom().' Exemple',$rows[0]['name']);
            self::assertTrue($rows[1]['isTrainer']);
            self::assertSame('Camille Test',$rows[1]['name']);
        }
        $meta=['entite'=>$this->entite,'titre'=>'Formation de démonstration','code'=>'SES-TEST','adresse'=>'Salle de formation','dateDebut'=>'01/10/2026','dateFin'=>'03/10/2026','jours'=>3,'joursList'=>$days,'formateurName'=>'Camille Test'];
        $html=self::getContainer()->get('twig')->render('pdf/emargement_sheet.html.twig',['meta'=>$meta,'linesByDate'=>[],'individualSheets'=>$sheets]);
        $pdf=new \Dompdf\Dompdf();$pdf->setPaper('A4','landscape');$pdf->loadHtml($html);$pdf->render();
        self::assertSame(2,$pdf->getCanvas()->get_page_count());
        if (getenv('ATTENDANCE_PDF_REVIEW')) file_put_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/individual-attendance-review.pdf',$pdf->output());
        $signed=['signed'=>true,'img'=>'data:image/png;base64,test','at'=>'01/10/2026 09:00'];
        $rows=$sheets[0]['lines'];$rows['2026-10-01'][0]['am']=$signed;$rows['2026-10-01'][1]['pm']=$signed;
        $withSignatures=(new \App\Service\Attendance\IndividualAttendanceSheets())->build($this->session,$days,$rows);
        self::assertSame($signed,$withSignatures[0]['lines']['2026-10-01'][0]['am']);
        self::assertSame($signed,$withSignatures[1]['lines']['2026-10-01'][1]['pm']);
        self::assertFalse($withSignatures[1]['lines']['2026-10-01'][0]['am']['signed']);
        $collective=self::getContainer()->get('twig')->render('pdf/emargement_sheet.html.twig',['meta'=>$meta,'linesByDate'=>[]]);
        self::assertStringContainsString('Liste des participants par jour',$collective);
    }
}
