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
        $company=(new Entreprise())->setEntite($this->tenant)->setRaisonSociale('Entreprise exemple')->setRepresentant($this->employer)->setCreateur($this->admin);$this->em->persist($company);$this->employer->setEntreprise($company);
        $template=(new HabilitationTemplate())->setEntite($this->tenant)->setTitre('Évaluation électrique')->setSchema((new HabilitationSchema())->example());$this->em->persist($template);
        $formation=(new Formation())->setTitre('Habilitation électrique')->setSlug('habilitation-test')->setEntite($this->tenant)->setCreateur($this->admin)->setHabilitationTemplate($template);$this->em->persist($formation);
        $f=(new Formateur())->setUtilisateur($this->trainer)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($f);
        $site=(new \App\Entity\Site())->setNom('Site exemple')->setSlug('site-exemple')->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($site);
        $session=(new Session())->setSite($site)->setEntite($this->tenant)->setCreateur($this->admin)->setFormation($formation)->setCode('SES-TEST')->setFormateur($f);$session->addJour((new \App\Entity\SessionJour())->setEntite($this->tenant)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('-2 days 09:00'))->setDateFin(new \DateTimeImmutable('-2 days 17:00')));$this->em->persist($session);
        $inscription=(new Inscription())->setSession($session)->setStagiaire($this->trainee)->setEntreprise($company)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($inscription);
        $this->dossier=(new HabilitationDossier())->setEntite($this->tenant)->setInscription($inscription)->setTemplate($template)->setSchema($template->getSchema())->setFormateur($this->trainer)->setEntreprise($company);$this->em->persist($this->dossier);$this->em->flush();
        $this->client=new KernelBrowser(self::$kernel);$this->client->disableReboot();$this->client->catchExceptions(false);
    }

    public function testExitDocumentsOnlyContainCurrentTransmittedRevisions(): void
    {
        $service = self::getContainer()->get(\App\Service\Convention\TrainingExitDocuments::class);
        $i = $this->dossier->getInscription();
        self::assertSame([], $service->build($i));
        $this->sign('avis');
        self::assertSame([], $service->build($i), 'Untransmitted trainer advice must remain private.');
        $this->dossier->setState('awaiting_company');
        $this->em->flush();
        $files = $service->build($i);
        self::assertArrayHasKey('Avis-post-formation-'.$this->dossier->getId().'.pdf', $files);
        self::assertStringStartsWith('%PDF', reset($files));
        $this->sign('titre');
        $files = $service->build($i);
        self::assertArrayHasKey('Titre-habilitation-'.$this->dossier->getId().'.pdf', $files);
        $this->dossier->setTitreToken(null)->setState('awaiting_company');
        $this->em->flush();
        self::assertCount(1, $service->build($i), 'An old signed title must not survive reopening the title.');
        $this->dossier->setActive(false);
        $this->em->flush();
        self::assertSame([], $service->build($i));
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
        self::assertStringContainsString('Avis post-formation PDF', $page->text());
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

    public function testEmployerStartsFromSignedOpinionAndCanReduceWithoutChangingIt(): void
    {
        $workflow = self::getContainer()->get(HabilitationWorkflow::class);
        $input = $this->input();
        $input['rows']['row_0']['symbols'] = ['H0V'];
        $input['rows']['row_0']['voltage'] = ['HTA'];
        $input['rows']['row_1'] = ['verdict' => 'unfavorable', 'symbols' => ['H0V'], 'voltage' => ['HTA']];
        $input['restrictions'] = 'Accès au seul atelier principal.';
        $avis = $workflow->save($this->dossier, 'avis', $input, true, $this->trainer, $this->signature());
        $this->em->persist($avis); $this->em->flush();
        $snapshot = $avis->getSnapshot();
        $this->em->persist($workflow->transmit($this->dossier, $this->admin)); $this->em->flush();

        // Even if mutable working data is later changed, only the signed snapshot is authoritative.
        $this->dossier->setTrainerData([]);
        $copy = $workflow->initialEmployerData($this->dossier);
        self::assertSame(['H0V'], $copy['rows']['row_0']['symbols']);
        self::assertSame(['HTA'], $copy['rows']['row_0']['voltage']);
        self::assertSame([], $copy['rows']['row_1']['symbols']);
        self::assertSame([], $copy['rows']['row_1']['voltage']);
        foreach (['issuedAt', 'validUntil', 'signerFunction', 'place'] as $key) self::assertSame('', $copy[$key]);
        self::assertSame('Accès au seul atelier principal.', $copy['restrictions']);

        $copy['rows']['row_0']['symbols'] = ['H0'];
        $copy['issuedAt'] = '2026-10-29'; $copy['validUntil'] = '2027-10-29';
        $copy['signerFunction'] = 'Dirigeant'; $copy['restrictions'] = 'Accès uniquement accompagné.';
        $title = $workflow->save($this->dossier, 'titre', $copy, true, $this->employer, $this->signature());
        $this->em->persist($title); $this->em->flush();
        self::assertSame('published', $this->dossier->getState());
        self::assertSame(['H0'], $title->getSnapshot()['data']['rows']['row_0']['symbols']);
        self::assertSame($snapshot['data'], $title->getSnapshot()['trainerReference']);
        self::assertSame($snapshot, $avis->getSnapshot(), 'The trainer opinion must never be altered by the company decision.');
        self::assertSame('Entreprise exemple', $title->getSnapshot()['identity']['companyBranding']['name']);
        self::assertSame($avis->getToken(), $title->getSnapshot()['avisToken']);
    }

    public function testEmployerCannotUseMutableWorkingDataToExpandSignedScope(): void
    {
        $avis = $this->sign('avis');
        $workflow = self::getContainer()->get(HabilitationWorkflow::class);
        $this->em->persist($workflow->transmit($this->dossier, $this->admin)); $this->em->flush();
        $forged = $this->input(); $forged['rows']['row_0']['symbols'] = ['H0V'];
        $this->dossier->setTrainerData($forged);
        try {
            $workflow->save($this->dossier, 'titre', $forged, false, $this->employer);
            self::fail('A title must be bounded by the immutable signed opinion.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('limites', $e->getMessage());
        }
        self::assertSame('awaiting_company', $this->dossier->getState());
        self::assertSame([], $this->dossier->getEmployerData());
        self::assertSame($avis->getToken(), $this->dossier->getAvisToken());
        self::assertNull($this->dossier->getTitreToken());
    }

    public function testEmployerNeedsActualCurrentSignedRevision(): void
    {
        $this->dossier->setState('awaiting_company')->setAvisToken(str_repeat('a', 32))->setTrainerData($this->input());
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(HabilitationWorkflow::class)->save($this->dossier, 'titre', $this->input(), false, $this->employer);
    }

    public function testChangedSchemaInvalidatesEmployerCopy(): void
    {
        $this->sign('avis');
        $workflow = self::getContainer()->get(HabilitationWorkflow::class);
        $this->em->persist($workflow->transmit($this->dossier, $this->admin)); $this->em->flush();
        $model = $this->dossier->getSchema();
        $model['sections'][0]['rows'][0]['fields'][0]['options'][] = 'Nouveau niveau';
        $this->dossier->setSchema($model);
        $this->expectException(\InvalidArgumentException::class);
        $workflow->initialEmployerData($this->dossier);
    }
    public function testTraineeCanReadTransmittedOpinionBeforeEmployerSignsTitle(): void
    {
        $avis = $this->sign('avis');
        $this->em->persist(self::getContainer()->get(HabilitationWorkflow::class)->transmit($this->dossier, $this->admin));
        $this->em->flush();
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->trainee);
        $page = $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('SES-TEST', $page->text());
        $page = $this->client->request('GET', $this->show());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('#hab-evaluation > fieldset[disabled]'));
        self::assertCount(0, $page->filter('#hab-evaluation button[name="action"]'));
        $token = $page->filter('#hab-evaluation input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->show(), ['_token'=>$token, '_complete'=>'1', 'action'=>'save', 'data'=>$this->input()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->show('/document/'.$avis->getToken()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->show('?stage=titre'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->other);
        $this->client->request('GET', $this->show());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCompanyRoleWithoutExplicitRepresentationCannotEditOwnHabilitation(): void
    {
        $this->sign('avis'); $this->sign('titre');
        $company = $this->dossier->getEntreprise();
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
            'utilisateur'=>$this->trainee, 'entite'=>$this->tenant,
        ]);
        $this->client->catchExceptions(true);
        foreach ([null, $company] as $attachedCompany) {
            $this->trainee = $this->em->find(Utilisateur::class, $this->trainee->getId());
            $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
            $this->trainee->setEntreprise($attachedCompany ? $this->em->find(Entreprise::class, $attachedCompany->getId()) : null);
            $membership->setRoles(['TENANT_ENTREPRISE']);
            $this->em->flush();
            $this->client->loginUser($this->trainee);
            foreach ([$this->url(), $this->show(), $this->show('?stage=titre'), $this->show('/entreprise').'?entreprise='.$company->getId()] as $url) {
                $this->client->request('GET', $url);
                self::assertSame(403, $this->client->getResponse()->getStatusCode());
            }
            // Keeping the trainee role grants personal read access, never employer editing rights.
            $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
            $membership->setRoles(['TENANT_ENTREPRISE', 'TENANT_STAGIAIRE']);
            $this->em->flush();
            $this->client->loginUser($this->trainee);
            foreach (['avis', 'titre'] as $stage) {
                $url = $this->show('?stage='.$stage);
                $page = $this->client->request('GET', $url);
                self::assertSame(200, $this->client->getResponse()->getStatusCode());
                self::assertCount(1, $page->filter('#hab-evaluation > fieldset[disabled]'));
                self::assertCount(0, $page->filter('#hab-evaluation button[name="action"]'));
                $token = $page->filter('#hab-evaluation input[name="_token"]')->attr('value');
                foreach (['save', 'sign'] as $action) {
                    $this->client->request('POST', $url, ['_token'=>$token, '_complete'=>'1',
                        'version'=>$this->dossier->getVersion(), 'action'=>$action, 'data'=>$this->input()]);
                    self::assertSame(403, $this->client->getResponse()->getStatusCode());
                }
            }
        }
        // Removing representation immediately revokes an existing employer's access,
        // even when the primary company association remains.
        $company = $this->em->find(Entreprise::class, $company->getId());
        $company->setRepresentant(null);
        $this->em->flush();
        $this->client->loginUser($this->employer);
        $this->client->request('GET', $this->show('/entreprise').'?entreprise='.$company->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->show('?stage=titre'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCompanyAttachmentDoesNotLetTraineesOrAdminsEditEmployerTitles(): void
    {
        $this->sign('avis'); $this->sign('titre');
        $company = $this->dossier->getEntreprise();
        $this->trainee->setEntreprise($company);
        $this->other->setEntreprise($company);
        $this->admin->setEntreprise($company);
        $this->em->flush();
        $this->client->catchExceptions(true);
        $before = $this->dossier->getEmployerData();
        foreach ([$this->trainee, $this->admin] as $user) {
            $this->client->loginUser($user);
            $page = $this->client->request('GET', $this->show('?stage=titre'));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $token = $page->filter('#hab-evaluation input[name="_token"]')->attr('value');
            foreach (['save', 'sign'] as $action) {
                $this->client->request('POST', $this->show('?stage=titre'), [
                    '_token'=>$token, '_complete'=>'1', 'action'=>$action,
                    'version'=>$this->dossier->getVersion(), 'data'=>$this->input(),
                ]);
                self::assertSame(403, $this->client->getResponse()->getStatusCode());
            }
            $this->client->request('GET', $this->show('/entreprise').'?entreprise='.$company->getId());
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
            $base = '/fr/entreprise/'.$this->tenant->getId().'/documents/habilitations';
            $this->client->request('POST', $base.'/avis/ajax?entreprise='.$company->getId());
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
            $this->client->request('GET', $base.'/'.$this->dossier->getId().'/avis/pdf?entreprise='.$company->getId());
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $this->client->loginUser($this->other);
        $this->client->request('GET', $this->show('?stage=titre'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->dossier->getEmployerData());
        $this->admin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $voter = self::getContainer()->get(\App\Security\Voter\TenantVoter::class);
        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->admin, 'main', $this->admin->getRoles());
        self::assertSame(-1, $voter->vote($token, $this->tenant, [\App\Security\Permission\TenantPermission::HABILITATION_ENTREPRISE]));

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
