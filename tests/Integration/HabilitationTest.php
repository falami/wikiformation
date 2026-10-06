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
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->tenant)->setStatus('active'));
        $company=(new Entreprise())->setEntite($this->tenant)->setRaisonSociale('Entreprise exemple')->setCreateur($this->admin);$this->em->persist($company);$this->employer->setEntreprise($company);
        $template=(new HabilitationTemplate())->setEntite($this->tenant)->setTitre('Évaluation électrique')->setSchema((new HabilitationSchema())->example());$this->em->persist($template);
        $formation=(new Formation())->setTitre('Habilitation électrique')->setSlug('habilitation-test')->setEntite($this->tenant)->setCreateur($this->admin)->setHabilitationTemplate($template);$this->em->persist($formation);
        $f=(new Formateur())->setUtilisateur($this->trainer)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($f);
        $site=(new \App\Entity\Site())->setNom('Site exemple')->setSlug('site-exemple')->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($site);
        $session=(new Session())->setSite($site)->setEntite($this->tenant)->setCreateur($this->admin)->setFormation($formation)->setCode('SES-TEST')->setFormateur($f);$this->em->persist($session);
        $inscription=(new Inscription())->setSession($session)->setStagiaire($this->trainee)->setEntreprise($company)->setEntite($this->tenant)->setCreateur($this->admin);$this->em->persist($inscription);
        $this->dossier=(new HabilitationDossier())->setEntite($this->tenant)->setInscription($inscription)->setTemplate($template)->setSchema($template->getSchema())->setFormateur($this->trainer)->setEntreprise($company);$this->em->persist($this->dossier);$this->em->flush();
        $this->client=new KernelBrowser(self::$kernel);$this->client->disableReboot();$this->client->catchExceptions(false);
    }
    private function user(string $name): Utilisateur { $u=(new Utilisateur())->setPrenom($name)->setNom('Test')->setEmail(strtolower($name).'@example.test')->setPassword('unused');$this->em->persist($u);return $u; }
    protected function tearDown(): void { parent::tearDown();foreach(['ENV','SERVER'] as $i=>$scope){if($this->oldDb[$i]===null)unset($GLOBALS['_'.$scope]['DATABASE_URL']);else $GLOBALS['_'.$scope]['DATABASE_URL']=$this->oldDb[$i];} }
    private function url(string $suffix=''): string { return '/fr/habilitation/'.$this->tenant->getId().$suffix; }
    private function show(string $query=''): string { return $this->url('/'.$this->dossier->getId()).$query; }
    private function input(): array {return ['function'=>'Technicien','assignment'=>'Atelier','issuedAt'=>'2026-10-06','validUntil'=>'2027-10-06','signerFunction'=>'Responsable','place'=>'Alès','additionalDocument'=>'Aucun','rows'=>['row_0'=>['verdict'=>'favorable','symbols'=>['B0'],'voltage'=>['BT'],'works'=>'Atelier principal','details'=>'Sans travaux sous tension']]];}
    private function signature(): string { $im=imagecreatetruecolor(100,40);imagesetthickness($im,2);imageline($im,5,5,95,35,imagecolorallocate($im,240,240,240));ob_start();imagepng($im);$s=ob_get_clean();imagedestroy($im);return 'data:image/png;base64,'.base64_encode($s); }
    private function sign(string $stage): HabilitationRevision { $r=self::getContainer()->get(HabilitationWorkflow::class)->save($this->dossier,$stage,$this->input(),true,$stage==='avis'?$this->trainer:$this->employer,$this->signature());$this->em->persist($r);$this->em->flush();return $r; }
    public function testAdminPagesAndTemplateSave(): void {
        $this->client->loginUser($this->admin);
        foreach(['','/modeles','/modeles/nouveau','/affecter','/'.$this->dossier->getId(),'/'.$this->dossier->getId().'/reaffecter'] as $path){$this->client->request('GET',$this->url($path));self::assertSame(200,$this->client->getResponse()->getStatusCode());}
        $p=$this->client->request('GET',$this->url('/modeles/nouveau'));if(getenv('HAB_PREVIEW_DIR'))file_put_contents(getenv('HAB_PREVIEW_DIR').'/model.html',$this->client->getResponse()->getContent());$form=$p->filter('#hab-builder')->form();$form['titre']='Nouveau modèle';$this->client->submit($form);self::assertSame(302,$this->client->getResponse()->getStatusCode());self::assertCount(2,$this->em->getRepository(HabilitationTemplate::class)->findAll());
    }
    public function testFullHttpSignatureFlowAndDownload(): void {
        foreach ([['avis',$this->trainer],['titre',$this->employer]] as [$stage,$actor]) {
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
        $d=$this->em->getRepository(HabilitationDossier::class)->find($this->dossier->getId());self::assertNull($d->getAvisToken());self::assertNull($d->getTitreToken());self::assertSame([],$d->getTrainerData());self::assertCount(3,$this->em->getRepository(HabilitationRevision::class)->findBy(['dossier'=>$d]));self::assertNotEmpty($avis->getSnapshot()['signature']);
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

}
