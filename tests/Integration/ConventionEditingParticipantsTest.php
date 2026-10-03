<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Devis, Entite, Entreprise, Formation, Inscription, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Enum\ModeFinancement;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\Mailer\MailerInterface;

final class ConventionEditingParticipantsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;
    private Entite $entity;
    private Utilisateur $admin;
    private Entreprise $company;
    private Session $session;
    private ConventionContrat $convention;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('streamPdfFromHtml')->willReturn(new \Symfony\Component\HttpFoundation\Response('%PDF-1.4 test document'));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $this->admin = (new Utilisateur())->setEmail('admin-convention@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test');
        $this->entity = (new Entite())->setNom('Organisme test')->setEmail('of@example.test')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entity] as $row) $this->em->persist($row);
        $this->em->flush(); $this->admin->setEntite($this->entity);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->entity)->setUtilisateur($this->admin)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setName('Test')->setCode('CONVENTION_EDIT_TEST'); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entity)->setPlan($plan)->setStatus('active'));
        $formation = (new Formation())->setEntite($this->entity)->setCreateur($this->admin)->setTitre('Habilitation électrique')->setSlug('habilitation-test');
        $site = (new Site())->setEntite($this->entity)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->company = (new Entreprise())->setEntite($this->entity)->setCreateur($this->admin)->setRaisonSociale('Entreprise test');
        foreach ([$formation, $site, $this->company] as $row) $this->em->persist($row);
        $this->session = (new Session())->setEntite($this->entity)->setCreateur($this->admin)->setFormation($formation)->setSite($site)->setCode('SES-EDIT-TEST')->setCapacite(8);
        $devis = (new Devis())->setEntite($this->entity)->setCreateur($this->admin)->setFormation($formation)->setEntrepriseDestinataire($this->company)->setNumero('DEV-EDIT-TEST');
        $this->convention = (new ConventionContrat())->setEntite($this->entity)->setCreateur($this->admin)->setSession($this->session)->setEntreprise($this->company)->setDevis($devis)
            ->setNumero('CONV-EDIT-TEST')->setEffectifPrevisionnel(2)->setParticipantsLibres("BRABANT Hugo\nBERNARD Ilian")->setPdfPath('uploads/old-convention.pdf');
        foreach ([$this->session, $devis, $this->convention] as $row) $this->em->persist($row);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel); $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $n => $key) {
            if ($this->database[$n] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->database[$n];
        }
    }

    public function testEditingAddsClientWithoutEnrollmentAndReplacesNamesWithoutDoubleCounting(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT'); $ilian = $this->learner('Ilian', 'BERNARD');
        $crawler = $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $crawler->filter('select[name="convention_contrat[stagiaires][]"] option[value="'.$hugo->getId().'"]')->count());
        self::assertSame(1, $crawler->filter('textarea#convention_contrat_participantsLibres.form-control')->count());
        self::assertStringContainsString('Hugo BRABANT', $crawler->filter('#convention_contrat_stagiaires')->text());
        if ($dir = getenv('WF_PREVIEW_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($dir.'/convention-edit.html', $this->client->getResponse()->getContent());
        }
        $id = $this->convention->getId();
        $this->submit([$hugo->getId(), $ilian->getId()]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear(); $saved = $this->em->find(ConventionContrat::class, $id);
        self::assertCount(2, $saved->getInscriptions()); self::assertSame(2, $saved->getEffectifTotal());
        self::assertNull($saved->getParticipantsLibres()); self::assertNull($saved->getPdfPath());
        self::assertCount(2, $saved->getDevis()->getInscriptions());
        foreach ($saved->getInscriptions() as $i) {
            self::assertSame($saved->getSession(), $i->getSession()); self::assertSame($saved->getEntreprise(), $i->getEntreprise());
            self::assertSame($saved->getEntite(), $i->getEntite()); self::assertNotNull($i->getDossier());
            self::assertSame(ModeFinancement::ENTREPRISE, $i->getModeFinancement());
        }
        $this->convention = $saved;
        $this->submit([$hugo->getId(), $ilian->getId()], '');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testExistingEnrollmentIsReusedAndRemovingSelectionDoesNotDeleteHistory(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT');
        $i = (new Inscription())->setEntite($this->entity)->setCreateur($this->admin)->setSession($this->session)->setEntreprise($this->company)->setStagiaire($hugo);
        $this->em->persist($i); $this->em->flush(); $existingId = $i->getId();
        $this->submit([$hugo->getId()]); self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame($existingId, $this->current()->getInscriptions()->first()->getId());
        self::assertSame(1, $this->em->getRepository(Inscription::class)->count([]));
        $this->submit([], ''); self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Inscription::class)->count([]));
        self::assertCount(1, $this->current()->getDevis()->getInscriptions());
    }

    public function testForeignTenantAndOtherClientAreNotShownAndForgedSelectionIsRejected(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->admin); $this->em->persist($other); $this->em->flush();
        $foreign = $this->learner('Foreign', 'EXCLUDED', $other);
        $otherCompany = (new Entreprise())->setEntite($this->entity)->setCreateur($this->admin)->setRaisonSociale('Autre client'); $this->em->persist($otherCompany);
        $wrongClient = $this->learner('Wrong', 'CLIENT'); $wrongClient->setEntreprise($otherCompany); $this->em->flush();
        $crawler = $this->client->request('GET', $this->url());
        foreach ([$foreign, $wrongClient] as $u) self::assertSame(0, $crawler->filter('#convention_contrat_stagiaires option[value="'.$u->getId().'"]')->count());
        $token = $crawler->filter('input[name="convention_contrat[_token]"]')->attr('value');
        $this->client->request('POST', $this->url(), ['convention_contrat' => ['_token'=>$token, 'historyToken'=>$crawler->filter('input[name="convention_contrat[historyToken]"]')->attr('value'), 'stagiaires'=>[$foreign->getId()], 'participantsLibres'=>'Nom maintenu', 'effectifPrevisionnel'=>2]]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        $id = $this->convention->getId(); $this->em->clear(); $saved = $this->em->find(ConventionContrat::class, $id);
        self::assertCount(0, $saved->getInscriptions()); self::assertSame("BRABANT Hugo\nBERNARD Ilian", $saved->getParticipantsLibres());
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testUnassignedExistingLearnersAreAttachedToThisClientWithoutChangingOtherSessions(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT'); $ilian = $this->learner('Ilian', 'BERNARD');
        $hugo->setEntreprise(null); $ilian->setEntreprise(null);
        $previousSession = (new Session())->setEntite($this->entity)->setCreateur($this->admin)->setFormation($this->session->getFormation())->setSite($this->session->getSite())->setCode('SES-PREVIOUS');
        $this->em->persist($previousSession);
        $ids = [];
        foreach ([$hugo, $ilian] as $learner) {
            $existing = (new Inscription())->setEntite($this->entity)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($learner);
            $previous = (new Inscription())->setEntite($this->entity)->setCreateur($this->admin)->setSession($previousSession)->setStagiaire($learner);
            $this->em->persist($existing); $this->em->persist($previous); $this->em->flush();
            $ids[] = $existing->getId();
        }
        $crawler = $this->client->request('GET', $this->url());
        foreach ([$hugo, $ilian] as $learner) self::assertSame(1, $crawler->filter('#convention_contrat_stagiaires option[value="'.$learner->getId().'"]')->count());
        self::assertSame(0, $crawler->filter('#convention_contrat_stagiaires option[value="'.$this->admin->getId().'"]')->count());
        $this->submit([$hugo->getId(), $ilian->getId()]); self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear(); $saved = $this->current();
        self::assertCount(2, $saved->getInscriptions()); self::assertSame(4, $this->em->getRepository(Inscription::class)->count([]));
        foreach ($saved->getInscriptions() as $i) {
            self::assertContains($i->getId(), $ids); self::assertSame($saved->getEntreprise(), $i->getEntreprise());
            self::assertNull($i->getStagiaire()->getEntreprise());
        }
        foreach ($this->em->getRepository(Inscription::class)->findBy(['session'=>$previousSession->getId()]) as $i) self::assertNull($i->getEntreprise());
    }

    public function testUnassignedEnrollmentCoveredByAnotherSignedConventionIsNotReassigned(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT'); $hugo->setEntreprise(null);
        $i = (new Inscription())->setEntite($this->entity)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($hugo);
        $signed = (new ConventionContrat())->setEntite($this->entity)->setCreateur($this->admin)->setSession($this->session)->setStagiaire($hugo)
            ->setNumero('CONV-SIGNED')->setDateSignatureStagiaire(new \DateTimeImmutable())->setPdfPath('uploads/signed.pdf')->addInscription($i);
        $this->em->persist($i); $this->em->persist($signed); $this->em->flush(); $enrollmentId = $i->getId();
        $this->submit([$hugo->getId()]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('convention signée ou un autre destinataire', $this->client->getResponse()->getContent());
        $this->em->clear(); self::assertNull($this->em->find(Inscription::class, $enrollmentId)->getEntreprise());
        self::assertCount(0, $this->current()->getInscriptions());
    }

    public function testSignedConventionCannotBeChangedEvenWithAnOldForm(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT'); $crawler = $this->client->request('GET', $this->url());
        $token = $crawler->filter('input[name="convention_contrat[_token]"]')->attr('value');
        $this->current()->setDateSignatureEntreprise(new \DateTimeImmutable()); $this->em->flush();
        $this->client->request('POST', $this->url(), ['convention_contrat'=>['_token'=>$token, 'historyToken'=>$crawler->filter('input[name="convention_contrat[historyToken]"]')->attr('value'), 'stagiaires'=>[$hugo->getId()], 'effectifPrevisionnel'=>1]]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('uploads/old-convention.pdf', $this->current()->getPdfPath());
        self::assertCount(0, $this->current()->getInscriptions()); self::assertSame(2, $this->current()->getEffectifTotal());
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testCapacityFailureCreatesNoPartialEnrollmentAndCanBeRetried(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT'); $ilian = $this->learner('Ilian', 'BERNARD');
        $this->session->setCapacite(1); $this->em->flush();
        $this->submit([$hugo->getId(), $ilian->getId()]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('capacité de la session est insuffisante', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([])); self::assertTrue($this->em->isOpen());
        $id = $this->convention->getId(); $this->em->clear(); $this->convention = $this->em->find(ConventionContrat::class, $id);
        self::assertSame('uploads/old-convention.pdf', $this->current()->getPdfPath());
        $this->convention->getSession()->setCapacite(8); $this->em->flush();
        $this->submit([$hugo->getId(), $ilian->getId()]); self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testSignedNamesCanBeLinkedWithoutChangingOriginalAndMaterialRevisionResetsSignatures(): void
    {
        $hugo = $this->learner('Hugo', 'BRABANT');
        $root = dirname(__DIR__, 2).'/var/storage/conventions';
        if (!is_dir($root)) mkdir($root, 0775, true);
        $name = 'history-test-'.bin2hex(random_bytes(8)).'.pdf';
        $original = '%PDF-1.4 signed original';
        file_put_contents($root.'/'.$name, $original);
        try {
            $this->convention->setPdfPath('private:'.$name)->setDateSignatureEntreprise(new \DateTimeImmutable('2026-10-01'));
            $this->em->flush();
            $this->submit([$hugo->getId()]);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            $c = $this->current();
            self::assertTrue($c->isSigned());
            self::assertSame('private:'.$name, $c->getPdfPath());
            self::assertNull($c->getIntituleFormation()); // forged changes to disabled fields are ignored
            self::assertCount(1, $c->getInscriptions());
            self::assertSame(['BERNARD Ilian'], $c->getParticipantsLibresListe());
            $revision = $this->em->getRepository(\App\Entity\ConventionRevision::class)->findOneBy(['convention'=>$c]);
            self::assertSame($original, $revision->getPdf());
            $url = str_replace('/edit', '', $this->url());
            $page = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $form = $page->selectButton('Créer une nouvelle version à signer')->form();
            $this->client->submit($form);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertFalse($this->current()->isSigned());
            self::assertNull($this->current()->getPdfPath());
            self::assertCount(2, $this->em->getRepository(\App\Entity\ConventionRevision::class)->findBy(['convention'=>$c]));
            $this->client->request('GET', $url.'/historique/'.$revision->getId().'/pdf');
            self::assertSame($original, $this->client->getResponse()->getContent());
        } finally { unlink($root.'/'.$name); }
    }

    public function testSignedConventionRejectsANewPersonAndKeepsItsOriginal(): void
    {
        $unknown = $this->learner('Autre', 'Personne');
        $root = dirname(__DIR__, 2).'/var/storage/conventions';
        if (!is_dir($root)) mkdir($root, 0775, true);
        $name = 'history-test-'.bin2hex(random_bytes(8)).'.pdf';
        file_put_contents($root.'/'.$name, '%PDF-1.4 signed original');
        try {
            $this->convention->setPdfPath('private:'.$name)->setDateSignatureEntreprise(new \DateTimeImmutable());
            $this->em->flush();
            $this->submit([$unknown->getId()]);
            self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('déjà nommé', $this->client->getResponse()->getContent());
            self::assertCount(0, $this->em->getRepository(\App\Entity\ConventionRevision::class)->findAll());
            self::assertCount(0, $this->em->getRepository(Inscription::class)->findAll());
            self::assertTrue($this->current()->isSigned());
        } finally { unlink($root.'/'.$name); }
    }

    private function learner(string $first, string $last, ?Entite $entity = null): Utilisateur
    {
        $u = (new Utilisateur())->setEmail(strtolower($first).'@example.test')->setPassword('unused')->setPrenom($first)->setNom($last)->setEntite($entity ?? $this->entity)->setEntreprise($this->company);
        $this->em->persist($u); $this->em->flush(); return $u;
    }

    private function current(): ConventionContrat { return $this->em->find(ConventionContrat::class, $this->convention->getId()); }
    private function url(): string { return '/fr/administrateur/'.$this->entity->getId().'/conventions/'.$this->convention->getId().'/edit'; }
    private function submit(array $ids, string $free = "BRABANT Hugo\nBERNARD Ilian"): void
    {
        $crawler = $this->client->request('GET', $this->url());
        $token = $crawler->filter('input[name="convention_contrat[_token]"]')->attr('value');
        $this->client->request('POST', $this->url(), ['convention_contrat'=>['_token'=>$token, 'historyToken'=>$crawler->filter('input[name="convention_contrat[historyToken]"]')->attr('value'), 'stagiaires'=>$ids, 'participantsLibres'=>$free, 'remplacerNomsLibres'=>'1', 'effectifPrevisionnel'=>'2', 'intituleFormation'=>'Titre personnalisé', 'conditionsFinancieres'=>'Paiement à 30 jours']]);
    }
}
