<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Platform\{Revenue,Cost};
use App\Service\Platform\Overview;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformDashboardTest extends WebTestCase
{
    private array $oldDb;
    private $client;
    private $em;
    private Entite $a;
    private Entite $b;
    private Utilisateur $user;
    protected function setUp(): void
    {
        $this->oldDb=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        $this->client=self::createClient(); $this->client->disableReboot();
        $this->em=self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user=(new Utilisateur())->setEmail('finance@test.local')->setPassword('unused')->setPrenom('Finance')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->a=(new Entite())->setNom('Alpha')->setCreateur($this->user)->setPublic(false);
        $this->b=(new Entite())->setNom('Beta')->setCreateur($this->user)->setPublic(false);
        foreach ([$this->user,$this->a,$this->b] as $e) $this->em->persist($e);
        $this->em->flush(); $this->user->setEntite($this->a); $this->em->flush();
        $this->em->persist((new \App\Entity\UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->a)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('CREATE TABLE notification_receipt (entite_id INTEGER, utilisateur_id INTEGER, item_key VARCHAR(190), read_at DATETIME, archived INTEGER DEFAULT 0, PRIMARY KEY(entite_id, utilisateur_id, item_key))');
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$name) {
            if ($this->oldDb[$i]===null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL']=$this->oldDb[$i];
        }
    }
    private function revenue(string $id,Entite $e,int $paid,bool $live=true): Revenue
    {
        $r=new Revenue(); $r->id=$id; $r->entite=$e; $r->live=$live;
        $r->paidAt=new \DateTimeImmutable('2026-10-10'); $r->syncedAt=$r->paidAt;
        $r->paid=$r->ttc=$paid; $r->ht=(int)round($paid/1.2); $r->fee=100; $r->label='=unsafe spreadsheet';
        $this->em->persist($r); return $r;
    }
    public function testAccountBreakdownReconcilesOtherRolesAndOverlaps(): void
    {
        $membership = $this->em->getRepository(\App\Entity\UtilisateurEntite::class)->findOneBy(['utilisateur'=>$this->user, 'entite'=>$this->a]);
        foreach ([
            [['TENANT_ADMIN', 'TENANT_FORMATEUR', 'TENANT_STAGIAIRE'], 0, 2],
            [['TENANT_ENTREPRISE'], 1, 0],
        ] as [$roles, $others, $overlaps]) {
            $membership->setRoles($roles);
            $this->em->flush();
            $data = self::getContainer()->get(Overview::class)->build('2026-10', '2026-10');
            $row = array_values(array_filter($data['roster'], fn ($r) => $r['id'] === $this->a->getId()))[0];
            self::assertSame($others, $row['others']);
            self::assertSame($overlaps, $row['roleOverlaps']);
            self::assertSame($row['accounts'], $row['admins'] + $row['trainers'] + $row['learners'] + $row['others'] - $row['roleOverlaps']);
        }
    }

    public function testTotalsFiltersAndUnknownAmounts(): void
    {
        $this->revenue('in_alpha',$this->a,12000);
        $unknown=$this->revenue('in_beta',$this->b,6000); $unknown->ht=null; $unknown->fee=null;
        $this->revenue('in_test',$this->a,999999,false);
        $foreign=$this->revenue('in_usd',$this->a,999999); $foreign->currency='usd';
        $cost=new Cost(); $cost->author=$this->user; $cost->date=new \DateTimeImmutable('2026-10-01'); $cost->label='Hébergement'; $cost->ht=1000; $cost->ttc=1200; $this->em->persist($cost);
        $estimated=clone $cost; $estimated->estimated=true; $estimated->ttc=10000; $this->em->persist($estimated);
        $this->em->flush();
        $service=self::getContainer()->get(Overview::class);
        $all=$service->build('2026-10','2026-10');
        self::assertSame(18000,$all['totals']['ttc']); self::assertSame(10000,$all['totals']['ht']);
        self::assertSame(1,$all['totals']['missingHt']); self::assertSame(1,$all['totals']['missingFees']);
        self::assertSame(16700,$all['contribution']); self::assertSame(2,$all['excluded']);
        self::assertSame(12000,$service->build('2026-10','2026-10',$this->a->getId())['totals']['ttc']);
        self::assertSame(0,$service->build('2026-09','2026-09')['totals']['count']);
        self::assertSame(0,$service->build('2026-10','2026-10',0,'courses')['totals']['count']);
        self::assertSame(0,$service->build('2026-10','2026-10',$this->a->getId())['costTtc'],'Common costs must not be allocated to a tenant arbitrarily.');
    }
    public function testPageCsvAndAuthorization(): void
    {
        $this->revenue('in_page',$this->a,12000); $this->em->flush();
        $url='/fr/super/'.$this->a->getId().'/pilotage';
        $this->client->loginUser($this->user);
        $this->client->request('GET',$url.'?from=2026-10&to=2026-10');
        self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h1','Une vision claire');
        self::assertSelectorExists('#pf-entity');
        if (getenv('PLATFORM_REVIEW_HTML')) file_put_contents(getenv('PLATFORM_REVIEW_HTML'), $this->client->getResponse()->getContent());
        $this->client->request('GET',$url.'?from=2026-10&to=2026-10&format=csv');
        self::assertResponseIsSuccessful();
        $csv=$this->client->getInternalResponse()->getContent();
        self::assertStringContainsString("'=unsafe spreadsheet",$csv);
        $this->client->request('POST',$url.'/cout',['_token'=>'bad']);
        self::assertResponseStatusCodeSame(403);
        $ordinary=(new Utilisateur())->setEmail('ordinary@test.local')->setPassword('unused')->setPrenom('Ordinary')->setNom('Test')->setRoles(['ROLE_USER'])->setEntite($this->em->find(Entite::class,$this->a->getId()));
        $this->em->persist($ordinary); $this->em->flush();
        $this->client->loginUser($ordinary);
        $this->client->request('GET',$url); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST',$url.'/synchroniser'); self::assertResponseStatusCodeSame(403);
    }
    public function testCostEntryScopeAndArchival(): void
    {
        $url='/fr/super/'.$this->a->getId().'/pilotage';
        $this->client->loginUser($this->user);
        $page=$this->client->request('GET',$url);
        $token=$page->filter('form[action$="/cout"] input[name="_token"]')->attr('value');
        $this->client->request('POST',$url.'/cout',[
            '_token'=>$token,'label'=>'SuperPDP octobre','reference'=>'F-2026-10',
            'category'=>'superpdp','activity'=>'subscriptions','date'=>'2026-10-15',
            'ht'=>'12,50','ttc'=>'15,00','entity'=>$this->a->getId(),
        ]);
        self::assertResponseRedirects();
        $cost=$this->em->getRepository(Cost::class)->findOneBy(['reference'=>'F-2026-10']);
        self::assertNotNull($cost); self::assertSame(1250,$cost->ht);
        $overview=self::getContainer()->get(Overview::class);
        self::assertSame(1500,$overview->build('2026-10','2026-10',0,'subscriptions')['costTtc']);
        self::assertSame(0,$overview->build('2026-10','2026-10',0,'courses')['costTtc']);
        self::assertSame(0,$overview->build('2026-10','2026-10',$this->b->getId())['costTtc']);
        $page=$this->client->request('GET',$url.'?from=2026-10&to=2026-10');
        $form=$page->filter('form[action$="/archiver"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(0,$overview->build('2026-10','2026-10')['costTtc']);
        self::assertSame(1,$this->em->getRepository(Cost::class)->count(['archived'=>true]));
    }

    public function testInvoiceRecorderIsIdempotentAndRejectsForeignCustomer(): void
    {
        $sub=(new \App\Entity\Billing\EntiteSubscription())->setEntite($this->a)->setStripeCustomerId('cus_test')->setStripeSubscriptionId('sub_test');
        $this->em->persist($sub); $this->em->flush();
        $service=self::getContainer()->get(\App\Service\Platform\StripeRevenue::class);
        $invoice=['id'=>'in_record','status'=>'paid','customer'=>'cus_test','subscription'=>'sub_test','currency'=>'eur','livemode'=>true,
            'status_transitions'=>['paid_at'=>1791597600],'total_excluding_tax'=>10000,'total'=>12000,'amount_paid'=>12000];
        $service->record($invoice); $this->em->flush();
        $r=$service->record($invoice,[['id'=>'ch_test','amount_refunded'=>2000,'balance_transaction'=>['source'=>'ch_test','currency'=>'eur','fee'=>125]]]);
        $this->em->flush();
        self::assertSame(1,$this->em->getRepository(Revenue::class)->count([]));
        self::assertSame(2000,$r->refund); self::assertSame(125,$r->fee); self::assertNull($r->ht);
        $invoice['id']='in_wrong'; $invoice['customer']='cus_wrong';
        self::assertNull($service->record($invoice));
    }
}
