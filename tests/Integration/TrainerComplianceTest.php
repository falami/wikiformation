<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite,Formateur,TrainerCharter,TrainerCharterVersion,TrainerCharterSignature};
use App\Service\TrainerCharter\CharterManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class TrainerComplianceTest extends KernelTestCase
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
    public function testPendingDocumentsCooldownAndTenantIsolation(): void {
        $service=self::getContainer()->get(\App\Service\TrainerReminder\TrainerCompliance::class);
        $entite=$this->em->find(Entite::class,$this->entiteId);
        $key='diploma:'.$this->trainerId;
        $rows=$service->pending($entite);
        $this->client->request('GET','/fr/administrateur/'.$this->entiteId.'/dashboard');
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Les documents de vos formateurs',$this->client->getResponse()->getContent());
        self::assertCount(1,$rows);self::assertTrue((bool)$rows[$key]['available']);
        $version=$this->published();$id=$this->requestSignature($version);
        self::assertArrayHasKey('charter:'.$id,$service->pending($entite));
        $signature=$this->em->find(TrainerCharterSignature::class,$id);$signature->signedAt=new \DateTimeImmutable();$this->em->flush();
        self::assertArrayNotHasKey('charter:'.$id,$service->pending($entite));
        $db=$this->em->getConnection();
        $db->insert('trainer_reminder_delivery',['entite_id'=>$this->entiteId,'scope_key'=>'manual:'.$key,'sequence_number'=>1,'kind'=>'manual','recipient'=>'trainer@example.test','subject'=>'Test','status'=>'sent','created_at'=>gmdate('Y-m-d H:i:s'),'sent_at'=>gmdate('Y-m-d H:i:s')]);
        self::assertFalse((bool)$service->pending($entite)[$key]['available']);
        self::assertSame('skipped',$service->send($entite,$key));
        $page=$this->client->request('GET','/fr/administrateur/'.$this->entiteId.'/dashboard');
        self::assertCount(1,$page->filter('[data-reminder-all="tblTrainerCompliance"]'));
        $token=$page->filter('#trainer-compliance input[name="_token"]')->first()->attr('value');
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key,'_token'=>$token],[],['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame(['lastReminderAt'=>null,'sent'=>0,'skipped'=>1,'failed'=>0],json_decode($this->client->getResponse()->getContent(),true));

        $db->update('trainer_reminder_delivery',['created_at'=>gmdate('Y-m-d H:i:s',time()-90000)],['scope_key'=>'manual:'.$key]);
        self::assertTrue((bool)$service->pending($entite)[$key]['available']);
        $db->update('trainer_reminder_delivery',['status'=>'unknown'],['scope_key'=>'manual:'.$key]);
        self::assertFalse((bool)$service->pending($entite)[$key]['available']);
        $other=(new Entite())->setNom('Autre')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->adminId));$this->em->persist($other);$this->em->flush();
        self::assertSame([],$service->pending($other));self::assertSame('skipped',$service->send($other,$key));
        $this->client->catchExceptions(true);
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key,'_token'=>'invalid']);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $this->login($this->userId);
        $this->client->request('POST','/fr/administrateur/'.$this->entiteId.'/dashboard/relance-formateur',['key'=>$key]);
        self::assertSame(403,$this->client->getResponse()->getStatusCode());
        $html=self::getContainer()->get('twig')->render('emails/trainer_compliance.html.twig',$rows[$key]+['entite'=>$entite,'subject'=>'Test']);
        self::assertStringContainsString('Déposer mes justificatifs',$html);
    }
}
