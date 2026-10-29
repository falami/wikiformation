<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Utilisateur, UtilisateurEntite, Site, Session, Inscription, HabilitationTemplate, HabilitationDossier, HabilitationRevision};
use App\Service\Habilitation\{HabilitationSchema, HabilitationWorkflow};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

final class EntrepriseHabilitationDocumentsTest extends WebTestCase
{
    private array $oldDb;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Entite $tenant;
    private Utilisateur $employer;
    private Utilisateur $trainer;
    private Entreprise $alpha;
    private Entreprise $beta;
    private HabilitationTemplate $model;
    private Site $site;

    protected function setUp(): void
    {
        $this->oldDb = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient(); $this->client->disableReboot(); $this->client->catchExceptions(false);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('twig')->addGlobal('entite', null);
        $this->employer = $this->user('Employeur'); $this->trainer = $this->user('Formateur');
        $this->tenant = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->employer);
        $this->em->persist($this->tenant); $this->em->flush();
        foreach ([[$this->employer, 'TENANT_ENTREPRISE'], [$this->trainer, 'TENANT_FORMATEUR']] as [$user, $role]) {
            $user->setEntite($this->tenant);
            $this->em->persist((new UtilisateurEntite())->setEntite($this->tenant)->setUtilisateur($user)->setCreateur($this->employer)->setRoles([$role])->setStatus('active'));
        }
        $plan = (new \App\Entity\Billing\Plan())->setCode('HAB_PORTAL')->setName('Fixture')->setMaxApprenantsAn(200)->setMaxUtilisateurs(10)->setMaxFormateurs(10)->setMaxEntreprises(10)->setMaxProspects(10);
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->tenant)->setPlan($plan)->setStatus('active'));
        $this->alpha = $this->company('Alpha'); $this->beta = $this->company('Beta');
        $this->site = (new Site())->setNom('Atelier')->setSlug('atelier')->setEntite($this->tenant)->setCreateur($this->employer);
        $this->model = (new HabilitationTemplate())->setEntite($this->tenant)->setTitre('Évaluation électrique')->setSchema((new HabilitationSchema())->example());
        $this->em->persist($this->site); $this->em->persist($this->model); $this->em->flush();
        $this->client->loginUser($this->employer);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i=>$scope) {
            if ($this->oldDb[$i] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->oldDb[$i];
        }
    }

    private function user(string $name): Utilisateur
    {
        $u = (new Utilisateur())->setEmail(strtolower($name).'@hab-portal.test')->setPassword('unused')->setPrenom($name)->setNom('Exemple');
        $this->em->persist($u); return $u;
    }

    private function company(string $name): Entreprise
    {
        $c = (new Entreprise())->setRaisonSociale($name)->setEntite($this->tenant)->setCreateur($this->employer)->setRepresentant($this->employer)->setAdresse('10 rue des Ateliers')->setCodePostal('30100')->setVille('Alès');
        $this->em->persist($c); return $c;
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(100, 40); imageline($image, 0, 0, 99, 39, imagecolorallocate($image, 230, 230, 230));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function dossier(Entreprise $company, string $name, bool $transmit = true): HabilitationDossier
    {
        $u = $this->user($name);
        $s = (new Session())->setEntite($this->tenant)->setCreateur($this->employer)->setCode('SES-'.$name)->setSite($this->site)->setTypeFinancement(\App\Enum\TypeFinancement::OUI)->setFormationIntituleLibre('Habilitation électrique');
        $i = (new Inscription())->setEntite($this->tenant)->setCreateur($this->employer)->setSession($s)->setEntreprise($company)->setStagiaire($u);
        $d = (new HabilitationDossier())->setEntite($this->tenant)->setEntreprise($company)->setInscription($i)->setFormateur($this->trainer)->setTemplate($this->model)->setSchema($this->model->getSchema());
        foreach ([$s, $i, $d] as $record) $this->em->persist($record);
        $this->em->flush();
        $workflow = self::getContainer()->get(HabilitationWorkflow::class);
        $input = ['function'=>'Technicien', 'assignment'=>'Atelier A', 'issuedAt'=>'2026-10-01', 'signerFunction'=>'Formateur', 'restrictions'=>'Atelier A uniquement',
            'rows'=>['row_0'=>['verdict'=>'favorable', 'symbols'=>['H0V'], 'voltage'=>['HTA'], 'works'=>'Atelier A', 'details'=>'Sans travaux électriques']]];
        $this->em->persist($workflow->save($d, 'avis', $input, true, $this->trainer, $this->signature())); $this->em->flush();
        if ($transmit) { $this->em->persist($workflow->transmit($d, $this->employer)); $this->em->flush(); }
        return $d;
    }

    private function documentsUrl(string $suffix = '', ?Entreprise $company = null): string
    {
        return '/fr/entreprise/'.$this->tenant->getId().'/documents'.$suffix.'?entreprise='.($company ?? $this->alpha)->getId();
    }

    private function editorUrl(HabilitationDossier $d, ?Entreprise $company = null): string
    {
        return '/fr/habilitation/'.$this->tenant->getId().'/'.$d->getId().'/entreprise?entreprise='.($company ?? $this->alpha)->getId();
    }

    private function listing(array $params = [], ?Entreprise $company = null): array
    {
        $this->client->request('POST', $this->documentsUrl('/habilitations/avis/ajax', $company), $params);
        self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testListsOnlySelectedCompanyTransmittedCurrentOpinionsWithSearchAndPagination(): void
    {
        $a = $this->dossier($this->alpha, 'Alice'); $this->dossier($this->alpha, 'Zoe');
        $this->dossier($this->beta, 'Bernard'); $this->dossier($this->alpha, 'Prive', false);
        $inactive = $this->dossier($this->alpha, 'Inactif'); $inactive->setActive(false);
        $deleted = $this->dossier($this->alpha, 'Supprime'); $deleted->setDeleted(true);
        $current = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['token'=>$a->getAvisToken()]);
        $archive = (new HabilitationRevision())->setDossier($a)->setActor($this->trainer)->setKind('avis')->setToken(str_repeat('a', 32))->setSnapshot($current->getSnapshot());
        $this->em->persist($archive); $this->em->flush();
        $data = $this->listing(['draw'=>7, 'length'=>1, 'order'=>[['column'=>0, 'dir'=>'asc']]]);
        self::assertSame(7, $data['draw']); self::assertSame(2, $data['recordsTotal']); self::assertSame(2, $data['recordsFiltered']); self::assertCount(1, $data['data']);
        self::assertSame('Alice Exemple', $data['data'][0]['stagiaire']);
        self::assertStringContainsString('entreprise='.$this->alpha->getId(), $data['data'][0]['actions']);
        self::assertStringContainsString('Préparer le titre', $data['data'][0]['actions']);
        $data = $this->listing(['search'=>['value'=>'exemple ali']]); self::assertSame(1, $data['recordsFiltered']);
        $data = $this->listing(['search'=>['value'=>'Habilitation électrique']]); self::assertSame(2, $data['recordsFiltered']);
        $data = $this->listing(['search'=>['value'=>'Bernard']]); self::assertSame(0, $data['recordsFiltered']);
        $data = $this->listing([], $this->beta); self::assertSame(1, $data['recordsTotal']); self::assertSame('Bernard Exemple', $data['data'][0]['stagiaire']);
        $this->client->request('GET', $this->documentsUrl()); self::assertResponseIsSuccessful(); self::assertSelectorExists('#tab-habilitations');
    }

    public function testCompanyStartsFromCheckedTrainerChoicesCanDowngradeAndSignIndependentTitle(): void
    {
        $d = $this->dossier($this->alpha, 'Alice');
        $opinion = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['token'=>$d->getAvisToken()]); $snapshot = $opinion->getSnapshot();
        $page = $this->client->request('GET', $this->editorUrl($d)); self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="data[rows][row_0][symbols][]"][value="H0V"][checked]:not([disabled])');
        self::assertSelectorExists('input[name="data[rows][row_0][symbols][]"][value="H0"]:not([checked]):not([disabled])');
        self::assertSelectorExists('input[name="data[rows][row_0][symbols][]"][value="B0"][disabled]');
        self::assertSelectorExists('textarea[name="data[rows][row_0][works]"][readonly]');
        self::assertStringContainsString('10 rue des Ateliers', $this->client->getResponse()->getContent());
        $data = self::getContainer()->get(HabilitationWorkflow::class)->initialEmployerData($d);
        $data['rows']['row_0']['symbols'] = ['H0']; $data['issuedAt']='2026-10-29'; $data['validUntil']='2027-10-29'; $data['signerFunction']='Directeur';
        $this->client->request('POST', $this->editorUrl($d), ['_token'=>$page->filter('input[name="_token"]')->first()->attr('value'), 'version'=>$page->filter('input[name="version"]')->attr('value'), '_complete'=>'1', 'action'=>'sign', 'confirm'=>'1', 'signature'=>$this->signature(), 'data'=>$data]);
        self::assertResponseRedirects($this->editorUrl($d).'&stage=titre');
        $d = $this->em->find(HabilitationDossier::class, $d->getId());
        self::assertSame('published', $d->getState()); self::assertSame(['H0'], $d->getEmployerData()['rows']['row_0']['symbols']);
        self::assertSame(['H0V'], $d->getTrainerData()['rows']['row_0']['symbols']);
        self::assertSame($snapshot, $this->em->find(HabilitationRevision::class, $opinion->getId())->getSnapshot());
        foreach (['avis', 'titre'] as $kind) {
            $this->client->request('GET', $this->documentsUrl('/habilitations/'.$d->getId().'/'.$kind.'/pdf'));
            self::assertResponseIsSuccessful(); self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $data = $this->listing(); self::assertStringContainsString('Titre délivré', $data['data'][0]['status']); self::assertStringContainsString('Titre PDF', $data['data'][0]['actions']);
    }

    public function testEditingAndDownloadsCannotCrossCompanyEvenForRepresentativesOfBoth(): void
    {
        $d = $this->dossier($this->beta, 'Bernard'); $private = $this->dossier($this->alpha, 'Prive', false);
        $other = $this->company('Interdite')->setRepresentant(null); $this->em->flush();
        $this->client->catchExceptions(true);
        foreach ([$this->editorUrl($d), $this->documentsUrl('/habilitations/'.$d->getId().'/avis/pdf'), $this->editorUrl($private)] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', $this->editorUrl($d, $this->beta)); self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->documentsUrl('/habilitations/'.$d->getId().'/avis/pdf', $this->beta)); self::assertResponseIsSuccessful();
        $this->client->request('POST', $this->documentsUrl('/habilitations/avis/ajax', $other)); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->trainer);
        $this->client->request('POST', $this->documentsUrl('/habilitations/avis/ajax')); self::assertResponseStatusCodeSame(403);
    }

    public function testReassignmentOrChangedSnapshotIdentityHidesDocuments(): void
    {
        $d = $this->dossier($this->alpha, 'Alice');
        $d->getInscription()->setEntreprise($this->beta); $this->em->flush(); self::assertSame(0, $this->listing()['recordsTotal']);
        $d->getInscription()->setEntreprise($this->alpha);
        $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['token'=>$d->getAvisToken()]);
        $snapshot = $r->getSnapshot(); $snapshot['identity']['companyId'] = $this->beta->getId(); $r->setSnapshot($snapshot); $this->em->flush();
        self::assertSame(0, $this->listing()['recordsTotal']);
        $this->client->catchExceptions(true); $this->client->request('GET', $this->documentsUrl('/habilitations/'.$d->getId().'/avis/pdf')); self::assertResponseStatusCodeSame(404);
    }

    public function testForgedHigherChoiceAndAttemptsToEditOriginalOpinionAreRejected(): void
    {
        $d = $this->dossier($this->alpha, 'Alice'); $originalToken = $d->getAvisToken();
        $data = self::getContainer()->get(HabilitationWorkflow::class)->initialEmployerData($d);
        $page = $this->client->request('GET', $this->editorUrl($d));
        $payload = ['_token'=>$page->filter('input[name="_token"]')->first()->attr('value'), 'version'=>$page->filter('input[name="version"]')->attr('value'), '_complete'=>'1', 'action'=>'save', 'data'=>$data];
        // This symbol exists in the questionnaire, but is not included in the signed advice.
        $payload['data']['rows']['row_0']['symbols'] = ['B0'];
        $this->client->request('POST', $this->editorUrl($d), $payload); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'limites de l’avis');
        $stored = $this->em->find(HabilitationDossier::class, $d->getId());
        self::assertSame([], $stored->getEmployerData()); self::assertSame($originalToken, $stored->getAvisToken());
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->editorUrl($d).'&stage=avis', $payload); self::assertResponseStatusCodeSame(403);
        $payload['_token'] = 'invalid'; $this->client->request('POST', $this->editorUrl($d), $payload); self::assertResponseStatusCodeSame(403);
    }

    public function testInconsistentTenantOnSessionNeverExposesDocuments(): void
    {
        $d = $this->dossier($this->alpha, 'Alice');
        $foreign = (new Entite())->setNom('Autre organisme')->setCreateur($this->employer)->setPublic(false);
        $this->em->persist($foreign); $d->getInscription()->getSession()->setEntite($foreign); $this->em->flush();
        self::assertSame(0, $this->listing()['recordsTotal']);
        $this->client->catchExceptions(true);
        foreach ([$this->editorUrl($d), $this->documentsUrl('/habilitations/'.$d->getId().'/avis/pdf')] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        }
    }

    public function testMalformedDatatablesSearchAndOrderReturnBadRequest(): void
    {
        $this->client->catchExceptions(true);
        foreach ([['search'=>['value'=>['invalid']]], ['order'=>['invalid']], ['order'=>[['column'=>['invalid']]]], ['order'=>[['dir'=>['invalid']]]]] as $parameters) {
            $this->client->request('POST', $this->documentsUrl('/habilitations/avis/ajax'), $parameters);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testPaginationAcrossBatchesPreservesCountsAndFiltersInvalidIdentity(): void
    {
        for ($n = 0; $n < 105; ++$n) {
            $d = $this->dossier($this->alpha, 'Apprenant'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
            if ($n === 99) {
                $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['token'=>$d->getAvisToken()]);
                $snapshot = $r->getSnapshot(); $snapshot['identity']['companyId'] = $this->beta->getId(); $r->setSnapshot($snapshot); $this->em->flush();
            }
        }
        $data = $this->listing(['start'=>99, 'length'=>10, 'order'=>[['column'=>0, 'dir'=>'asc']]]);
        self::assertSame(104, $data['recordsTotal']); self::assertSame(104, $data['recordsFiltered']); self::assertCount(5, $data['data']);
        self::assertSame('Apprenant100 Exemple', $data['data'][0]['stagiaire']);
        $data = $this->listing(['search'=>['value'=>'Apprenant10'], 'order'=>[['column'=>0, 'dir'=>'desc']]]);
        self::assertSame(104, $data['recordsTotal']); self::assertSame(5, $data['recordsFiltered']); self::assertSame('Apprenant104 Exemple', $data['data'][0]['stagiaire']);
    }
}
