<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite,Utilisateur,UtilisateurEntite};
use App\Entity\Elearning\{ElearningCourse,ElearningNode,ElearningBlock,ElearningEnrollment};
use App\Enum\EnrollmentStatus;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ElearningSecurityTest extends KernelTestCase
{
    private array $database; private $em; private $client; private array $ids=[]; private string $file;
    protected function setUp(): void {
        $this->database=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        self::bootKernel();$this->em=self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $objects=[];
        foreach (['admin','trainer','learner','other'] as $key) $objects[$key]=(new Utilisateur())->setEmail($key.'@security.test')->setPassword('unused')->setPrenom('Test')->setNom($key);
        $objects['tenant']=(new Entite())->setNom('A')->setCreateur($objects['admin'])->setPublic(false);
        $objects['foreignTenant']=(new Entite())->setNom('B')->setCreateur($objects['admin'])->setPublic(false);
        foreach ($objects as $o) $this->em->persist($o);$this->em->flush();
        foreach (['admin'=>'TENANT_ADMIN','trainer'=>'TENANT_FORMATEUR','learner'=>'TENANT_STAGIAIRE','other'=>'TENANT_FORMATEUR'] as $key=>$role) {
            $objects[$key]->setEntite($objects['tenant']);
            $this->em->persist((new UtilisateurEntite())->setEntite($objects['tenant'])->setUtilisateur($objects[$key])->setCreateur($objects['admin'])->setRoles([$role]));
        }
        // Two trainers are needed to verify course-owner isolation.
        $plan = (new \App\Entity\Billing\Plan())->setCode('ELEARNING_SECURITY')->setName('Course ownership fixture')->setMaxApprenantsAn(50)->setMaxUtilisateurs(1)->setMaxFormateurs(2)->setMaxEntreprises(1)->setMaxProspects(50);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($objects['tenant'])->setStatus('active')->setPlan($plan));
        $objects['course']=(new ElearningCourse())->setEntite($objects['tenant'])->setCreateur($objects['trainer'])->setTitre('Sécurité')->setSlug('security');
        $objects['foreignCourse']=(new ElearningCourse())->setEntite($objects['foreignTenant'])->setCreateur($objects['admin'])->setTitre('Cours confidentiel')->setSlug('foreign');
        $objects['node']=(new ElearningNode())->setEntite($objects['tenant'])->setCreateur($objects['trainer'])->setCourse($objects['course'])->setTitre('Chapitre')->setSlug('chapter')->setIsPublished(true);
        $objects['child']=(new ElearningNode())->setEntite($objects['tenant'])->setCreateur($objects['trainer'])->setCourse($objects['course'])->setParent($objects['node'])->setTitre('Sous-chapitre')->setSlug('child')->setIsPublished(true);
        $objects['foreignNode']=(new ElearningNode())->setEntite($objects['foreignTenant'])->setCreateur($objects['admin'])->setCourse($objects['foreignCourse'])->setTitre('Confidentiel')->setSlug('foreign');
        $objects['block']=(new ElearningBlock())->setEntite($objects['tenant'])->setCreateur($objects['trainer'])->setNode($objects['node'])->setContent('<p>Texte autorisé</p><script>SECURITY_ATTACK_SENTINEL</script><img src=x onerror="SECURITY_ATTACK_SENTINEL">');
        $objects['foreignBlock']=(new ElearningBlock())->setEntite($objects['foreignTenant'])->setCreateur($objects['admin'])->setNode($objects['foreignNode'])->setContent('Confidentiel');
        $objects['enrollment']=(new ElearningEnrollment())->setEntite($objects['tenant'])->setCreateur($objects['admin'])->setCourse($objects['course'])->setStagiaire($objects['learner']);
        $name='audit-'.bin2hex(random_bytes(6)).'.txt';$this->file=self::getContainer()->getParameter('kernel.project_dir').'/public/uploads/elearning/'.$name;
        if(!is_dir(dirname($this->file)))mkdir(dirname($this->file),0775,true);file_put_contents($this->file,'Confidential course fixture');
        $objects['block']->setMediaFilename($name);
        foreach ($objects as $o) $this->em->persist($o);$this->em->flush();
        foreach ($objects as $key=>$o) $this->ids[$key]=$o->getId();$this->em->clear();
        $this->client=self::getContainer()->get('test.client');$this->client->disableReboot();$this->client->catchExceptions(true);
    }
    protected function tearDown(): void {
        if(isset($this->file)&&is_file($this->file))unlink($this->file);
        parent::tearDown();foreach(['ENV','SERVER'] as $i=>$n){if($this->database[$i]===null)unset($GLOBALS['_'.$n]['DATABASE_URL']);else $GLOBALS['_'.$n]['DATABASE_URL']=$this->database[$i];}
    }
    private function login(string $key): void {$this->client->loginUser($this->em->find(Utilisateur::class,$this->ids[$key]));}
    private function base(string $course='course'): string {return '/fr/administrateur/'.$this->ids['tenant'].'/elearning/'.$this->ids[$course];}
    private function request(string $method,string $url,array $data=[],array $headers=[],?string $content=null): int {
        $this->client->request($method,$url,$data,[],$headers,$content);return $this->client->getResponse()->getStatusCode();
    }
    public function testForeignNestedResourcesAndCourseEnrollmentAreRejectedBeforeRendering(): void {
        $this->login('admin');
        foreach (['/contenu/node/'.$this->ids['foreignNode'].'/modifier','/contenu/block/'.$this->ids['foreignBlock'].'/modifier'] as $path) {
            self::assertSame(404,$this->request('GET',$this->base().$path));
            self::assertSame(404,$this->request('POST',$this->base().$path));
        }
        foreach (['/inscriptions','/inscriptions/attribuer'] as $path) self::assertSame(404,$this->request('GET',$this->base('foreignCourse').$path));
        $this->login('other');self::assertSame(403,$this->request('GET',$this->base().'/contenu'));
        $this->login('trainer');self::assertSame(200,$this->request('GET',$this->base().'/contenu'));
    }
    public function testLegacyReordersRequireCsrfAndRejectCyclesWithoutMutation(): void {
        $this->login('trainer');$base=$this->base().'/contenu';
        $this->client->request('GET',$base.'/node/'.$this->ids['node'].'/modifier');
        $page=$this->client->getCrawler();$token=$page->filter('#tree-card')->attr('data-csrf');
        $cycle=[['id'=>$this->ids['node'],'position'=>4,'parentId'=>$this->ids['child']]];
        self::assertSame(403,$this->request('POST',$base.'/node/reorder',[],['CONTENT_TYPE'=>'application/json'],json_encode($cycle)));
        self::assertSame(400,$this->request('POST',$base.'/node/reorder',[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],json_encode($cycle)));
        $this->em->clear();self::assertNull($this->em->find(ElearningNode::class,$this->ids['node'])->getParent());
        self::assertSame(403,$this->request('POST',$base.'/block/reorder',[],['CONTENT_TYPE'=>'application/json'],json_encode([['id'=>$this->ids['block'],'position'=>4]])));
        $valid=[['id'=>$this->ids['child'],'position'=>1,'parentId'=>null]];
        self::assertSame(200,$this->request('POST',$base.'/node/reorder',[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],json_encode($valid)));
        $this->em->clear();self::assertNull($this->em->find(ElearningNode::class,$this->ids['child'])->getParent());
    }
    public function testEnrollmentDateChangesRequireCsrf(): void {
        $this->login('admin');$url=$this->base().'/inscriptions';$this->client->request('GET',$url);
        $token=$this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $data=['id'=>$this->ids['enrollment'],'endsAt'=>'2026-01-01 12:00'];
        self::assertSame(403,$this->request('POST',$url.'/update-dates',$data));
        self::assertSame(302,$this->request('POST',$url.'/update-dates',$data+['_token'=>$token]));
        $this->em->clear();self::assertNotNull($this->em->find(ElearningEnrollment::class,$this->ids['enrollment'])->getEndsAt());
    }
    public function testSuspendedExpiredAndFutureEnrollmentsCannotReadOrChangeProgress(): void {
        $this->login('learner');$url='/fr/stagiaire/'.$this->ids['tenant'].'/elearning/course/'.$this->ids['course'];
        foreach ([EnrollmentStatus::SUSPENDED,EnrollmentStatus::EXPIRED,EnrollmentStatus::UPCOMING] as $status) {
            $this->em->find(ElearningEnrollment::class,$this->ids['enrollment'])->setStatus($status);$this->em->flush();
            self::assertSame(403,$this->request('GET',$url));
            self::assertSame(403,$this->request('POST',$url.'/node/'.$this->ids['node'].'/toggle-complete'));
        }
        $this->em->find(ElearningEnrollment::class,$this->ids['enrollment'])->setStatus(EnrollmentStatus::ACTIVE)->setEndsAt(new \DateTimeImmutable('-1 day'));$this->em->flush();
        self::assertSame(403,$this->request('GET',$url));
    }
    public function testAuthorizedCourseHtmlIsSanitized(): void {
        $this->login('learner');self::assertSame(200,$this->request('GET','/fr/stagiaire/'.$this->ids['tenant'].'/elearning/course/'.$this->ids['course']));
        self::assertStringNotContainsString('SECURITY_ATTACK_SENTINEL',$this->client->getResponse()->getContent());
        self::assertStringContainsString('Texte autorisé',$this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store',$this->client->getResponse()->headers->get('Cache-Control'));
    }
    public function testOriginalMediaRequiresAnAuthorizedCurrentAccess(): void {
        $url='/uploads/elearning/'.basename($this->file);
        self::assertSame(404,$this->request('GET',$url));
        $this->login('other');self::assertSame(404,$this->request('GET',$url));
        $this->login('trainer');self::assertSame(200,$this->request('GET',$url));
        $this->login('learner');self::assertSame(200,$this->request('GET',$url));
        self::assertStringContainsString('no-store',$this->client->getResponse()->headers->get('Cache-Control'));
        $this->em->find(ElearningEnrollment::class,$this->ids['enrollment'])->setStatus(EnrollmentStatus::SUSPENDED);$this->em->flush();
        self::assertSame(404,$this->request('GET',$url));
        $this->login('admin');self::assertSame(200,$this->request('GET',$url));
    }
}
