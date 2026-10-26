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
    public function testPriorityDisabledRulesAndZeroDeductibility(): void
    {
        $category = (new \App\Entity\DepenseCategorie())->setEntite($this->entite)->setLibelle('Transport');
        $supplier = (new \App\Entity\DepenseFournisseur())->setEntite($this->entite)->setNom('SNCF');
        $this->em->persist($category);
        $this->em->persist($supplier);
        foreach ([[1, false, true, 90.0], [10, true, true, 0.0], [20, true, true, 80.0]] as [$priority, $enabled, $deductible, $percentage]) {
            $rule = new \App\Entity\ExpenseImportRule();
            $rule->entite = $this->entite;
            $rule->keyword = 'sncf';
            $rule->category = $category;
            $rule->supplier = $supplier;
            $rule->priority = $priority;
            $rule->enabled = $enabled;
            $rule->deductible = $deductible;
            $rule->percentage = $percentage;
            $this->em->persist($rule);
        }
        $this->em->flush();
        $engine = self::getContainer()->get(\App\Service\Depense\ExpenseImportRules::class);
        $rows = [['libelle' => 'Paiement carte SNCF', 'tvaDeductiblePct' => 80.0]];
        $result = $engine->apply($this->entite, $rows);
        self::assertSame(0.0, $result[0]['tvaDeductiblePct']);
        self::assertTrue($result[0]['tvaDeductible']);
        self::assertSame($category->getId(), $result[0]['categorieId']);
        self::assertSame($supplier->getId(), $result[0]['fournisseurId']);
        $first = $this->em->getRepository(\App\Entity\ExpenseImportRule::class)->findOneBy(['priority' => 10]);
        $first->deductible = false;
        $first->percentage = 80.0;
        $this->em->flush();
        $result = $engine->apply($this->entite, $rows);
        self::assertFalse($result[0]['tvaDeductible']);
        self::assertSame(0.0, $result[0]['tvaDeductiblePct']);
    }

    public function testForeignClassificationAndInvalidStoredPercentageNeverApply(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $category = (new \App\Entity\DepenseCategorie())->setEntite($this->entite)->setLibelle('Énergie');
        $foreignCategory = (new \App\Entity\DepenseCategorie())->setEntite($other)->setLibelle('Énergie privée');
        $supplier = (new \App\Entity\DepenseFournisseur())->setEntite($this->entite)->setNom('EDF');
        foreach ([$other, $category, $foreignCategory, $supplier] as $item) {
            $this->em->persist($item);
        }
        foreach ([[$foreignCategory, 80.0], [$category, 180.0]] as [$ruleCategory, $percentage]) {
            $rule = new \App\Entity\ExpenseImportRule();
            $rule->entite = $this->entite;
            $rule->keyword = 'électricité';
            $rule->category = $ruleCategory;
            $rule->supplier = $supplier;
            $rule->percentage = $percentage;
            $this->em->persist($rule);
        }
        $this->em->flush();
        $rows = [['libelle' => 'Facture ELECTRICITE', 'categorieId' => null, 'tvaDeductiblePct' => 0.0]];
        $engine = self::getContainer()->get(\App\Service\Depense\ExpenseImportRules::class);
        self::assertSame($rows, $engine->apply($this->entite, $rows));
    }

}
