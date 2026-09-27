<?php

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Devis, Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class ListingFiltersTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Formation $formation;
    private Site $site;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('quotes-list@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Habilitation électrique')->setSlug('habilitation-electrique');
        $this->site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->em->persist($this->formation);
        $this->em->persist($this->site);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testSiteChoicesCoverTheWholeTenantAndEmptyMeansNoRows(): void
    {
        $this->site->setVille('Paris')->setPays('France');
        $lyon = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Lyon')->setSlug('lyon')->setVille('Lyon')->setPays('France');
        $foreign = (new Site())->setEntite($this->other)->setCreateur($this->admin)->setNom('Secret')->setSlug('secret')->setVille('Confidentiel');
        $this->em->persist($lyon); $this->em->persist($foreign); $this->em->flush();
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $url = self::getContainer()->get('router')->generate('app_administrateur_site_ajax', ['entite'=>$this->entite->getId()]);
        foreach ([[[],2], [['ville'=>[]],0], [['ville'=>['Paris']],1], [['ville'=>['Paris','Lyon']],2], [['ville'=>'*'],2], [['ville'=>['Confidentiel']],0]] as [$filters,$expected]) {
            $client->request('POST',$url,['draw'=>1,'start'=>0,'length'=>1,'wfFilters'=>json_encode($filters)]);
            self::assertSame(200,$client->getResponse()->getStatusCode());
            $data = json_decode($client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
            self::assertSame(2,$data['recordsTotal']); self::assertSame($expected,$data['recordsFiltered']);
            self::assertCount(min(1,$expected),$data['data']);
            self::assertSame(['Lyon','Paris'],array_column($data['filters'][0]['options'],'label'));
            self::assertStringNotContainsString('Confidentiel',json_encode($data));
        }
    }

    public function testFacetsSupportEnumsBooleansAndConventionsBeforePagination(): void
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $routes = ['app_administrateur_engin_ajax','app_administrateur_attestation_ajax','app_administrateur_convention_ajax','app_administrateur_elearning_ajax','app_administrateur_charge_rule_ajax'];
        foreach ($routes as $route) {
            $url = self::getContainer()->get('router')->generate($route,['entite'=>$this->entite->getId()]);
            $client->request('POST',$url,['draw'=>1,'start'=>0,'length'=>1,'wfFilters'=>'{}']);
            self::assertSame(200,$client->getResponse()->getStatusCode(),$route);
            $data=json_decode($client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
            self::assertNotEmpty($data['filters'],$route);
        }
    }

    public function testMultipleChoicesAreOrConditionsAndPreserveTheTenant(): void
    {
        $this->site->setVille('Paris');
        foreach ([[$this->entite, 'Lyon'], [$this->other, 'Paris']] as [$tenant, $city]) {
            $this->em->persist((new Site())->setEntite($tenant)->setCreateur($this->admin)->setNom($city)->setSlug($city . $tenant->getId())->setVille($city));
        }
        $this->em->flush();
        foreach (['all'=>2, 'Paris'=>1, '["Paris","Lyon"]'=>2, '[]'=>0, '["missing"]'=>0] as $raw=>$expected) {
            $qb = $this->em->getRepository(Site::class)->createQueryBuilder('s')->select('COUNT(s.id)')
                ->where('s.entite = :tenant')->setParameter('tenant', $this->entite);
            \App\Service\Filter\ChoiceFilter::any($qb, $raw, static function ($branch, $city): void {
                // Reuse the same parameter name in each OR branch and in a tenant predicate.
                $branch->andWhere('s.ville = :city AND s.entite = :tenant')->setParameter('city', $city);
            });
            self::assertSame($expected, (int)$qb->getQuery()->getSingleScalarResult(), $raw);
        }
    }

    public function testExistingListingsAcceptNoneAndMultipleChoices(): void
    {
        $this->formation->setNiveau(\App\Enum\NiveauFormation::INITIAL);
        $this->em->persist((new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Avancée')->setSlug('avancee')->setNiveau(\App\Enum\NiveauFormation::AVANCEE));
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $cases = [
            ['formation', 'niveauFilter', 'initial', 1],
            ['formation', 'niveauFilter', '["initial","avancée"]', 2],
            ['formation', 'niveauFilter', '[]', 0],
            ['formateur', 'siteFilter', '["1","2"]', 0],
            ['formateur', 'enginFilter', '[]', 0],
            ['entreprise', 'lockedFilter', '["0","1"]', 0],
            ['utilisateur', 'verifiedFilter', '[]', 0],
            ['utilisateur', 'lockedFilter', '["0","1"]', 1],
            ['formateurs_contrats', 'statusFilter', '[]', 0],
            ['reservation', 'statusFilter', '[]', 0],
            ['inscription', 'statusFilter', '[]', 0],
            ['devis', 'statusFilter', '[]', 0],
            ['qcm', 'activeFilter', '["0","1"]', 0],
            ['positioning_questionnaire', 'publishedFilter', '["yes","no"]', 0],
            ['satisfaction_template', 'activeFilter', '[]', 0],
            ['formateur_satisfaction_template', 'activeFilter', '["yes","no"]', 0],
            ['session', 'dossierFilter', '["complete","missing"]', 0],
            ['session', 'statusFilter', '[]', 0],
        ];
        foreach ($cases as [$name, $key, $value, $expected]) {
            $url = self::getContainer()->get('router')->generate('app_administrateur_'.$name.'_ajax', ['entite'=>$this->entite->getId()]);
            $client->request('POST', $url, ['draw'=>1,'start'=>0,'length'=>1,$key=>$value]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $name . ': ' . $client->getResponse()->getContent());
            $json = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expected, $json['recordsFiltered'], $name . '/' . $key);
        }
    }

    public function testAccountingRecipientAndQuarterFiltersAgreeWithKpis(): void
    {
        $pdo = $this->em->getConnection()->getNativeConnection();
        // Accounting SQL uses these MySQL functions; the test database is isolated SQLite.
        $pdo->sqliteCreateFunction('GREATEST', static fn(...$v)=>max($v));
        $pdo->sqliteCreateFunction('LEAST', static fn(...$v)=>min($v));
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$v)=>implode('', $v));
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Entreprise test');
        $this->em->persist($company);
        foreach ([['2026-01-10',true,$this->entite], ['2026-04-10',false,$this->entite], ['2026-07-10',true,$this->entite], ['2026-01-10',true,$this->other]] as $i=>[$date,$business,$tenant]) {
            $invoice = (new \App\Entity\Facture())->setEntite($tenant)->setCreateur($this->admin)->setNumero('FAC-FILTER-'.$i)->setMontantHtCents(0)->setMontantTvaCents(0)->setMontantTtcCents(0)->setDateEmission(new \DateTimeImmutable($date))->setDestinataire($this->admin);
            if ($business) $invoice->setEntrepriseDestinataire($company);
            $payment = (new \App\Entity\Paiement())->setEntite($tenant)->setCreateur($this->admin)->setDatePaiement(new \DateTimeImmutable($date))->setMontantCents(1000);
            if ($business) $payment->setPayeurEntreprise($company); else $payment->setPayeurUtilisateur($this->admin);
            $this->em->persist($invoice); $this->em->persist($payment);
        }
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $cases = [
            [[],3],
            [['payeurEntrepriseMode'=>'none','payeurUserMode'=>'all'],1],
            [['payeurEntrepriseMode'=>'none','payeurUserMode'=>'none'],0],
            [['periodType'=>'quarter','yearFilter'=>'2026','quarterFilter'=>'["1","3"]'],2],
            [['periodType'=>'quarter','yearFilter'=>'2026','quarterFilter'=>'[]'],0],
            [['periodType'=>'quarter','yearFilter'=>'[]','quarterFilter'=>'all'],0],
            [['periodType'=>'month','yearFilter'=>'2026','monthFilter'=>'["1","7"]'],2],
            [['periodType'=>'year','yearFilter'=>'["2025","2026"]'],3],
        ];
        foreach (['facture','paiement'] as $name) {
            foreach ($cases as [$filters,$expected]) {
                $params = ['entite'=>$this->entite->getId()];
                $client->request('POST', self::getContainer()->get('router')->generate('app_administrateur_'.$name.'_ajax',$params), $filters+['draw'=>1,'start'=>0,'length'=>1]);
                self::assertSame(200,$client->getResponse()->getStatusCode(), $name);
                $data = json_decode($client->getResponse()->getContent(),true,flags: JSON_THROW_ON_ERROR);
                self::assertSame($expected,$data['recordsFiltered'],$name.json_encode($filters));
                self::assertCount(min(1,$expected),$data['data']);
                $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_'.$name.'_kpis',$params), $filters);
                self::assertSame(200,$client->getResponse()->getStatusCode());
                $kpis = json_decode($client->getResponse()->getContent(),true,flags: JSON_THROW_ON_ERROR);
                self::assertSame($expected,$kpis['count'],$name.json_encode($filters));
            }
        }
    }
}
