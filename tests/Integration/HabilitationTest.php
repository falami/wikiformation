<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Utilisateur, UtilisateurEntite, Entite, Entreprise, Formation, Session, Formateur, Inscription, HabilitationTemplate, HabilitationDossier, HabilitationRevision};
use App\Service\Habilitation\{HabilitationSchema, HabilitationWorkflow};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class HabilitationTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $oldDb;
    private $em;
    private Entite $tenant;
    private Utilisateur $admin;
    private Utilisateur $trainer;
    private Utilisateur $employer;
    private Utilisateur $trainee;
    private Utilisateur $other;
    private HabilitationDossier $dossier;
    protected function setUp(): void {
        $this->oldDb=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';self::bootKernel();
        $this->em=self::getContainer()->get('doctrine')->getManager();(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('twig')->addGlobal('entite',null);
        $this->admin=$this->user('Admin');$this->trainer=$this->user('Formateur');$this->employer=$this->user('Employeur');$this->trainee=$this->user('Stagiaire');$this->other=$this->user('Autre');
        $this->tenant=(new Entite())->setNom('Centre exemple')->setCreateur($this->admin)->setPublic(false);$this->em->persist($this->tenant);$this->em->flush();
        foreach ([[$this->admin,'TENANT_ADMIN'],[$this->trainer,'TENANT_FORMATEUR'],[$this->employer,'TENANT_ENTREPRISE'],[$this->trainee,'TENANT_STAGIAIRE'],[$this->other,'TENANT_STAGIAIRE']] as [$u,$role]) {
            $u->setEntite($this->tenant);$this->em->persist((new UtilisateurEntite())->setEntite($this->tenant)->setUtilisateur($u)->setCreateur($this->admin)->setRoles([$role]));
        }
        // Ownership scenarios require two trainers and two companies in this organism.
        $plan = (new \App\Entity\Billing\Plan())->setCode('HABILITATION')->setName('Isolation fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(2)->setMaxEntreprises(2)->setMaxProspects(50);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->tenant)->setStatus('active')->setPlan($plan));
        $company=(new Entreprise())->setEntite($this->tenant)->setRaisonSociale('Entreprise exemple')->setCreateur($this->admin);$this->em->persist($company);$this->employer->setEntreprise($company);
        $template=(new HabilitationTemplate())->setEntite($this->tenant)->setTitre('Évaluation électrique')->setSchema((new HabilitationSchema())->example());$this->em->persist($template);
        $formation=(new Formation())->setTitre('Habilitation électrique')->setSlug('habilitation-test')->setEntite($this->tenant)->setCreateur($this->admin)->setHabilitationTemplate($template);$this->em->persist($formation);
        $f=(new Formateur())->setUtilisateur($this->trainer)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($f);
        $site=(new \App\Entity\Site())->setNom('Site exemple')->setSlug('site-exemple')->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($site);
        $session=(new Session())->setSite($site)->setEntite($this->tenant)->setCreateur($this->admin)->setFormation($formation)->setCode('SES-TEST')->setFormateur($f);$session->addJour((new \App\Entity\SessionJour())->setEntite($this->tenant)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('-2 days 09:00'))->setDateFin(new \DateTimeImmutable('-2 days 17:00')));$this->em->persist($session);
        $inscription=(new Inscription())->setSession($session)->setStagiaire($this->trainee)->setEntreprise($company)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($inscription);
        $this->dossier=(new HabilitationDossier())->setEntite($this->tenant)->setInscription($inscription)->setTemplate($template)->setSchema($template->getSchema())->setFormateur($this->trainer)->setEntreprise($company);$this->em->persist($this->dossier);$this->em->flush();
        $this->client=new KernelBrowser(self::$kernel);$this->client->disableReboot();$this->client->catchExceptions(false);
    }

    public function testSelectedAttestationsExportAndAccessControl(): void
    {
        $this->sign('avis');
        $this->client->loginUser($this->trainer);
        $page = $this->client->request('GET', $this->url());
        $token = $page->filter('#hab-export-selection input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url().'/attestations-selection', ['_token'=>$token, 'ids'=>[$this->dossier->getId()]]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'test-hab-zip');
        try {
            file_put_contents($path, $this->client->getResponse()->getContent());
            $zip = new \ZipArchive(); self::assertTrue($zip->open($path));
            self::assertSame(1, $zip->numFiles);
            self::assertStringStartsWith('%PDF', $zip->getFromIndex(0)); $zip->close();
        } finally { unlink($path); }
        $this->client->request('POST', $this->url().'/attestations-selection', ['_token'=>$token, 'ids'=>[]]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->other);
        $page = $this->client->request('GET', $this->url());
        $token = $page->filter('#hab-export-selection input[name="_token"]')->attr('value');
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url().'/attestations-selection', ['_token'=>$token, 'ids'=>[$this->dossier->getId()]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkAssignmentsAreAtomicAndArchivePreviousAnswers(): void
    {
        $company=(new Entreprise())->setEntite($this->tenant)->setRaisonSociale('Nouvel employeur')->setCreateur($this->admin);
        $this->em->persist($company);
        $newTrainer=(new Formateur())->setUtilisateur($this->other)->setEntite($this->tenant)->setCreateur($this->admin);
        $newModel=(new HabilitationTemplate())->setEntite($this->tenant)->setTitre('Nouveau questionnaire')->setSchema($this->dossier->getSchema());
        $this->em->persist($newTrainer);$this->em->persist($newModel);
        $i=(new Inscription())->setSession($this->dossier->getInscription()->getSession())->setStagiaire($this->other)->setEntreprise($this->dossier->getEntreprise())->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($i);
        $second=(new HabilitationDossier())->setEntite($this->tenant)->setInscription($i)->setTemplate($this->dossier->getTemplate())->setSchema($this->dossier->getSchema())->setFormateur($this->trainer)->setEntreprise($this->dossier->getEntreprise());
        $this->dossier->setTrainerData(['comment'=>'Réponse à conserver'])->setAvisToken('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->setState('pending_admin');
        $this->em->persist($second);$this->em->flush();
        $ids=[$this->dossier->getId(),$second->getId()];
        $versions=[$ids[0]=>$this->dossier->getVersion(),$ids[1]=>$second->getVersion()];
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url());
        self::assertCount(2,$page->filter('.hab-select-row'));
        $token=$page->filter('#hab-bulk-selection input[name="_token"]')->attr('value');
        $url=$this->url().'/modifier-selection';
        $this->client->request('POST',$url,['_token'=>$token,'ids'=>$ids]);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $payload=['_token'=>$token,'ids'=>$ids,'versions'=>$versions,'action'=>'apply','entreprise'=>$company->getId(),'template'=>$newModel->getId(),'change_trainers'=>'1','formateurs'=>[$newTrainer->getId()],'confirm_reset'=>'1'];
        $invalid=$payload;$invalid['entreprise']=999999;
        $this->client->request('POST',$url,$invalid);
        self::assertSame(422,$this->client->getResponse()->getStatusCode());
        $this->em=self::getContainer()->get('doctrine')->getManager();
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',$this->em->find(HabilitationDossier::class,$ids[0])->getAvisToken());
        $stale=$payload;$stale['versions'][$ids[1]]=0;
        $this->client->request('POST',$url,$stale);
        self::assertSame(409,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$url,$payload);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $this->em=self::getContainer()->get('doctrine')->getManager();
        foreach($ids as $id) {
            $d=$this->em->find(HabilitationDossier::class,$id);
            self::assertSame($company->getId(),$d->getEntreprise()->getId());
            self::assertSame('draft',$d->getState());self::assertNull($d->getAvisToken());
            self::assertSame($this->other->getId(),$d->getFormateur()->getId());self::assertSame($newModel->getId(),$d->getTemplate()->getId());
        }
        $revision=$this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier'=>$ids[0],'kind'=>'reassign']);
        self::assertSame('Réponse à conserver',$revision->getSnapshot()['previous']['trainerData']['comment']);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',$revision->getSnapshot()['previous']['avisToken']);
    }

    public function testBulkAssignmentRejectsOtherTenantAndTrainer(): void
    {
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->url());
        $token=$page->filter('#hab-bulk-selection input[name="_token"]')->attr('value');
        $this->client->request('POST',$this->url().'/modifier-selection',['_token'=>'invalid','ids'=>[$this->dossier->getId()]]);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$this->url().'/modifier-selection',['_token'=>$token,'ids'=>[$this->dossier->getId(),999999]]);
        self::assertSame(404,$this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->trainer);
        $page=$this->client->request('GET',$this->url());self::assertCount(0,$page->filter('#hab-bulk-selection'));self::assertCount(0,$page->filter('.hab-select-row:not([disabled])'));
        $this->client->request('POST',$this->url().'/modifier-selection',['_token'=>$token,'ids'=>[$this->dossier->getId()]]);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanAssignMultipleTrainersAndRemoveAccess(): void
    {
        $second = $this->user('Second');
        $second->setEntite($this->tenant);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->tenant)->setUtilisateur($second)->setCreateur($this->admin)->setRoles(['TENANT_FORMATEUR']));
        $f = (new Formateur())->setUtilisateur($second)->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($f); $this->em->flush();
        $first = $this->em->getRepository(Formateur::class)->findOneBy(['utilisateur'=>$this->trainer]);
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', $this->show('/reaffecter'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->show('/reaffecter'), [
            '_token'=>$page->filter('input[name="_token"]')->attr('value'),
            'version'=>$this->dossier->getVersion(),
            'inscription'=>$this->dossier->getInscription()->getId(),
            'template'=>$this->dossier->getTemplate()->getId(),
            'entreprise'=>$this->dossier->getEntreprise()->getId(),
            'formateurs'=>[$first->getId(), $f->getId()],
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->dossier = $this->em->find(HabilitationDossier::class, $this->dossier->getId());
        self::assertCount(2, $this->dossier->getFormateurs());
        $this->client->loginUser($second);
        $page = $this->client->request('GET', $this->url());
        self::assertStringContainsString('SES-TEST', $page->text());
        $page = $this->client->request('GET', $this->show());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->show(), [
            '_token'=>$page->filter('#hab-evaluation input[name="_token"]')->attr('value'),
            '_complete'=>'1', 'version'=>$this->dossier->getVersion(), 'action'=>'sign', 'data'=>$this->input(),
            'confirm'=>'1', 'signature'=>$this->signature(),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->dossier = $this->em->find(HabilitationDossier::class, $this->dossier->getId());
        $this->dossier->setFormateurs([$this->em->find(Utilisateur::class, $this->trainer->getId())]); $this->em->flush();
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->show());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAttestationIsAvailableAfterTransmissionAndUsesSignedSnapshot(): void
    {
        $avis = $this->sign('avis');
        $url = $this->show('/attestation/'.$avis->getToken());
        $snapshot = $avis->getSnapshot();
        self::assertArrayHasKey('durationHours', $snapshot['identity']);
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
        if (getenv('HAB_PREVIEW_DIR')) file_put_contents(getenv('HAB_PREVIEW_DIR').'/attestation.pdf', $this->client->getResponse()->getContent());
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->employer);
        $this->client->request('GET', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', $this->show());
        $this->client->submit($page->filter('form[action$="/transmettre"]')->form());
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->employer);
        $page = $this->client->request('GET', $this->url());
        self::assertStringContainsString('Attestation PDF', $page->text());
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->loginUser($this->other);
        $this->client->request('GET', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame($snapshot, $avis->getSnapshot());
    }

    public function testAdminAttestationLibraryIncludesArchivedDocumentsAndRejectsTrainer(): void
    {
        $avis = $this->sign('avis');
        $this->dossier->setAvisToken(null)->setState('draft');
        $this->em->flush();
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', $this->url('/attestations'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Archivée', $page->text());
        self::assertCount(1, $page->filter('a[href$="/attestation/'.$avis->getToken().'"]'));
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->trainer);
        $this->client->request('GET', $this->url('/attestations'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function user(string $name): Utilisateur { $u=(new Utilisateur())->setPrenom($name)->setNom('Test')->setEmail(strtolower($name).'@example.test')->setPassword('unused');$this->em->persist($u);return $u; }
    protected function tearDown(): void { parent::tearDown();foreach(['ENV','SERVER'] as $i=>$scope){if($this->oldDb[$i]===null)unset($GLOBALS['_'.$scope]['DATABASE_URL']);else $GLOBALS['_'.$scope]['DATABASE_URL']=$this->oldDb[$i];} }
    private function url(string $suffix=''): string { return '/fr/habilitation/'.$this->tenant->getId().$suffix; }
    private function show(string $query=''): string { return $this->url('/'.$this->dossier->getId()).$query; }
    private function input(): array {return ['function'=>'Technicien','assignment'=>'Atelier','issuedAt'=>'2026-10-06','validUntil'=>'2027-10-06','signerFunction'=>'Responsable','place'=>'Alès','additionalDocument'=>'Aucun','rows'=>['row_0'=>['verdict'=>'favorable','symbols'=>['B0'],'voltage'=>['BT'],'works'=>'Atelier principal','details'=>'Sans travaux sous tension']]];}
    private function signature(): string { $im=imagecreatetruecolor(100,40);imagesetthickness($im,2);imageline($im,5,5,95,35,imagecolorallocate($im,240,240,240));ob_start();imagepng($im);$s=ob_get_clean();imagedestroy($im);return 'data:image/png;base64,'.base64_encode($s); }
    private function sign(string $stage): HabilitationRevision { if ($stage==='titre' && $this->dossier->getState()==='pending_admin') { $this->em->persist(self::getContainer()->get(HabilitationWorkflow::class)->transmit($this->dossier,$this->admin));$this->em->flush(); } $r=self::getContainer()->get(HabilitationWorkflow::class)->save($this->dossier,$stage,$this->input(),true,$stage==='avis'?$this->trainer:$this->employer,$this->signature());$this->em->persist($r);$this->em->flush();return $r; }
    public function testAdminPagesAndTemplateSave(): void {
        $this->client->loginUser($this->admin);
        foreach(['','/modeles','/modeles/nouveau','/affecter','/'.$this->dossier->getId(),'/'.$this->dossier->getId().'/reaffecter'] as $path){$this->client->request('GET',$this->url($path));self::assertSame(200,$this->client->getResponse()->getStatusCode());}
        $p=$this->client->request('GET',$this->url('/modeles/nouveau'));if(getenv('HAB_PREVIEW_DIR'))file_put_contents(getenv('HAB_PREVIEW_DIR').'/model.html',$this->client->getResponse()->getContent());$form=$p->filter('#hab-builder')->form();$form['titre']='Nouveau modèle';$this->client->submit($form);self::assertSame(302,$this->client->getResponse()->getStatusCode());self::assertCount(2,$this->em->getRepository(HabilitationTemplate::class)->findAll());
    }
    public function testFullHttpSignatureFlowAndDownload(): void {
        foreach ([['avis',$this->trainer],['titre',$this->employer]] as [$stage,$actor]) {
            if ($stage==='titre') {
                $this->client->loginUser($this->admin);
                $page=$this->client->request('GET',$this->show());
                $form=$page->filter('form[action$="/transmettre"]')->form();
                $this->client->submit($form);self::assertSame(302,$this->client->getResponse()->getStatusCode());
            }
            $this->client->loginUser($actor);$page=$this->client->request('GET',$this->show('?stage='.$stage));self::assertSame(200,$this->client->getResponse()->getStatusCode());
            if (getenv('HAB_PREVIEW_DIR')) file_put_contents(getenv('HAB_PREVIEW_DIR').'/'.$stage.'.html', $this->client->getResponse()->getContent());
            $token=$page->filter('#hab-evaluation input[name="_token"]')->attr('value');
            $this->client->request('POST',$this->show('?stage='.$stage),['_token'=>$token,'version'=>(int)$page->filter('#hab-evaluation input[name=version]')->attr('value'),'data'=>$this->input(),'signature'=>$this->signature(),'confirm'=>'1','action'=>'sign','_complete'=>'1']);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        }
        $this->dossier=$this->em->getRepository(HabilitationDossier::class)->find($this->dossier->getId());
        self::assertSame('published',$this->dossier->getState());$this->client->loginUser($this->trainee);$this->client->request('GET',$this->show());self::assertSame(200,$this->client->getResponse()->getStatusCode());
        foreach ([$this->dossier->getAvisToken(),$this->dossier->getTitreToken()] as $token){$this->client->request('GET',$this->show('/document/'.$token));self::assertSame(200,$this->client->getResponse()->getStatusCode());self::assertStringStartsWith('%PDF',$this->client->getResponse()->getContent());if(getenv('HAB_PREVIEW_DIR'))file_put_contents(getenv('HAB_PREVIEW_DIR').'/'.$token.'.pdf',$this->client->getResponse()->getContent());self::assertStringContainsString('no-store',$this->client->getResponse()->headers->get('Cache-Control'));}
    }
    public function testEditingAvisInvalidatesTitleAndPreservesSnapshot(): void {
        $avis=$this->sign('avis');$titre=$this->sign('titre');$snapshot=$avis->getSnapshot();
        $r=self::getContainer()->get(HabilitationWorkflow::class)->save($this->dossier,'avis',['observations'=>'Nouvelle évaluation'],false,$this->admin);$this->em->persist($r);$this->em->flush();
        self::assertNull($this->dossier->getTitreToken());self::assertNull($this->dossier->getAvisToken());self::assertSame('draft',$this->dossier->getState());self::assertSame($snapshot,$avis->getSnapshot());self::assertSame('titre',$titre->getKind());
    }
    public function testUnrelatedTraineeCannotReadOrSign(): void {
        $this->sign('avis');$this->sign('titre');$this->client->catchExceptions(true);$this->client->loginUser($this->other);$this->client->request('GET',$this->show());self::assertSame(403,$this->client->getResponse()->getStatusCode());$this->client->request('GET',$this->show('/document/'.$this->dossier->getTitreToken()));self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }
    public function testStaleFormAndInvalidCsrfRejected(): void {
        $this->client->catchExceptions(true);$this->client->loginUser($this->trainer);$p=$this->client->request('GET',$this->show());$token=$p->filter('#hab-evaluation input[name="_token"]')->attr('value');
        $this->client->request('POST',$this->show(),['_token'=>'invalid','action'=>'save','data'=>[],'_complete'=>'1']);self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$this->show(),['_token'=>$token,'version'=>999,'action'=>'save','data'=>[],'_complete'=>'1']);self::assertSame(409,$this->client->getResponse()->getStatusCode());
    }
    public function testTraineeCannotAccessBeforePublicationOrAfterDisable(): void {
        $this->client->catchExceptions(true);$this->client->loginUser($this->trainee);
        $this->client->request('GET',$this->show());self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->sign('avis');$title=$this->sign('titre');$this->dossier->setActive(false);$this->em->flush();
        $this->client->request('GET',$this->show('/document/'.$title->getToken()));self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }
    public function testOtherTenantCannotAccessEvenWithAdminRole(): void {
        $other=(new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);$this->em->persist($other);$this->em->persist((new UtilisateurEntite())->setEntite($other)->setUtilisateur($this->admin)->setCreateur($this->admin)->setRoles(['TENANT_ADMIN']));$this->em->flush();
        $this->client->catchExceptions(true);$this->client->loginUser($this->admin);$this->client->request('GET','/fr/habilitation/'.$other->getId().'/'.$this->dossier->getId());self::assertSame(404,$this->client->getResponse()->getStatusCode());
    }
    public function testReassignmentResetsApprovalsAndKeepsHistory(): void {
        $avis=$this->sign('avis');$this->sign('titre');$this->client->loginUser($this->admin);
        $page=$this->client->request('GET',$this->show('/reaffecter'));$this->client->submit($page->filter('#hab-assignment')->form());self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $d=$this->em->getRepository(HabilitationDossier::class)->find($this->dossier->getId());self::assertNull($d->getAvisToken());self::assertNull($d->getTitreToken());self::assertSame([],$d->getTrainerData());self::assertCount(4,$this->em->getRepository(HabilitationRevision::class)->findBy(['dossier'=>$d]));self::assertNotEmpty($avis->getSnapshot()['signature']);
    }
    public function testAdminCanEditButCannotImpersonateSignatory(): void {
        $this->client->loginUser($this->admin);$page=$this->client->request('GET',$this->show());$token=$page->filter('#hab-evaluation input[name="_token"]')->attr('value');$version=$page->filter('#hab-evaluation input[name="version"]')->attr('value');$this->client->catchExceptions(true);
        $this->client->request('POST',$this->show(),['_token'=>$token,'version'=>$version,'_complete'=>'1','action'=>'sign','confirm'=>'1','signature'=>$this->signature(),'data'=>$this->input()]);self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }

    public function testNewRegistrationInheritsModelAndPreservesItsSnapshot(): void {
        $i=(new Inscription())->setSession($this->dossier->getInscription()->getSession())->setStagiaire($this->other)->setEntreprise($this->dossier->getEntreprise())->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($i);$this->em->flush();$d=$this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription'=>$i]);
        self::assertNotNull($d);self::assertSame($this->trainer,$d->getFormateur());$snapshot=$d->getSchema();
        $model=$d->getTemplate();$changed=$model->getSchema();$changed['sections'][0]['title']='Modifié après affectation';$model->setSchema($changed);$this->em->flush();self::assertSame($snapshot,$d->getSchema());
    }

    public function testTrainerCannotAssignAndEmployerMustWaitForAdmin(): void {
        $this->sign('avis');
        self::assertSame('pending_admin',$this->dossier->getState());
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->trainer);
        foreach (['GET','POST'] as $method) {
            $this->client->request($method,$this->url('/affecter'));
            self::assertSame(403,$this->client->getResponse()->getStatusCode());
        }
        $this->client->loginUser($this->employer);
        foreach ([$this->show(),$this->show('/document/'.$this->dossier->getAvisToken())] as $url) {
            $this->client->request('GET',$url);self::assertSame(403,$this->client->getResponse()->getStatusCode());
        }
        $this->client->request('GET',$this->url());
        self::assertStringNotContainsString('SES-TEST',$this->client->getResponse()->getContent());
        $this->client->loginUser($this->trainer);
        $this->client->request('POST',$this->show('/transmettre'));
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }

    public function testPastAssignmentsAreBackfilledOnceAndFutureOnesWait(): void {
        $session=$this->dossier->getInscription()->getSession();
        foreach ($session->getJours() as $day) $day->setDateDebut(new \DateTimeImmutable('+2 days 09:00'))->setDateFin(new \DateTimeImmutable('+2 days 17:00'));
        $i=(new Inscription())->setSession($session)->setStagiaire($this->other)->setEntreprise($this->dossier->getEntreprise())->setEntite($this->tenant)->setCreateur($this->admin);
        $this->em->persist($i);$this->em->flush();
        $service=self::getContainer()->get(\App\Service\Habilitation\HabilitationAssignments::class);
        self::assertSame(0,$service->synchronize($this->tenant)['created']);
        self::assertNull($this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription'=>$i]));
        foreach ($session->getJours() as $day) $day->setDateDebut(new \DateTimeImmutable('-10 days 09:00'))->setDateFin(new \DateTimeImmutable('-10 days 17:00'));
        $this->em->flush();
        self::assertSame(1,$service->synchronize($this->tenant)['created']);
        self::assertSame(0,$service->synchronize($this->tenant)['created']);
        $this->client->loginUser($this->trainer);
        $this->client->request('GET',$this->url('?session='.$session->getId()));
        self::assertStringContainsString('SES-TEST',$this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Affecter une évaluation',$this->client->getResponse()->getContent());
        $this->client->request('GET',$this->url('?session=999999'));
        self::assertStringNotContainsString('SES-TEST',$this->client->getResponse()->getContent());
    }

    public function testEligibilityUsesFirstCalendarDayAndExcludesCancellations(): void {
        $i=$this->dossier->getInscription();$s=$i->getSession();
        $now=new \DateTimeImmutable('2026-10-18 00:01',new \DateTimeZone('Europe/Paris'));
        foreach ($s->getJours() as $day) $day->setDateDebut(new \DateTimeImmutable('2026-10-18 09:00',new \DateTimeZone('Europe/Paris')));
        self::assertTrue(\App\Service\Habilitation\HabilitationAssignments::due($i,$now));
        self::assertFalse(\App\Service\Habilitation\HabilitationAssignments::due($i,$now->modify('-1 day')));
        $i->setStatus(\App\Enum\StatusInscription::ANNULE);
        self::assertFalse(\App\Service\Habilitation\HabilitationAssignments::due($i,$now));
        $i->setStatus(\App\Enum\StatusInscription::ABSENT);
        self::assertFalse(\App\Service\Habilitation\HabilitationAssignments::due($i,$now));
        $i->setStatus(\App\Enum\StatusInscription::CONFIRME);$s->setStatus(\App\Enum\StatusSession::CANCELED);
        self::assertFalse(\App\Service\Habilitation\HabilitationAssignments::due($i,$now));
    }

}
