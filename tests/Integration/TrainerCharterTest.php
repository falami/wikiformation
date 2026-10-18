<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite,Formateur,TrainerCharter,TrainerCharterVersion,TrainerCharterSignature};
use App\Service\TrainerCharter\CharterManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class TrainerCharterTest extends KernelTestCase
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
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($e)->setStatus('active'));
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
    public function testDeleteUnsignedCharterAndItsRequests(): void {
        $v=$this->published(); $this->requestSignature($v);
        $page=$this->client->request('GET',$this->base());
        $this->client->submit($page->selectButton('Supprimer la charte')->form());
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        self::assertSame(0,$this->em->getRepository(TrainerCharter::class)->count([]));
        self::assertSame(0,$this->em->getRepository(TrainerCharterVersion::class)->count([]));
        self::assertSame(0,$this->em->getRepository(TrainerCharterSignature::class)->count([]));
    }
    public function testDeleteProtectsSignedCharter(): void {
        $v=$this->published(); $id=$this->requestSignature($v);
        $signature=$this->em->find(TrainerCharterSignature::class,$id);
        $signature->signedAt=new \DateTimeImmutable(); $this->em->flush();
        $page=$this->client->request('GET',$this->base());
        $this->client->submit($page->selectButton('Supprimer la charte')->form());
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        self::assertSame(1,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trainer_charter_signature'));
        self::assertSame(1,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trainer_charter'));
    }
    public function testEditorPublicationAndImmutableVersion(): void {
        $page=$this->client->request('GET',$this->base());$form=$page->selectButton('Créer une charte')->form();$this->client->submit($form);
        $page=$this->client->followRedirect();self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $v=$this->em->getRepository(TrainerCharterVersion::class)->findOneBy([]);$id=$v->id;
        $form=$page->selectButton('Publier cette version')->form();$form['title']='Charte test';$form['effectiveAt']='2026-10-15';$form['chapters']=json_encode([['title'=>'Chapitre principal','body'=>'Texte <script>alert(1)</script>','children'=>[['title'=>'Détail','body'=>'Contenu']]]]);$this->client->submit($form);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());$v=$this->em->find(TrainerCharterVersion::class,$id);self::assertNotNull($v->publishedAt);self::assertSame($v->hash(),$v->digest);
        $this->client->catchExceptions(true);$this->client->request('GET',$this->base().'/'.$id.'/editer');self::assertSame(409,$this->client->getResponse()->getStatusCode());
    }
    public function testSignatureIsOwnedVersionBoundAndPdfIsRetained(): void {
        $v=$this->published();$versionId=$v->id;$id=$this->requestSignature($v);$this->requestSignature($this->em->find(TrainerCharterVersion::class,$versionId));self::assertSame(1,$this->em->getRepository(TrainerCharterSignature::class)->count([]));
        $this->login($this->userId);
        $page=$this->client->request('GET','/fr/formateur/'.$this->entiteId.'/dashboard');self::assertStringContainsString('Lire et signer',$this->client->getResponse()->getContent());
        $url='/fr/formateur/'.$this->entiteId.'/chartes/'.$id;$page=$this->client->request('GET',$url);self::assertSame(200,$this->client->getResponse()->getStatusCode());
        if(getenv('CHARTER_PREVIEW_DIR'))file_put_contents(getenv('CHARTER_PREVIEW_DIR').'/charter-preview.html',$this->client->getResponse()->getContent());
        $form=$page->selectButton('Signer la charte · version 1')->form();$form['signerName']='Camille Martin';$form['consent']->tick();$this->client->submit($form);self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $s=$this->em->find(TrainerCharterSignature::class,$id);self::assertNotNull($s->signedAt);$bytes=$s->pdfBytes();self::assertStringStartsWith('%PDF',$bytes);self::assertSame(hash('sha256',$bytes),$s->pdfDigest);self::assertSame($s->version->digest,$s->evidence['digest']);
        if(getenv('CHARTER_PREVIEW_DIR'))file_put_contents(getenv('CHARTER_PREVIEW_DIR').'/charter-preview.pdf',$bytes);
        $this->client->request('GET','/fr/charte/'.$this->entiteId.'/signature/'.$id.'/pdf');self::assertSame($bytes,$this->client->getResponse()->getContent());
        $this->client->catchExceptions(true);$this->client->submit($form);self::assertSame(409,$this->client->getResponse()->getStatusCode());
        $this->login($this->adminId);$this->client->request('GET',$this->base().'/'.$versionId.'/export');self::assertSame(200,$this->client->getResponse()->getStatusCode());self::assertStringStartsWith('PK',$this->client->getResponse()->getContent());
        $v=$this->em->find(TrainerCharterVersion::class,$versionId);$next=new TrainerCharterVersion();$next->charter=$v->charter;$next->number=2;$next->title=$v->title;$next->chapters=$v->chapters;$next->publishedAt=new \DateTimeImmutable();$next->effectiveAt=new \DateTimeImmutable();$next->digest=$next->hash();$this->em->persist($next);$this->em->flush();
        $page=$this->client->request('GET',$this->base().'/'.$next->id.'/suivi');self::assertStringContainsString('Ancienne version signée',$this->client->getResponse()->getContent());
        $this->login($this->userId);$this->client->request('GET','/fr/charte/'.$this->entiteId.'/signature/'.$id.'/pdf');self::assertSame($bytes,$this->client->getResponse()->getContent());
    }
    public function testCrossTenantAndOtherTrainerCannotReadOrSign(): void {
        $v=$this->published();$id=$this->requestSignature($v);$this->login($this->userId);$this->client->catchExceptions(true);
        $this->client->request('GET',$this->base());self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $s=$this->em->find(TrainerCharterSignature::class,$id);$admin=$this->em->find(Utilisateur::class,$this->adminId);$other=(new Entite())->setNom('Autre')->setPublic(false)->setCreateur($admin);$this->em->persist($other);$this->em->flush();
        $this->client->request('GET','/fr/charte/'.$other->getId().'/signature/'.$id.'/pdf');self::assertSame(404,$this->client->getResponse()->getStatusCode());
        $this->login($this->adminId);$this->client->request('GET','/fr/formateur/'.$this->entiteId.'/chartes/'.$id);self::assertContains($this->client->getResponse()->getStatusCode(),[403,404]);
    }
    public function testAnotherTrainerCannotAccessRequestAndReplacedVersionCannotBeSigned(): void {
        $v=$this->published();$id=$this->requestSignature($v);$versionId=$v->id;
        $e=$this->em->find(Entite::class,$this->entiteId);$admin=$this->em->find(Utilisateur::class,$this->adminId);
        $u=(new Utilisateur())->setEmail('other@example.test')->setPassword('unused')->setPrenom('Autre')->setNom('Formateur')->setRoles(['ROLE_USER'])->setEntite($e);
        $this->em->persist($u);$this->em->persist((new UtilisateurEntite())->setUtilisateur($u)->setEntite($e)->setCreateur($admin)->setRoles(['TENANT_FORMATEUR']));
        $t=(new Formateur())->setUtilisateur($u)->setEntite($e)->setCreateur($admin);$u->setFormateur($t);$this->em->persist($t);$this->em->flush();
        $this->login($u->getId());$this->client->catchExceptions(true);$url='/fr/formateur/'.$this->entiteId.'/chartes/'.$id;
        $this->client->request('GET',$url);self::assertSame(404,$this->client->getResponse()->getStatusCode());
        $this->client->request('GET','/fr/charte/'.$this->entiteId.'/signature/'.$id.'/pdf');self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->login($this->userId);$page=$this->client->request('GET',$url);$form=$page->selectButton('Signer la charte · version 1')->form();$form['signerName']='Camille Martin';$form['consent']->tick();
        $v=$this->em->find(TrainerCharterVersion::class,$versionId);$next=new TrainerCharterVersion();$next->charter=$v->charter;$next->number=2;$next->title=$v->title;$next->chapters=$v->chapters;$next->publishedAt=new \DateTimeImmutable();$next->digest=$next->hash();$this->em->persist($next);$this->em->flush();
        $this->client->submit($form);self::assertSame(409,$this->client->getResponse()->getStatusCode());self::assertNull($this->em->find(TrainerCharterSignature::class,$id)->signedAt);
    }
    public function testConsentAndDigestAreRequired(): void {
        $v=$this->published();$id=$this->requestSignature($v);$this->login($this->userId);$url='/fr/formateur/'.$this->entiteId.'/chartes/'.$id;$p=$this->client->request('GET',$url);$token=$p->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST',$url,['_token'=>$token,'signerName'=>'Camille Martin','digest'=>$v->digest]);self::assertSame(200,$this->client->getResponse()->getStatusCode());self::assertNull($this->em->find(TrainerCharterSignature::class,$id)->signedAt);
        $this->client->catchExceptions(true);$this->client->request('POST',$url,['_token'=>$token,'signerName'=>'Camille Martin','digest'=>'wrong','consent'=>1]);self::assertSame(409,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$url,['_token'=>'wrong','signerName'=>'Camille Martin','digest'=>$v->digest,'consent'=>1]);self::assertSame(403,$this->client->getResponse()->getStatusCode());
    }
}
