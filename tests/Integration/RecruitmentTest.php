<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Utilisateur,Entite,Formateur,TrainerApplication,RecruitmentPlacement,TrainerCv,UtilisateurEntite};
use App\Service\Recruitment\{Applications,Placement};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RecruitmentTest extends WebTestCase
{
    private $client;
    private $em;
    private array $env;
    private array $files=[];
    protected function setUp(): void {
        $this->env=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        $this->client=self::createClient();$this->client->disableReboot();
        self::getContainer()->get('limiter.recruitment')->create('127.0.0.1')->reset();
        $this->em=self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }
    protected function tearDown(): void {
        foreach($this->files as $f) if(is_file($f)) unlink($f);
        parent::tearDown();
        foreach(['_ENV','_SERVER'] as $i=>$k) {if($this->env[$i]===null)unset($GLOBALS[$k]['DATABASE_URL']);else $GLOBALS[$k]['DATABASE_URL']=$this->env[$i];}
    }
    private function fixtures(): array {
        $u=(new Utilisateur())->setEmail('super@recruit.test')->setPassword('unused')->setPrenom('Super')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $a=(new Entite())->setNom('Organisme A')->setCreateur($u)->setPublic(false);
        $b=(new Entite())->setNom('Organisme B')->setCreateur($u)->setPublic(false);
        foreach([$u,$a,$b] as $e)$this->em->persist($e);$this->em->flush();$u->setEntite($a);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($u)->setEntite($a)->setCreateur($u)->setRoles(['TENANT_ADMIN']));$this->em->flush();
        return [$u,$a,$b];
    }
    private function candidate(): TrainerApplication {
        $a=new TrainerApplication();$a->email='candidate@recruit.test';$a->firstName='Camille';$a->lastName='Expert';$a->phone='0600000000';
        $a->details=['company'=>'Expert','address'=>'1 rue Test','postalCode'=>'75001','city'=>'Paris','siret'=>'12345678901234','vat'=>false,'vatNumber'=>'','presentation'=>'Pédagogie'];
        $a->subjects=['Informatique'];$a->departments=['75'];$a->hourlyRate=6000;
        $dir=self::getContainer()->get(Applications::class)->directory();if(!is_dir($dir))mkdir($dir,0700,true);
        $a->cvFilename='test-'.bin2hex(random_bytes(8)).'.pdf';$a->cvOriginalName='CV.pdf';
        file_put_contents($dir.'/'.$a->cvFilename,"%PDF-1.4\n%%EOF");$this->files[]=$dir.'/'.$a->cvFilename;
        $this->em->persist($a);$this->em->flush();return $a;
    }
    public function testPublicFormAndCsrf(): void {
        $c=$this->client->request('GET','/fr/recrutement');self::assertResponseIsSuccessful();
        self::assertCount(1,$c->filter('input[name=prenom]'));self::assertCount(1,$c->filter('input[name=siret]'));
        self::assertCount(101,$c->filter('[data-department]'));
        self::assertCount(2775,$c->filter('#rec-subjects option'));
        $this->client->request('POST','/fr/recrutement',['_token'=>'invalid']);self::assertResponseRedirects('http://localhost/fr/login');
    }
    public function testTransferToTwoEntitiesIsIdempotentAndPreservesDocuments(): void {
        [$u,$a,$b]=$this->fixtures();$candidate=$this->candidate();
        $service=self::getContainer()->get(Placement::class);
        self::assertSame(2,$service->transfer($candidate,[$a,$b],$u));
        self::assertSame(2,$this->em->getRepository(Formateur::class)->count([]));
        self::assertSame(2,$this->em->getRepository(RecruitmentPlacement::class)->count([]));
        self::assertNotSame($candidate->account->getFormateur($a),$candidate->account->getFormateur($b));
        foreach($this->em->getRepository(TrainerCv::class)->findAll() as $cv) {
            $file=dirname(__DIR__,2).'/var/storage/trainer-cv/'.$cv->filename;$this->files[]=$file;
            self::assertFileExists($file);self::assertSame('CV.pdf',$cv->originalName);
        }
        self::assertSame(0,$service->transfer($candidate,[$a,$b],$u));
        $id=$candidate->account->getId();$aid=$a->getId();$bid=$b->getId();$this->em->clear();
        $user=$this->em->find(Utilisateur::class,$id);
        self::assertSame($aid,$user->getFormateur($this->em->find(Entite::class,$aid))->getEntite()->getId());
        self::assertSame($bid,$user->getFormateur($this->em->find(Entite::class,$bid))->getEntite()->getId());
    }
    public function testSuperAdminViewsFiltersAndTenantDenial(): void {
        [$u,$a]=$this->fixtures();$candidate=$this->candidate();$this->client->loginUser($u);
        $base='/fr/super/'.$a->getId().'/recrutement';
        $this->client->request('GET',$base.'?department=75&subject=Informatique&basis=hourly&max=70');self::assertResponseIsSuccessful();self::assertSelectorTextContains('body','Camille');
        $this->client->request('GET',$base.'/'.$candidate->id);self::assertResponseIsSuccessful();self::assertSelectorExists('select[name="entities[]"]');
        $this->client->request('GET',$base.'/'.$candidate->id.'/fichier/cv');self::assertResponseIsSuccessful();
        $u=$this->em->find(Utilisateur::class,$u->getId());$a=$this->em->find(Entite::class,$a->getId());
        $normal=(new Utilisateur())->setEmail('normal@recruit.test')->setPassword('unused')->setPrenom('Normal')->setNom('Admin')->setEntite($a);
        $this->em->persist($normal);$this->em->persist((new UtilisateurEntite())->setUtilisateur($normal)->setEntite($a)->setCreateur($u)->setRoles(['TENANT_ADMIN']));$this->em->flush();$this->client->loginUser($normal);
        $this->client->request('GET',$base);self::assertResponseStatusCodeSame(403);
        $this->client->request('GET',$base.'/'.$candidate->id.'/fichier/cv');self::assertResponseStatusCodeSame(403);
    }
    public function testPublicSubmissionStoresPrivateCvAndValidatesInput(): void {
        $c=$this->client->request('GET','/fr/recrutement');
        $token=$c->filter('input[name="_token"]')->attr('value');
        $data=['_token'=>$token,'prenom'=>'Camille','nom'=>'Expert','email'=>'new@recruit.test','telephone'=>'0600000000','societe'=>'Test','siret'=>'12345678901234','adresse'=>'1 rue Test','codePostal'=>'75001','ville'=>'Paris','tarifHoraire'=>'60,50','subjects'=>['Spécialité personnelle'],'departments'=>['75'],'consent'=>'1','accuracy'=>'1'];
        $this->client->request('POST','/fr/recrutement',$data);self::assertResponseStatusCodeSame(422);
        self::assertSame(0,$this->em->getRepository(TrainerApplication::class)->count([]));
        $tmp=tempnam(sys_get_temp_dir(),'recruit-test');
        $pdf=new \Dompdf\Dompdf();$pdf->loadHtml('<p>CV de test</p>');$pdf->render();file_put_contents($tmp,$pdf->output());
        $this->client->request('POST','/fr/recrutement',$data,['cv'=>new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp,'CV.pdf','application/pdf',null,true)]);
        self::assertResponseRedirects('/fr/recrutement/merci');
        $a=$this->em->getRepository(TrainerApplication::class)->findOneBy(['email'=>'new@recruit.test']);
        self::assertNotNull($a);self::assertSame(6050,$a->hourlyRate);self::assertSame(['75'],$a->departments);
        $file=self::getContainer()->get(Applications::class)->directory().'/'.$a->cvFilename;$this->files[]=$file;self::assertFileExists($file);
        self::assertSame(0,$this->em->getRepository(Utilisateur::class)->count([]));
    }
    public function testProfessionalReferencesAndPrivateSupportingDocuments(): void {
        [$admin,$entity]=$this->fixtures();
        $service=self::getContainer()->get(Applications::class);
        $data=['prenom'=>'Camille','nom'=>'Expert','email'=>'remote@recruit.test','telephone'=>'0600000000','societe'=>'Test','siret'=>'12345678901234','adresse'=>'1 rue Test','codePostal'=>'75001','ville'=>'Paris','tarifHoraire'=>'60','subjects'=>['Bureautique'],'remoteTraining'=>'1','consent'=>'1','accuracy'=>'1','hasNda'=>'1','nda'=>'11 123 456 789','qualiopiStatus'=>'yes','qualiopiNumber'=>'CERT-2026-123','competenceEvidence'=>'1'];
        $request=new \Symfony\Component\HttpFoundation\Request([], $data);
        $candidate=$service->parse($request);
        self::assertTrue($candidate->details['remoteTraining']);
        self::assertSame([], $candidate->departments);
        self::assertSame('11123456789',$candidate->details['nda']);
        self::assertSame('CERT-2026-123',$candidate->details['qualiopiNumber']);
        self::assertFalse($candidate->details['commitments']['qualityDiscussion']);
        $pdf=new \Dompdf\Dompdf();$pdf->loadHtml('<p>Justificatif test</p>');$pdf->render();
        foreach(['cv','rcPro','qualiopi'] as $kind) {
            $tmp=tempnam(sys_get_temp_dir(),'recruit-ref-');$this->files[]=$tmp;file_put_contents($tmp,$pdf->output());
            $request->files->set($kind,new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp,$kind.'.pdf','application/pdf',null,true));
        }
        $service->upload($candidate,$request);
        $this->files[]=$service->directory().'/'.$candidate->cvFilename;
        foreach($candidate->details['documents'] as $document) {$this->files[]=$service->directory().'/'.$document['filename'];self::assertFileExists($service->directory().'/'.$document['filename']);}
        $this->em->persist($candidate);$this->em->flush();
        $url='/fr/super/'.$entity->getId().'/recrutement/'.$candidate->id.'/fichier/rcPro';
        $this->client->request('GET',$url);self::assertResponseRedirects('http://localhost/fr/login');
        $this->client->loginUser($admin);
        $this->client->request('GET',$url);self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET','/fr/super/'.$entity->getId().'/recrutement/'.$candidate->id);self::assertResponseIsSuccessful();
        self::assertStringContainsString('CERT-2026-123',$this->client->getResponse()->getContent());
        foreach([['nda'=>'123'],['accuracy'=>'0'],['qualiopiStatus'=>'invalid']] as $invalid) {
            try {$service->parse(new \Symfony\Component\HttpFoundation\Request([],array_replace($data,$invalid)));self::fail('Invalid submission accepted');}
            catch(\InvalidArgumentException $e) {self::assertNotEmpty($e->getMessage());}
        }
        $service->cleanup($candidate);
        self::assertFileDoesNotExist($service->directory().'/'.$candidate->details['documents']['rcPro']['filename']);
    }

    public function testExistingAccountNeedsConfirmation(): void {
        [$u,$a]=$this->fixtures();$candidate=$this->candidate();$candidate->email=$u->getEmail();$this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(Placement::class)->transfer($candidate,[$a],$u);
    }
    public function testConfirmedExistingTrainerIsPreservedAndAnotherEntityReceivesCv(): void {
        [$u,$a,$b]=$this->fixtures();$candidate=$this->candidate();$candidate->email=$u->getEmail();
        $trainer=(new Formateur())->setUtilisateur($u)->setEntite($a)->setCreateur($u)->setTauxHoraireCents(9999);
        $u->setFormateur($trainer);$this->em->persist($trainer);$this->em->flush();
        self::assertSame(2,self::getContainer()->get(Placement::class)->transfer($candidate,[$a,$b],$u,true));
        self::assertSame('Super',$u->getPrenom());self::assertSame(9999,$trainer->getTauxHoraireCents());
        self::assertSame(1,$this->em->getRepository(Utilisateur::class)->count([]));
        foreach($this->em->getRepository(TrainerCv::class)->findAll() as $cv)$this->files[]=dirname(__DIR__,2).'/var/storage/trainer-cv/'.$cv->filename;
        self::assertSame(1,$this->em->getRepository(TrainerCv::class)->count([]));
    }

    public function testTransferFormProcessesMultipleEntities(): void {
        [$u,$a,$b]=$this->fixtures();$candidate=$this->candidate();$this->client->loginUser($u);
        $url='/fr/super/'.$a->getId().'/recrutement/'.$candidate->id;
        $c=$this->client->request('GET',$url);
        $this->client->request('POST',$url,['_token'=>$c->filter('input[name="_token"]')->first()->attr('value'),'version'=>$candidate->version,'action'=>'transfer','entities'=>[(string)$a->getId(),(string)$b->getId()]]);
        self::assertResponseRedirects($url);
        self::assertSame(2,$this->em->getRepository(RecruitmentPlacement::class)->count([]));
        foreach($this->em->getRepository(TrainerCv::class)->findAll() as $cv)$this->files[]=dirname(__DIR__,2).'/var/storage/trainer-cv/'.$cv->filename;
        $this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('body','2 affectation(s) ajoutée(s)');
    }

}
