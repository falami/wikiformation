<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Automation\{TrainingWorkflow, WorkflowParticipant};
use App\Entity\{ConventionContrat, Entite, Entreprise, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\Automation\{WorkflowParticipantImporter, WorkflowPortal, WorkflowSignature};
use App\Service\Convention\ConventionDocument;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Ephemeral database and private temp storage: never sends mail or mutates business data. */
final class WorkflowPortalTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TrainingWorkflow $workflow;
    private Entite $entite;
    private Utilisateur $admin;
    private array $database;
    private string $directory;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->directory = sys_get_temp_dir().'/wikiformation-portal-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $pdf = $this->createMock(PdfManager::class);
        $pdf->method('createPortraitBytes')->willReturnCallback(static fn(string $html): string => "%PDF-1.4\n".$html);
        $twig = self::getContainer()->get('twig');
        self::getContainer()->set(WorkflowSignature::class, new WorkflowSignature($twig, $pdf, $this->directory));
        self::getContainer()->set(ConventionDocument::class, new ConventionDocument($twig, $pdf, $this->directory));

        $this->admin = (new Utilisateur())->setEmail('portal-admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Portail');
        $this->entite = (new Entite())->setNom('Organisme Portail')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite] as $e) $this->em->persist($e);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setCode('portal-test')->setName('Portail test'); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $company = (new Entreprise())->setRaisonSociale('Entreprise Alpha')->setEntite($this->entite)->setCreateur($this->admin);
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site test')->setSlug('portal-test');
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation test')->setSlug('portal-test');
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode('SES-PORTAL')->setSite($site)->setFormation($formation)->setCapacite(8);
        $session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('tomorrow 08:30'))->setDateFin(new \DateTimeImmutable('tomorrow 17:00')));
        $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setEntreprise($company)->setNumero('CONV-PORTAL')->setEffectifPrevisionnel(8);
        $this->workflow = (new TrainingWorkflow())->setEntite($this->entite)->setSession($session)->setConvention($convention)->setCreateur($this->admin)->setContactEmail('contact@example.test');
        foreach ([$company, $site, $formation, $session, $convention, $this->workflow] as $e) $this->em->persist($e);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->directory);
        }
        foreach (['ENV','SERVER'] as $i => $type) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testDossierLinkIsScopedRevocableAndExpiresWithoutExposingEmail(): void
    {
        $portal = self::getContainer()->get(WorkflowPortal::class); $token = $portal->token($this->workflow);
        self::assertTrue($portal->isValid($this->workflow, $token));
        self::assertFalse($portal->isValid($this->workflow, str_repeat('0', 64)));
        self::assertFalse($portal->isValid($this->workflow, $token, new \DateTimeImmutable('+40 days')));
        self::assertStringNotContainsString('contact', $portal->url($this->workflow));
        self::assertTrue($portal->canSign($this->workflow));
        self::assertFalse($portal->canSign($this->workflow, new \DateTimeImmutable('+2 days')));
        $oldOptOut = $portal->renewalToken($this->workflow);
        self::assertFalse($portal->isValid($this->workflow, $oldOptOut));
        self::assertFalse($portal->isRenewalTokenValid($this->workflow, $token));
        $this->workflow->rotatePortalNonce();
        self::assertFalse($portal->isValid($this->workflow, $token));
        self::assertFalse($portal->isRenewalTokenValid($this->workflow, $oldOptOut));
    }

    public function testPublicCollectionAcceptsMissingEmailAndProtectsCsrfAndRevision(): void
    {
        $client = $this->client(); $crawler = $client->request('GET', $this->url());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        $csrf = $crawler->filter('#participants-form input[name="_token"]')->attr('value');
        $data = ['action'=>'participants', '_token'=>$csrf, 'revision'=>0, 'participants'=>[
            0=>['prenom'=>'Camille', 'nom'=>'Sans email', 'email'=>'', 'dateNaissance'=>''],
            1=>['prenom'=>'Lou', 'nom'=>'Martin', 'email'=>'lou@example.test', 'dateNaissance'=>'1991-06-15'],
        ]];
        $client->request('POST', $this->url(), $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->reload();
        $rows = $this->em->getRepository(WorkflowParticipant::class)->findBy(['workflow'=>$this->workflow], ['position'=>'ASC']);
        self::assertCount(2, $rows); self::assertNull($rows[0]->getEmail());
        self::assertSame('1991-06-15', $rows[1]->getDateNaissance()->format('Y-m-d'));
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]), 'Collection creates no account.');
        self::assertSame("Camille Sans email\nLou Martin", $this->workflow->getConvention()->getParticipantsLibres());
        $client->catchExceptions(true); $client->request('POST', $this->url(), $data);
        self::assertSame(409, $client->getResponse()->getStatusCode(), 'A stale form cannot overwrite newer collected data.');
    }

    public function testInvalidCsrfAndWrongDossierTokenCannotCollectOrExposeData(): void
    {
        $client = $this->client(); $client->catchExceptions(true);
        $client->request('POST', $this->url(), ['action'=>'participants','_token'=>'invalid','participants'=>[]]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(WorkflowParticipant::class)->count([]));
        $client->request('GET', preg_replace('/[a-f0-9]{64}$/', str_repeat('0',64), $this->url()));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Entreprise Alpha', $client->getResponse()->getContent());
    }

    public function testSignatureNeedsExplicitConsentAndFreezesPrivatePdfAndEvidence(): void
    {
        $client = $this->client(); $crawler = $client->request('GET', $this->url());
        $form = $crawler->filter('.signature-form')->form();
        $data = $form->getValues(); $data['signerName']='Camille Dupont'; $data['signerRole']='Responsable formation'; unset($data['consent']);
        $client->request('POST', $this->url(), $data);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertFalse($this->workflow->getConvention()->isSigned());
        $data['consent']='1'; $client->request('POST', $this->url(), $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->reload();
        self::assertTrue($this->workflow->getConvention()->isSignedByEntreprise());
        self::assertStringStartsWith('private:', $this->workflow->getConvention()->getPdfPath());
        $evidence = $this->workflow->getOptions()['signatureEvidence'];
        self::assertSame('Camille Dupont', $evidence['name']);
        $path = $this->directory.'/var/storage/conventions/'.substr($this->workflow->getConvention()->getPdfPath(), 8);
        self::assertSame($evidence['signedSha256'], hash_file('sha256', $path));
        $source = $this->directory.'/var/storage/conventions/'.$evidence['sourceFile'];
        self::assertSame($evidence['sourceSha256'], hash_file('sha256', $source));
        $original = file_get_contents($path);
        $this->workflow->getSession()->getFormation()->setTitre('New title must not rewrite the signed document'); $this->em->flush();
        $client->request('GET', $this->url().'/convention');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($original, $client->getInternalResponse()->getContent());
    }

    public function testChangedDocumentCannotBeSignedFromAStalePreview(): void
    {
        $client=$this->client(); $crawler=$client->request('GET',$this->url());
        $data=$crawler->filter('.signature-form')->form()->getValues();
        $data['signerName']='Camille Dupont'; $data['signerRole']='Direction'; $data['consent']='1';
        $this->workflow->getConvention()->setIntituleFormation('Nouvel intitulé'); $this->em->flush();
        $client->request('POST',$this->url(),$data);
        self::assertSame(409,$client->getResponse()->getStatusCode());
        self::assertStringContainsString('La convention a changé', $client->getResponse()->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM convention_contrat WHERE date_signature_entreprise IS NOT NULL'));
    }

    public function testImportIsIdempotentLeavesMissingEmailsPendingAndNeverLinksForeignAccounts(): void
    {
        $foreign = (new Utilisateur())->setEmail('foreign@example.test')->setPassword('unused')->setPrenom('Autre')->setNom('Compte'); $this->em->persist($foreign);
        foreach ([['Lou','Martin','lou@example.test'],['Sans','Mail',null],['Autre','Compte','foreign@example.test']] as $position=>$details) {
            $this->em->persist((new WorkflowParticipant())->setWorkflow($this->workflow)->setPosition($position)->setPrenom($details[0])->setNom($details[1])->setEmail($details[2]));
        }
        $this->workflow->getConvention()->setParticipantsLibres("Lou Martin\nSans Mail\nAutre Compte"); $this->em->flush();
        $importer = self::getContainer()->get(WorkflowParticipantImporter::class);
        self::assertSame(['imported'=>1,'pending'=>2],$importer->import($this->workflow,$this->admin));
        self::assertSame(1,$this->em->getRepository(Inscription::class)->count([]));
        self::assertSame("Sans Mail\nAutre Compte",$this->workflow->getConvention()->getParticipantsLibres());
        self::assertTrue($this->workflow->getConvention()->getInscriptions()->first()->getMeta()['workflowNewAccount']);
        $revision = $this->workflow->getOptions()['portalRevision'];
        self::assertSame(['imported'=>0,'pending'=>2],$importer->import($this->workflow,$this->admin));
        self::assertSame($revision, $this->workflow->getOptions()['portalRevision'], 'A no-op import does not invalidate the client form.');
        self::assertSame(1,$this->em->getRepository(Inscription::class)->count([]));
        self::assertCount(0,$foreign->getUtilisateurEntites());
    }

    public function testRenewalOptOutRequiresConfirmationAndDoesNotExposeParticipants(): void
    {
        $portal=self::getContainer()->get(WorkflowPortal::class); $client=$this->client();
        $crawler=$client->request('GET',$portal->optOutUrl($this->workflow));
        self::assertSame(200,$client->getResponse()->getStatusCode()); self::assertFalse($this->workflow->isRenewalOptOut());
        self::assertStringNotContainsString('Entreprise Alpha',$client->getResponse()->getContent());
        $client->submit($crawler->selectButton('Arrêter ces rappels')->form());
        $this->reload();
        self::assertSame(200,$client->getResponse()->getStatusCode()); self::assertTrue($this->workflow->isRenewalOptOut());
    }

    public function testCapacityRefusalKeepsWorkflowManagedAndCanBeRetriedWithoutPartialAccounts(): void
    {
        $this->collectTwoParticipants();
        $this->workflow->getSession()->setCapacite(1); $this->em->flush();
        $importer = self::getContainer()->get(WorkflowParticipantImporter::class);
        try {
            $importer->import($this->workflow, $this->admin);
            self::fail('The session cannot exceed its capacity.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('capacité', $error->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($this->workflow));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
        self::assertSame(0, $this->workflow->getOptions()['portalRevision'] ?? 0);

        $this->workflow->getSession()->setCapacite(2); $this->em->flush();
        self::assertSame(['imported' => 2, 'pending' => 0], $importer->import($this->workflow, $this->admin));
        self::assertSame(2, $this->em->getRepository(Inscription::class)->count([]));
        self::assertSame(3, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testQuotaRefusalDiscardsUnflushedUsageAndAllowsCleanRetry(): void
    {
        $this->collectTwoParticipants();
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['code' => 'portal-test']);
        $plan->setMaxApprenantsAn(1); $this->em->flush();
        $importer = self::getContainer()->get(WorkflowParticipantImporter::class);
        try {
            $importer->import($this->workflow, $this->admin);
            self::fail('The annual learner quota cannot be exceeded.');
        } catch (\App\Exception\BillingQuotaExceededException $error) {
            self::assertSame('max_apprenants_an', $error->getQuotaKey());
        }
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($this->workflow));
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions());
        // This flush represents the caller saving a blocked task after the refusal.
        $this->em->flush();
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\EntiteUsageYear::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\DossierInscription::class)->count([]));

        $plan->setMaxApprenantsAn(3); $this->em->flush();
        self::assertSame(['imported' => 2, 'pending' => 0], $importer->import($this->workflow, $this->admin));
        self::assertSame(2, $this->em->getRepository(Inscription::class)->count([]));
        $usage = $this->em->getRepository(\App\Entity\Billing\EntiteUsageYear::class)->findOneBy(['entite' => $this->entite]);
        self::assertSame(2, $usage->getApprenantsCount());
        self::assertSame(['imported' => 0, 'pending' => 0], $importer->import($this->workflow, $this->admin));
        self::assertSame(2, $usage->getApprenantsCount(), 'A retry does not consume the annual quota again.');
    }

    private function collectTwoParticipants(): void
    {
        foreach (['Alice', 'Benoit'] as $position => $name) {
            $this->em->persist((new WorkflowParticipant())->setWorkflow($this->workflow)->setPosition($position)
                ->setPrenom($name)->setNom('Test')->setEmail(strtolower($name) . '@example.test'));
        }
        $this->em->flush();
    }

    private function client(): KernelBrowser { $client=self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); return $client; }
    private function url(): string { return self::getContainer()->get(WorkflowPortal::class)->url($this->workflow); }
    private function reload(): void { $this->workflow = $this->em->getRepository(TrainingWorkflow::class)->find($this->workflow->getId()); }
}
