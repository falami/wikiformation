<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite,Formateur,TrainerCharter,TrainerCharterVersion,TrainerCharterSignature};
use App\Entity\Elearning\{ElearningCourse,ElearningNode,ElearningBlock};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class CourseBuilderTest extends KernelTestCase
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
    public function testOrderingAndStaleOrInvalidRequests(): void {
        $e=$this->em->find(Entite::class,$this->entiteId);$u=$this->em->find(Utilisateur::class,$this->adminId);
        $course=(new ElearningCourse())->setEntite($e)->setCreateur($u)->setTitre('Excel - Niveau 1')->setSlug('excel');$this->em->persist($course);
        $nodes=[];
        foreach (['Introduction','Les tableaux'] as $i=>$title) {$n=(new ElearningNode())->setEntite($e)->setCreateur($u)->setCourse($course)->setTitre($title)->setSlug('chapitre-'.$i)->setPosition($i);$this->em->persist($n);$nodes[]=$n;}
        $block=(new ElearningBlock())->setEntite($e)->setCreateur($u)->setNode($nodes[0])->setContent('Découvrez les fondamentaux et les outils essentiels.');$this->em->persist($block);$this->em->flush();$courseId=$course->getId();$nodeId=$nodes[0]->getId();$blockId=$block->getId();$ids=array_map(fn($n)=>(string)$n->getId(),$nodes);$this->em->clear();
        $url='/fr/administrateur/'.$this->entiteId.'/elearning/'.$courseId.'/contenu';$page=$this->client->request('GET',$url);self::assertSame(200,$this->client->getResponse()->getStatusCode());
        if(getenv('COURSE_PREVIEW'))file_put_contents(getenv('COURSE_PREVIEW'),$this->client->getResponse()->getContent());
        $token=$page->filter('#courseBuilder')->attr('data-token');$data=['_token'=>$token,'kind'=>'nodes','parentId'=>'0','ids'=>array_reverse($ids),'previous'=>$ids];
        $this->client->request('POST',$url.'/ordonner',$data);self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $this->em->clear();self::assertSame(1,$this->em->find(ElearningNode::class,$nodeId)->getPosition());
        $this->client->request('POST',$url.'/ordonner',$data);self::assertSame(409,$this->client->getResponse()->getStatusCode());
        $data['previous']=array_reverse($ids);$data['ids']=[$ids[0],$ids[0]];$this->client->request('POST',$url.'/ordonner',$data);self::assertSame(400,$this->client->getResponse()->getStatusCode());
        $data['ids']=['9999',$ids[0]];$this->client->request('POST',$url.'/ordonner',$data);self::assertSame(409,$this->client->getResponse()->getStatusCode());
        $data['ids']=$ids;$data['_token']='wrong';$this->client->request('POST',$url.'/ordonner',$data);self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$url.'/ordonner',['_token'=>$token,'kind'=>'blocks','parentId'=>(string)$nodeId,'ids'=>[(string)$blockId],'previous'=>[(string)$blockId]]);self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $other=(new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->adminId));$this->em->persist($other);$this->em->flush();$course=$this->em->find(ElearningCourse::class,$courseId);$course->setEntite($other);$this->em->flush();
        $this->client->catchExceptions(true);$this->client->request('GET',$url);self::assertSame(404,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$url.'/ordonner',$data);self::assertSame(404,$this->client->getResponse()->getStatusCode());
    }
}
