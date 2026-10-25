<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{ContentBlock, Entite, Formation, FormationContentNode, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminResourceSecurityTest extends KernelTestCase
{
    private array $database; private $em; private $client; private array $ids=[];
    protected function setUp(): void
    {
        $this->database=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        self::bootKernel();$this->em=self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $objects['admin']=(new Utilisateur())->setEmail('admin@scope.test')->setPassword('unused')->setPrenom('Test')->setNom('Admin');
        foreach (['tenant','foreignTenant'] as $key) $objects[$key]=(new Entite())->setNom($key)->setCreateur($objects['admin'])->setPublic(false);
        foreach ($objects as $o) $this->em->persist($o);$this->em->flush();
        $objects['admin']->setEntite($objects['tenant']);
        $this->em->persist((new UtilisateurEntite())->setEntite($objects['tenant'])->setUtilisateur($objects['admin'])->setCreateur($objects['admin'])->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($objects['tenant'])->setStatus('active'));
        foreach (['own'=>'tenant','other'=>'tenant','foreign'=>'foreignTenant'] as $prefix=>$tenant) {
            $objects[$prefix.'Site']=(new Site())->setNom($prefix)->setSlug($prefix)->setEntite($objects[$tenant])->setCreateur($objects['admin']);
            $objects[$prefix.'Formation']=(new Formation())->setTitre($prefix)->setSlug($prefix)->setEntite($objects[$tenant])->setCreateur($objects['admin']);
            $objects[$prefix.'Node']=(new FormationContentNode())->setTitre($prefix)->setSlug($prefix)->setFormation($objects[$prefix.'Formation'])->setEntite($objects[$tenant])->setCreateur($objects['admin']);
            $objects[$prefix.'Block']=(new ContentBlock())->setNode($objects[$prefix.'Node'])->setContent('Fixture')->setEntite($objects[$tenant])->setCreateur($objects['admin']);
        }
        $objects['child']=(new FormationContentNode())->setTitre('Child')->setSlug('child')->setFormation($objects['ownFormation'])->setParent($objects['ownNode'])->setEntite($objects['tenant'])->setCreateur($objects['admin']);
        foreach ($objects as $o) $this->em->persist($o);$this->em->flush();
        foreach ($objects as $key=>$o) $this->ids[$key]=$o->getId();$this->em->clear();
        $this->client=self::getContainer()->get('test.client');$this->client->disableReboot();$this->client->catchExceptions(true);
        $this->client->loginUser($this->em->find(Utilisateur::class,$this->ids['admin']));
    }
    protected function tearDown(): void
    {
        parent::tearDown();foreach(['ENV','SERVER'] as $i=>$n){if($this->database[$i]===null)unset($GLOBALS['_'.$n]['DATABASE_URL']);else $GLOBALS['_'.$n]['DATABASE_URL']=$this->database[$i];}
    }
    private function base(): string {return '/fr/administrateur/'.$this->ids['tenant'];}
    private function content(): string {return $this->base().'/formation/'.$this->ids['ownFormation'].'/contenu';}
    private function request(string $method,string $url,array $data=[],array $headers=[],?string $content=null): int
    {
        $this->client->request($method,$url,$data,[],$headers,$content);return $this->client->getResponse()->getStatusCode();
    }
    private function token(string $id): string
    {
        $stack=self::getContainer()->get('request_stack');$stack->push($this->client->getRequest());
        try{return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();}
        finally{$this->client->getRequest()->getSession()->save();$stack->pop();}
    }
    public function testTenantGuardProtectsReadAndWriteWithoutBlockingOwnForms(): void
    {
        self::assertSame(200,$this->request('GET',$this->base().'/site/modifier/'.$this->ids['ownSite']));
        self::assertSame(200,$this->request('GET',$this->base().'/site/ajouter'));
        self::assertSame(200,$this->request('GET',$this->content().'/node/'.$this->ids['ownNode'].'/modifier'));
        foreach (['GET','POST'] as $method) {
            self::assertSame(404,$this->request($method,$this->base().'/site/modifier/'.$this->ids['foreignSite']));
            self::assertSame(404,$this->request($method,$this->base().'/formation/'.$this->ids['foreignFormation'].'/contenu/node/'.$this->ids['foreignNode'].'/modifier'));
            foreach (['other','foreign'] as $prefix) {
                self::assertSame(404,$this->request($method,$this->content().'/node/'.$this->ids[$prefix.'Node'].'/modifier'));
                self::assertSame(404,$this->request($method,$this->content().'/block/'.$this->ids[$prefix.'Block'].'/modifier'));
            }
        }
    }
    public function testSiteDeletionRequiresPostAndCsrfAndCannotCrossTenant(): void
    {
        $url=$this->base().'/site/supprimer/'.$this->ids['ownSite'];
        self::assertSame(405,$this->request('GET',$url));
        self::assertSame(403,$this->request('POST',$url));
        self::assertSame(404,$this->request('POST',$this->base().'/site/supprimer/'.$this->ids['foreignSite']));
        self::assertNotNull($this->em->find(Site::class,$this->ids['ownSite']));
        $this->request('GET',$this->base().'/site');$token=$this->token('delete_site_'.$this->ids['ownSite']);
        self::assertSame(302,$this->request('POST',$url,['_token'=>$token]));
        $this->em->clear();self::assertNull($this->em->find(Site::class,$this->ids['ownSite']));self::assertNotNull($this->em->find(Site::class,$this->ids['foreignSite']));
    }
    public function testContentDeletionRequiresCsrfAndScopesBothNodeAndFormation(): void
    {
        $this->request('GET',$this->content());
        $token=$this->token('del_block_'.$this->ids['ownBlock']);
        self::assertSame(403,$this->request('POST',$this->content().'/block/'.$this->ids['ownBlock'].'/supprimer'));
        self::assertSame(403,$this->request('POST',$this->content().'/node/'.$this->ids['ownNode'].'/supprimer'));
        self::assertSame(404,$this->request('POST',$this->content().'/node/'.$this->ids['otherNode'].'/supprimer'));
        self::assertSame(404,$this->request('POST',$this->content().'/block/'.$this->ids['foreignBlock'].'/supprimer'));
        self::assertSame(302,$this->request('POST',$this->content().'/block/'.$this->ids['ownBlock'].'/supprimer',['_token'=>$token]));
        $this->em->clear();self::assertNull($this->em->find(ContentBlock::class,$this->ids['ownBlock']));self::assertNotNull($this->em->find(ContentBlock::class,$this->ids['foreignBlock']));
    }
    public function testBothReorderRoutesRequireCsrfAndRejectCyclesAndForeignIdsAtomically(): void
    {
        $this->request('GET',$this->content());$token=$this->token('reorder_nodes_'.$this->ids['ownFormation']);
        $routes=[$this->content().'/node/reorder','/fr/admin/'.$this->ids['tenant'].'/'.$this->ids['ownFormation'].'/content/reorder'];
        $cycle=[['id'=>$this->ids['ownNode'],'position'=>3,'parentId'=>$this->ids['child']]];
        $foreign=[['id'=>$this->ids['ownNode'],'position'=>3,'parentId'=>null],['id'=>$this->ids['otherNode'],'position'=>4,'parentId'=>null]];
        foreach ($routes as $route) {
            self::assertSame(403,$this->request('POST',$route,[],['CONTENT_TYPE'=>'application/json'],json_encode($cycle)));
            foreach ([$cycle,$foreign] as $invalid) self::assertSame(400,$this->request('POST',$route,[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],json_encode($invalid)));
        }
        $this->em->clear();$node=$this->em->find(FormationContentNode::class,$this->ids['ownNode']);self::assertNull($node->getParent());self::assertSame(0,$node->getPosition());
        self::assertSame(200,$this->request('POST',$routes[0],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],json_encode([['id'=>$this->ids['child'],'position'=>1,'parentId'=>null]])));
        $this->em->clear();self::assertNull($this->em->find(FormationContentNode::class,$this->ids['child'])->getParent());
    }
}
