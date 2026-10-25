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
        // Ownership scenarios require two trainers in this organism.
        $plan = (new \App\Entity\Billing\Plan())->setCode('TRAINERPROFILE')->setName('Isolation fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(2)->setMaxEntreprises(1)->setMaxProspects(50);
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

    public function testCvUploadIsPrivateAndRejectsStaleChanges(): void
    {
        $this->client->loginUser($this->user);$url='/fr/formateur/'.$this->entite->getId().'/cv';
        $page=$this->client->request('GET',$url);$token=$page->filter('input[name="_token"]')->first()->attr('value');
        $tmp=tempnam(sys_get_temp_dir(),'cv');file_put_contents($tmp,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
        $file=new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp,'mon-cv.pdf','application/pdf',null,true);
        $this->client->request('POST',$url,['_token'=>$token,'version'=>1,'action'=>'upload'],['cvFile'=>$file]);self::assertResponseRedirects();
        $cv=$this->em->getRepository(\App\Entity\TrainerCv::class)->findOneBy(['formateur'=>$this->trainer]);self::assertNotNull($cv->filename);self::assertNull($cv->published);
        try {
            $this->client->request('GET','/fr/formateur/'.$this->entite->getId().'/'.$this->trainer->getId().'/cv/fichier');self::assertResponseIsSuccessful();self::assertStringContainsString('private',$this->client->getResponse()->headers->get('Cache-Control'));
            $this->client->request('POST',$url,['_token'=>$token,'version'=>999,'action'=>'save','cv'=>['headline'=>'Wrong']]);self::assertResponseStatusCodeSame(409);
        } finally { @unlink(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/trainer-cv/'.$cv->filename); }
    }

    public function testCvDraftPublicationAndPrivateDocuments(): void
    {
        $this->client->loginUser($this->user);
        $url='/fr/formateur/'.$this->entite->getId().'/cv';
        $page=$this->client->request('GET',$url);self::assertResponseIsSuccessful();
        $token=$page->filter('input[name="_token"]')->first()->attr('value');
        $content=['headline'=>'Formateur en outils collaboratifs','summary'=>'Accompagner les équipes dans leur progression.','experience'=>"2020 — Aujourd’hui\nFormateur indépendant\nConception et animation de parcours en bureautique.",'education'=>'Certification professionnelle de formateur','skills'=>"Excel\nGoogle Sheets",'languages'=>'Français','methods'=>'Une pédagogie concrète, fondée sur la pratique.'];
        $this->client->request('POST',$url,['_token'=>$token,'version'=>1,'action'=>'publish','cv'=>$content]);self::assertResponseRedirects();
        $cv=$this->em->getRepository(\App\Entity\TrainerCv::class)->findOneBy(['formateur'=>$this->trainer]);self::assertSame($content['summary'],$cv->published['summary']);
        $content['summary']='Brouillon privé';
        $this->client->request('POST',$url,['_token'=>$token,'version'=>$cv->version,'action'=>'save','cv'=>$content]);self::assertResponseRedirects();
        $cv=$this->em->getRepository(\App\Entity\TrainerCv::class)->findOneBy(['formateur'=>$this->trainer]);self::assertNotSame($cv->content['summary'],$cv->published['summary']);
        $this->client->request('GET','/fr/administrateur/'.$this->entite->getId().'/formateur/'.$this->trainer->getId().'/cv');self::assertResponseIsSuccessful();self::assertSelectorTextContains('.resume-main','Brouillon privé');
        $this->user=$this->em->find(Utilisateur::class,$this->user->getId());
        $this->entite=$this->em->find(Entite::class,$this->entite->getId());
        $other=(new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user);$this->em->persist($other);$this->em->flush();
        $this->client->request('GET','/fr/administrateur/'.$other->getId().'/formateur/'.$this->trainer->getId().'/cv');self::assertResponseStatusCodeSame(404);
        $learnerUrl='/fr/stagiaire/'.$this->entite->getId().'/session/'.$this->session->getId().'/formateur/'.$this->trainer->getId().'/cv';
        $this->client->request('GET',$learnerUrl);self::assertResponseStatusCodeSame(403);
        $this->user=$this->em->find(Utilisateur::class,$this->user->getId());$this->entite=$this->em->find(Entite::class,$this->entite->getId());$this->session=$this->em->find(Session::class,$this->session->getId());
        $ins=(new Inscription())->setSession($this->session)->setStagiaire($this->user)->setEntite($this->entite)->setCreateur($this->user);$this->em->persist($ins);$this->em->flush();
        $this->client->request('GET',$learnerUrl);self::assertResponseIsSuccessful();self::assertSelectorTextNotContains('.resume-main','Brouillon privé');self::assertSelectorNotExists('.tp-file');
        if(getenv('CV_QA_HTML')) file_put_contents(getenv('CV_QA_HTML'),$this->client->getResponse()->getContent());
        $this->client->request('POST',$url,['_token'=>'bad','version'=>$cv->version,'action'=>'unpublish']);self::assertResponseStatusCodeSame(403);
        $this->client->request('POST',$url,['_token'=>$token,'version'=>$cv->version,'action'=>'unpublish']);self::assertResponseRedirects();
        $this->client->request('GET',$learnerUrl);self::assertResponseStatusCodeSame(404);
    }

    public function testCatalogueRatingsUseSubmittedSpecificScores(): void
    {
        $course = (new \App\Entity\Formation())->setTitre('Excel')->setSlug('excel')->setEntite($this->entite)->setCreateur($this->user);
        $this->em->persist($course);
        $this->session->setFormation($course);
        $template = (new \App\Entity\SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->user);
        $this->em->persist($template);
        foreach ([[8,10,6,true],[0,8,null,true],[10,0,10,false]] as [$global,$trainer,$site,$submitted]) {
            $assignment = (new \App\Entity\SatisfactionAssignment())->setSession($this->session)->setTemplate($template)->setEntite($this->entite)->setCreateur($this->user);
            $attempt = (new \App\Entity\SatisfactionAttempt())->setAssignment($assignment)->setEntite($this->entite)->setCreateur($this->user)->setNoteGlobale($global)->setNoteFormateur($trainer)->setNoteSite($site)->setSubmittedAt($submitted ? new \DateTimeImmutable() : null);
            $this->em->persist($assignment); $this->em->persist($attempt);
        }
        $this->em->flush();
        $ratings = new \App\Service\Satisfaction\CatalogueRatings($this->em);
        self::assertSame(['average'=>4.0,'votes'=>2], $ratings->forType($this->entite,'formation')[$course->getId()]);
        self::assertSame(['average'=>9.0,'votes'=>2], $ratings->forType($this->entite,'formateur')[$this->trainer->getId()]);
        self::assertSame(['average'=>6.0,'votes'=>1], $ratings->forType($this->entite,'site')[$this->session->getSite()->getId()]);
        // Repeated days must not multiply votes; a full override excludes the default trainer.
        $replacementUser = (new Utilisateur())->setEmail('replacement@test.test')->setPassword('unused')->setNom('Test')->setPrenom('Alex');
        $this->em->persist($replacementUser);
        $replacement = (new Formateur())->setUtilisateur($replacementUser)->setEntite($this->entite)->setCreateur($this->user);
        $this->em->persist($replacement);
        foreach ($this->session->getJours() as $day) $day->setFormateur($replacement);
        $this->session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2026-10-02 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-02 17:00'))->setFormateur($replacement));
        $this->em->flush();
        self::assertSame([$replacement->getId()=>['average'=>9.0,'votes'=>2]], $ratings->forType($this->entite,'formateur'));
        $other = (new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        foreach (['formation','formateur','site'] as $type) self::assertSame([], $ratings->forType($other,$type));
    }

    public function testProfileSaveAndPlanning(): void {
        $this->client->loginUser($this->user);
        $url='/fr/formateur/'.$this->entite->getId().'/profil';
        $crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();
        $token=$crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'action'=>'save','subjects'=>['Excel','Excel','Management'],'days'=>['1','2','3','4','5'],'declaredAvailable'=>'1']);self::assertResponseRedirects();
        $profile=$this->em->getRepository(\App\Entity\TrainerProfile::class)->findOneBy(['formateur'=>$this->trainer]);self::assertSame(['Excel','Management'],$profile->subjects);
        $this->client->request('GET','/fr/administrateur/'.$this->entite->getId().'/formateurs/disponibilites?subject=Excel&date=2026-10-01');self::assertResponseIsSuccessful();self::assertSelectorTextContains('.tp-matrix','Camille');self::assertSelectorExists('input[name="date"][value="2026-09-28"]');self::assertSelectorTextContains('.tp-matrix thead th:nth-child(2) .day-number','28');
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
    public function testInquirySendDeduplicationAndHistory():void {
        $mailer=$this->createMock(\Symfony\Component\Mailer\MailerInterface::class);$mailer->expects(self::once())->method('send');self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class,$mailer);
        $this->client->loginUser($this->user);$page=$this->client->request('GET','/fr/administrateur/'.$this->entite->getId().'/formateurs/disponibilites');self::assertResponseIsSuccessful();
        $token=$page->filter('[data-inquiry-token]')->attr('data-inquiry-token');$url='/fr/administrateur/'.$this->entite->getId().'/formateurs/'.$this->trainer->getId().'/demandes';
        $date=(new \DateTimeImmutable('tomorrow',new \DateTimeZone('Europe/Paris')))->format('Y-m-d');$body=['_token'=>$token,'key'=>str_repeat('a',32),'subject'=>'Excel','message'=>'Une formation de perfectionnement.','slots'=>[$date.'-AM',$date.'-PM']];
        $this->client->request('POST',$url,$body);self::assertResponseIsSuccessful();self::assertSame('sent',json_decode($this->client->getResponse()->getContent(),true)['status']);
        $this->client->request('POST',$url,$body);self::assertResponseIsSuccessful();self::assertSame(1,$this->em->getRepository(\App\Entity\TrainerInquiry::class)->count([]));
        $this->client->request('GET',$url);$items=json_decode($this->client->getResponse()->getContent(),true)['items'];self::assertCount(1,$items);self::assertCount(2,$items[0]['slots']);
        $this->client->request('POST',$items[0]['updateUrl'],['_token'=>$token,'status'=>'interested','note'=>'Accord reçu par téléphone.']);self::assertResponseIsSuccessful();
        $this->client->request('GET',$url);$item=json_decode($this->client->getResponse()->getContent(),true)['items'][0];self::assertSame('interested',$item['status']);self::assertSame('Camille Test',$item['history'][2]['author']);
        $body['key']=str_repeat('b',32);$body['slots']=['2020-01-01-AM'];$this->client->request('POST',$url,$body);self::assertResponseStatusCodeSame(422);
        $body['_token']='invalid';$this->client->request('POST',$url,$body);self::assertResponseStatusCodeSame(403);
    }
    public function testInquiryFailureIsRecordedAndOtherTenantIsDenied():void {
        $mailer=$this->createMock(\Symfony\Component\Mailer\MailerInterface::class);$mailer->method('send')->willThrowException(new \RuntimeException('Transport unavailable'));self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class,$mailer);
        $this->client->loginUser($this->user);$page=$this->client->request('GET','/fr/administrateur/'.$this->entite->getId().'/formateurs/disponibilites');$token=$page->filter('[data-inquiry-token]')->attr('data-inquiry-token');$url='/fr/administrateur/'.$this->entite->getId().'/formateurs/'.$this->trainer->getId().'/demandes';
        $date=(new \DateTimeImmutable('tomorrow'))->format('Y-m-d');$this->client->request('POST',$url,['_token'=>$token,'key'=>str_repeat('f',32),'subject'=>'Excel','slots'=>[$date.'-AM']]);self::assertResponseIsSuccessful();self::assertSame('failed',json_decode($this->client->getResponse()->getContent(),true)['status']);
        $other=(new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->user->getId()));$this->em->persist($other);$this->em->flush();
        $this->client->request('GET','/fr/administrateur/'.$other->getId().'/formateurs/'.$this->trainer->getId().'/demandes');self::assertResponseStatusCodeSame(404);
    }
    public function testEmailResponseConfirmationAndAudit():void {
        $mailer=$this->createMock(\Symfony\Component\Mailer\MailerInterface::class);$mailer->expects(self::once())->method('send')->with(self::callback(function($email){$attachment=$email->getAttachments()[0];self::assertSame('text/calendar',$attachment->getMediaType().'/'.$attachment->getMediaSubtype());self::assertStringContainsString('STATUS:TENTATIVE',$attachment->getBody());self::assertStringContainsString('20261201T070000Z',$attachment->getBody());return true;}));self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class,$mailer);
        $item=new \App\Entity\TrainerInquiry();$item->formateur=$this->trainer;$item->author=$this->user;$item->subject='Excel';$item->recipient=$this->user->getEmail();$item->requestKey=str_repeat('c',64);$item->status='sent';$item->slots=['2026-12-01-AM'];$this->em->persist($item);$this->em->flush();
        $service=self::getContainer()->get(\App\Service\TrainerProfile\InquiryResponses::class);$url='/fr/reponse-proposition/'.$item->id.'/'.$service->signature($item).'?choice=available';
        $page=$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSame('sent',$item->status);self::assertSame(0,$this->em->getRepository(\App\Entity\ProspectInteraction::class)->count([]));
        $token=$page->filter('input[name="_token"]')->attr('value');$body=['_token'=>$token,'choice'=>'available','note'=>'Avec plaisir.'];
        $this->client->request('POST',$url,$body);self::assertResponseIsSuccessful();$item=$this->em->find(\App\Entity\TrainerInquiry::class,$item->id);self::assertSame('available',$item->status);self::assertSame(2,$this->em->getRepository(\App\Entity\ProspectInteraction::class)->count([]));self::assertSame(2,$this->em->getRepository(\App\Entity\NotificationEvent::class)->count(['sourceType'=>'TrainerInquiry']));
        $this->client->request('POST',$url,$body);self::assertResponseIsSuccessful();$item=$this->em->find(\App\Entity\TrainerInquiry::class,$item->id);self::assertCount(2,$item->history);
        $body['choice']='unavailable';$this->client->request('POST',$url,$body);self::assertResponseIsSuccessful();$item=$this->em->find(\App\Entity\TrainerInquiry::class,$item->id);self::assertSame('unavailable',$item->status);self::assertCount(3,$item->history);
        $this->client->request('GET','/fr/reponse-proposition/'.$item->id.'/'.str_repeat('0',64));self::assertResponseStatusCodeSame(410);
        $item=$this->em->find(\App\Entity\TrainerInquiry::class,$item->id);$item->createdAt=new \DateTimeImmutable('-31 days');$this->em->flush();$this->client->request('GET',$url);self::assertResponseStatusCodeSame(410);
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
