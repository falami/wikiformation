<?php

declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite, Utilisateur, UtilisateurEntite, BpfDeclaration, Session, SessionJour, Facture, AuditLog};
use App\Service\Bpf\{BpfCalculator, BpfSchema};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExpenseImportRulesTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private Entite $entite;
    private Utilisateur $user;
    private string $url;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('bpf@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin')->setRoles(['ROLE_USER']);
        $this->entite = (new Entite())->setNom('Organisme de démonstration')->setPublic(false)->setCreateur($this->user)->setSiret('12345678900012')->setNumeroDeclarant('12345678901')->setFormeJuridique('SAS')->setEmail('contact@example.test');
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->user);
        $this->url = '/fr/administrateur/'.$this->entite->getId().'/bpf?start=2025-07-01&end=2026-06-30';
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']); else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testRuleCrudMatchingAndIsolation(): void {
        $cat=(new \App\Entity\DepenseCategorie())->setEntite($this->entite)->setLibelle('Autoroute');
        $supplier=(new \App\Entity\DepenseFournisseur())->setEntite($this->entite)->setNom('Vinci');
        $this->em->persist($cat);$this->em->persist($supplier);$this->em->flush();
        $url='/fr/administrateur/'.$this->entite->getId().'/depense/regles-import';
        $page=$this->client->request('GET',$url);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        $token=$page->filter('input[name="_token"]')->first()->attr('value');
        $choiceUrl=$url.'/classement/supplier';
        $this->client->request('POST',$choiceUrl,['_token'=>$token,'name'=>'Nouveau partenaire']);
        self::assertSame(201,$this->client->getResponse()->getStatusCode());
        $created=json_decode($this->client->getResponse()->getContent(),true);
        $this->client->request('POST',$choiceUrl,['_token'=>$token,'name'=>'Nouveau partenaire']);
        self::assertSame(200,$this->client->getResponse()->getStatusCode());
        self::assertSame($created['id'],json_decode($this->client->getResponse()->getContent(),true)['id']);
        $this->client->request('POST',$url.'/classement/category',['_token'=>$token,'name'=>'Nouvelle catégorie']);
        self::assertSame(201,$this->client->getResponse()->getStatusCode());
        $this->client->request('POST',$choiceUrl,['_token'=>$token,'name'=>'']);
        self::assertSame(422,$this->client->getResponse()->getStatusCode());
        $payload=['_token'=>$token,'keyword'=>'autoroute','category'=>$cat->getId(),'supplier'=>$supplier->getId(),'priority'=>'10','percentage'=>'65.5','enabled'=>'1','deductible'=>'1'];
        $this->client->request('POST',$url,$payload);
        self::assertSame(302,$this->client->getResponse()->getStatusCode());
        $rule=$this->em->getRepository(\App\Entity\ExpenseImportRule::class)->findOneBy([]);
        $engine=self::getContainer()->get(\App\Service\Depense\ExpenseImportRules::class);
        $rows=[['libelle'=>'Prélèvement AUTOROUTES du sud Vinci','categorieId'=>null],['libelle'=>'Autre achat','categorieId'=>null]];
        $result=$engine->apply($this->entite,$rows);
        self::assertSame($cat->getId(),$result[0]['categorieId']);self::assertSame(65.5,$result[0]['tvaDeductiblePct']);self::assertNull($result[1]['categorieId']);
        self::assertSame('electricite',\App\Service\Depense\ExpenseImportRules::normalize('ÉLECTRICITÉ'));
        $other=(new Entite())->setNom('Autre')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class,$this->user->getId()));$this->em->persist($other);$this->em->flush();
        self::assertSame($rows,$engine->apply($other,$rows));
        $payload['id']=$rule->id;unset($payload['enabled']);
        $this->client->request('POST',$url,$payload);
        self::assertSame($rows,$engine->apply($this->entite,$rows));
        $this->client->request('POST',$url,['_token'=>$token,'id'=>$rule->id,'action'=>'delete']);
        self::assertSame(0,$this->em->getRepository(\App\Entity\ExpenseImportRule::class)->count([]));
    }
}
