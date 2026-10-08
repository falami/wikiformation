<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite, Prospect, DossierDelegation, Entreprise, Facture, Devis};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\Delegation\DossierAccess;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
final class DelegationPortalTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $sales;
    private Utilisateur $admin;
    private UtilisateurEntite $membership;
    private KernelBrowser $client;
    private array $database;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $sequence = $this->createMock(\App\Service\Sequence\SequenceNumberManager::class);
        $counter = 0;
        $sequence->method('next')->willReturnCallback(function ($type, $entite, $year = null) use (&$counter) {
            return [$year ?? 2026, ++$counter];
        });
        self::getContainer()->set(\App\Service\Sequence\SequenceNumberManager::class, $sequence);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@delegation.test')->setNom('Admin')->setPrenom('Test')->setPassword('unused');
        $this->em->persist($this->admin);
        $this->sales = (new Utilisateur())->setEmail('sales@delegation.test')->setNom('Commercial')->setPrenom('Test')->setPassword('unused');
        $this->em->persist($this->sales);
        $this->entite = (new Entite())->setNom('Organisme commercial')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($this->entite);
        $this->em->flush();
        $this->sales->setEntite($this->entite);
        $this->admin->setEntite($this->entite);
        $this->membership = (new UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->sales)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_COMMERCIAL]);
        $this->em->persist($this->membership);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->admin)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setCode('PORTAL')->setName('Portail')->setMaxApprenantsAn(100);
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setPlan($plan)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->sales);
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) {
                unset($GLOBALS['_' . $name]['DATABASE_URL']);
            } else {
                $GLOBALS['_' . $name]['DATABASE_URL'] = $this->database[$i];
            }
        }
    }
    private function url(string $route, array $args = []): string
    {
        return self::getContainer()->get('router')->generate($route, $args + ['entite' => $this->entite->getId(), 'espace' => 'commercial']);
    }
    private function prospect(string $name): Prospect
    {
        $p = (new Prospect())->setEntite($this->entite)->setCreateur($this->admin)->setPrenom('Contact')->setNom($name);
        $this->em->persist($p);
        $this->em->flush();
        return $p;
    }
    private function grant(string $module, int $id, string $level = 'edit'): DossierDelegation
    {
        $g = self::getContainer()->get(DossierAccess::class)->assign($this->em->find(UtilisateurEntite::class, $this->membership->getId()), $module, $id, $level, $this->em->find(Utilisateur::class, $this->admin->getId()));
        $this->em->flush();
        return $g;
    }
    public function testCommercialOnlySeesAssignedRecordsAndCanUpdateThem(): void
    {
        $assigned = $this->prospect('Visible');
        $hidden = $this->prospect('Confidentiel');
        $grant = $this->grant('prospects', $assigned->getId());
        $this->client->request('GET', $this->url('app_portail_commercial_dashboard'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_portail_list', ['module' => 'prospects']));
        self::assertStringContainsString('Visible', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Confidentiel', $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $this->url('app_portail_show', ['module' => 'prospects', 'id' => $assigned->getId()]));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[nom]'] = 'Modifié';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('Modifié', $this->em->getConnection()->fetchOne('SELECT nom FROM prospect WHERE id = ?', [$assigned->getId()]));
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->url('app_portail_show', ['module' => 'prospects', 'id' => $hidden->getId()]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/fr/administrateur/' . $this->entite->getId() . '/utilisateur');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->em->remove($this->em->find(DossierDelegation::class, $grant->getId()));
        $this->em->flush();
        $this->client->request('GET', $this->url('app_portail_show', ['module' => 'prospects', 'id' => $assigned->getId()]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
    public function testCommercialCanCreateAssignedProspectAndCannotForgeRole(): void
    {
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'prospects']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[prenom]'] = 'Nouveau';
        $form['dossier[nom]'] = 'Prospect';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $record = $this->em->getRepository(Prospect::class)->findOneBy(['nom' => 'Prospect']);
        self::assertNotNull($record);
        self::assertNotNull($this->em->getRepository(DossierDelegation::class)->findOneBy(['membership' => $this->membership, 'module' => 'prospects', 'recordId' => $record->getId()]));
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'clients']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[prenom]'] = 'Client';
        $form['dossier[nom]'] = 'Test';
        $form['dossier[email]'] = 'client@delegation.test';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $u = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'client@delegation.test']);
        self::assertSame([UtilisateurEntite::TENANT_STAGIAIRE], $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $u])->getRoles());
    }
    public function testReadOnlyOpcoAndCrossTenantAccessAreEnforced(): void
    {
        $company = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Réservée');
        $this->em->persist($company);
        $this->em->flush();
        $this->grant('entreprises', $company->getId(), 'read');
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url('app_portail_show', ['module' => 'entreprises', 'id' => $company->getId()]), ['dossier' => ['raisonSociale' => 'Interdit']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $other = (new Entite())->setNom('Autre entité')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($other);
        $this->em->flush();
        $this->client->request('GET', $this->url('app_portail_dashboard', ['entite' => $other->getId()]));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $membership = $this->em->find(UtilisateurEntite::class, $this->membership->getId());
        $membership->setRoles([UtilisateurEntite::TENANT_OPCO]);
        $this->em->flush();
        $this->client->request('GET', $this->url('app_portail_opco_dashboard', ['espace' => 'opco']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Nouveau prospect', $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->url('app_portail_list', ['espace' => 'opco', 'module' => 'prospects']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_portail_new', ['espace' => 'opco', 'module' => 'factures']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $this->url('app_portail_dashboard'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
    public function testAdminCanAssignAndRevokeWithoutGivingAdministrativeRights(): void
    {
        $prospect = $this->prospect('À attribuer');
        $this->client->loginUser($this->admin);
        $url = $this->url('app_administrateur_delegation_index', ['module' => 'prospects']);
        $page = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->selectButton('Enregistrer l’attribution')->form();
        $form['membership']->select((string) $this->membership->getId());
        $form['record']->select((string) $prospect->getId());
        $form['level']->select('edit');
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $url);
        $this->client->submit($page->selectButton('Retirer l’accès')->form());
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(DossierDelegation::class)->count([]));
    }
    public function testOperationalModulesCreateAndExposeNoOtherTenantChoices(): void
    {
        foreach (['sites' => ['nom' => 'Site commercial'], 'formations' => ['titre' => 'Formation commerciale', 'duree' => '2'], 'entreprises' => ['raisonSociale' => 'Entreprise commerciale']] as $module => $fields) {
            $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => $module]));
            $form = $page->filter('form[name="dossier"]')->form();
            foreach ($fields as $field => $value) {
                $form['dossier[' . $field . ']'] = $value;
            }
            $this->client->submit($form);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
            $this->client->followRedirect();
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'sessions']));
        $form = $page->filter('form[name="dossier"]')->form();
        $site = $this->em->getRepository(\App\Entity\Site::class)->findOneBy(['nom' => 'Site commercial']);
        $formation = $this->em->getRepository(\App\Entity\Formation::class)->findOneBy(['titre' => 'Formation commerciale']);
        $form['dossier[site]']->select((string) $site->getId());
        $form['dossier[formation]']->select((string) $formation->getId());
        $form['dossier[start]'] = '2026-12-01T08:30';
        $form['dossier[end]'] = '2026-12-01T17:00';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('01/12/2026', $this->client->getResponse()->getContent());
    }
    public function testTrainerAndRegistrationCreationAndAdditionalSchedule(): void
    {
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('site');
        $formation = (new \App\Entity\Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation')->setSlug('formation');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('TEST-SESSION')->setFormation($formation);
        foreach ([$site, $formation, $session] as $record) {
            $this->em->persist($record);
        }
        $this->em->flush();
        $this->grant('sessions', $session->getId());
        $this->grant('clients', $this->membership->getId());
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'formateurs']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[utilisateur]']->select((string) $this->sales->getId());
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'inscriptions']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[session]']->select((string) $session->getId());
        $form['dossier[stagiaire]']->select((string) $this->sales->getId());
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Entity\DossierInscription::class)->count([]));
        $page = $this->client->request('GET', $this->url('app_portail_schedule', ['id' => $session->getId()]));
        $form = $page->filter('form[name="form"]')->form();
        $form['form[dateDebut]'] = '2026-12-01T08:30';
        $form['form[dateFin]'] = '2026-12-01T17:00';
        $form['form[pauseMinutes]'] = '60';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Entity\SessionJour::class)->count([]));
    }
    public function testFinancialIssueUsesAssignedRecipientAndCalculatesAmounts(): void
    {
        $company = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client attribué');
        $this->em->persist($company);
        $this->em->flush();
        $this->grant('entreprises', $company->getId());
        foreach (['devis' => Devis::class, 'factures' => Facture::class] as $module => $class) {
            $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => $module]));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $form = $page->filter('form[name="document"]')->form();
            $form['document[company]']->select((string) $company->getId());
            $form['document[lines][0][label]'] = 'Formation';
            $form['document[lines][0][quantity]'] = '2';
            $form['document[lines][0][price]'] = '150';
            $form['document[lines][0][vat]']->select('20');
            $this->client->submit($form);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
            $record = $this->em->getRepository($class)->findOneBy(['entite' => $this->entite]);
            self::assertSame(30000, $record->getMontantHtCents());
            self::assertSame(36000, $record->getMontantTtcCents());
            self::assertCount(1, $record->getLignes());
            self::assertNotNull($this->em->getRepository(DossierDelegation::class)->findOneBy(['module' => $module, 'recordId' => $record->getId(), 'membership' => $this->membership]));
        }
    }
    public function testConventionsCanBeIssuedAndAllDocumentTemplatesRender(): void
    {
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createPortrait')->willReturnCallback(fn($html, $name) => new \Symfony\Component\HttpFoundation\Response($html));
        $pdf->method('streamPdfFromHtml')->willReturnCallback(fn($html, $name) => new \Symfony\Component\HttpFoundation\Response($html));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $company = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Destinataire');
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('site');
        $formation = (new \App\Entity\Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation')->setSlug('formation');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('TEST-CONVENTION')->setFormation($formation);
        $inscription = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->sales)->setEntreprise($company);
        foreach ([$company, $site, $formation, $session, $inscription] as $record) {
            $this->em->persist($record);
        }
        $this->em->flush();
        foreach (['entreprises' => $company, 'sessions' => $session, 'inscriptions' => $inscription] as $module => $record) {
            $this->grant($module, $record->getId());
        }
        foreach (['conventions', 'devis', 'factures'] as $module) {
            $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => $module]));
            $form = $page->filter('form[name="document"]')->form();
            $form['document[company]']->select((string) $company->getId());
            if ($module === 'conventions') {
                $form['document[session]']->select((string) $session->getId());
                $form['document[participants]']->select([(string) $inscription->getId()]);
            }
            $form['document[lines][0][label]'] = 'Prestation de formation';
            $form['document[lines][0][price]'] = '500';
            $result = $this->client->submit($form);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $result->filter('form')->count() ? $result->filter('form')->text() : '');
            $page = $this->client->followRedirect();
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->client->click($page->selectLink('Télécharger le PDF')->link());
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('Destinataire', $this->client->getResponse()->getContent());
        }
    }
    public function testForgedAssignmentsAndInvalidCsrfCannotCreateDocuments(): void
    {
        $hidden = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Non attribuée');
        $this->em->persist($hidden);
        $this->em->flush();
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'factures']));
        $form = $page->filter('form[name="document"]')->form();
        $values = $form->getPhpValues();
        $values['document']['company'] = $hidden->getId();
        $values['document']['lines'][0]['label'] = 'Interdit';
        $this->client->request('POST', $this->url('app_portail_new', ['module' => 'factures']), $values);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
        $this->grant('entreprises', $hidden->getId());
        $values['document']['_token'] = 'invalid';
        $this->client->request('POST', $this->url('app_portail_new', ['module' => 'factures']), $values);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
    }
    public function testActiveAdministratorCanOpenPortalsWithoutBusinessRoles(): void
    {
        $this->client->loginUser($this->admin);
        $hidden = $this->prospect('Dossier sans attribution');
        foreach (['commercial', 'opco'] as $space) {
            $this->client->request('GET', $this->url('app_portail_dashboard', ['espace' => $space]));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('GET', $this->url('app_administrateur_delegation_index'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->url('app_portail_show', ['module' => 'prospects', 'id' => $hidden->getId()]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $adminMembership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->admin->getId(), 'entite' => $this->entite->getId()]);
        $adminMembership->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        $this->client->request('GET', $this->url('app_portail_dashboard'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
    private function company(string $name): Entreprise
    {
        $company = (new Entreprise())->setEntite($this->em->find(Entite::class, $this->entite->getId()))->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))->setRaisonSociale($name)->setEmail('client@portfolio.test');
        $this->em->persist($company);
        $this->em->flush();
        return $company;
    }
    public function testCompanyPortfolioInheritsAndRevocationRemovesOldChildGrants(): void
    {
        $company = $this->company('Mon portefeuille');
        $other = $this->company('Client confidentiel');
        $quote = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-OWN')->setEntrepriseDestinataire($company);
        $hidden = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-HIDDEN')->setEntrepriseDestinataire($other);
        foreach ([$quote, $hidden] as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
        $companyGrant = $this->grant('entreprises', $company->getId());
        $this->grant('devis', $quote->getId());
        $access = self::getContainer()->get(DossierAccess::class);
        self::assertSame('edit', $access->grant($this->membership, 'devis', $quote->getId())->getAccessLevel());
        self::assertCount(1, $access->rows($this->membership, 'devis'));
        $page = $this->client->request('GET', $this->url('app_portail_show', ['module' => 'entreprises', 'id' => $company->getId()]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('DEV-OWN', $page->text());
        self::assertStringNotContainsString('DEV-HIDDEN', $page->text());
        $this->em->remove($this->em->find(DossierDelegation::class, $companyGrant->getId()));
        $this->em->flush();
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->url('app_portail_show', ['module' => 'devis', 'id' => $quote->getId()]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
    public function testSharedSessionIsReadOnlyAndTrainerContractDoesNotLeak(): void
    {
        $own = $this->company('Client A');
        $other = $this->company('Client B');
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('shared');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode('SHARED')->setSite($site)->setEntrepriseCliente($own);
        $registration = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->admin)->setEntreprise($other);
        $session->addInscription($registration);
        $trainer = (new \App\Entity\Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->sales);
        $contract = (new \App\Entity\ContratFormateur())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setFormateur($trainer)->setNumero('CF-SHARED');
        foreach ([$site, $session, $registration, $trainer, $contract] as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
        $this->grant('entreprises', $own->getId());
        $access = self::getContainer()->get(DossierAccess::class);
        self::assertSame('read', $access->grant($this->membership, 'sessions', $session->getId())->getAccessLevel());
        self::assertCount(0, $access->rows($this->membership, 'inscriptions'));
        self::assertCount(0, $access->rows($this->membership, 'contrats-formateurs'));
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url('app_portail_show', ['module' => 'sessions', 'id' => $session->getId()]), ['dossier' => ['capacite' => 99]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
    public function testDraftQuoteEditDeletionAndIssuedInvoiceProtection(): void
    {
        $company = $this->company('Édition client');
        $this->grant('entreprises', $company->getId());
        $quote = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setDateValidite(new \DateTimeImmutable('+30 days'))->setRemiseGlobalePourcent(5)->setNumero('DRAFT-EDIT')->setEntrepriseDestinataire($company);
        $line = (new \App\Entity\LigneDevis())->setEntite($this->entite)->setCreateur($this->admin)->setLabel('Ancien')->setRemisePourcent(10)->setQte(1)->setPuHtCents(10000)->setTva(20);
        $quote->addLigne($line);
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(12000)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setNumero('F-LOCKED')->setEntrepriseDestinataire($company);
        foreach ([$quote, $line, $invoice] as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
        $page = $this->client->request('GET', $this->url('app_portail_edit_document', ['module' => 'devis', 'id' => $quote->getId()]));
        $form = $page->filter('form[name="document"]')->form();
        $form['document[lines][0][price]'] = '250';
        $form['document[lines][0][label]'] = 'Modifié';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $saved = $this->em->find(Devis::class, $quote->getId());
        self::assertSame(21375, $saved->getMontantHtCents());
        self::assertSame(4275, $saved->getMontantTvaCents());
        self::assertSame('DRAFT-EDIT', $saved->getNumero());
        self::assertCount(1, $saved->getLignes());
        $page = $this->client->followRedirect();
        $this->client->submit($page->selectButton('Confirmer la suppression')->form());
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em->find(Devis::class, $quote->getId()));
        self::assertNotNull(self::getContainer()->get(\App\Service\Delegation\CommercialRules::class)->deleteBlock($this->em->find(Facture::class, $invoice->getId())));
    }
    public function testNewTrainerContractAndFollowupFormsWork(): void
    {
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createPortrait')->willReturnCallback(fn($html, $name) => new \Symfony\Component\HttpFoundation\Response($html));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'formateurs']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[prenom]'] = 'Nouveau';
        $form['dossier[nom]'] = 'Formateur';
        $form['dossier[email]'] = 'trainer@portfolio.test';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $trainer = $this->em->getRepository(\App\Entity\Formateur::class)->findOneBy([]);
        self::assertSame('trainer@portfolio.test', $trainer->getUtilisateur()->getEmail());
        $company = $this->company('Contrat client');
        $this->grant('entreprises', $company->getId());
        $site = (new \App\Entity\Site())->setEntite($company->getEntite())->setCreateur($company->getCreateur())->setNom('Site')->setSlug('contract');
        $session = (new \App\Entity\Session())->setEntite($company->getEntite())->setCreateur($company->getCreateur())->setCode('CONTRACT')->setSite($site)->setEntrepriseCliente($company)->setFormateur($trainer);
        foreach ([$site, $session] as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'contrats-formateurs']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form[name="contrat_formateur"]')->form();
        $form['contrat_formateur[session]']->select((string) $session->getId());
        $form['contrat_formateur[formateur]']->select((string) $trainer->getId());
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $page = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->click($page->selectLink('Télécharger le PDF')->link());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Nouveau', $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $this->url('app_portail_activity', ['module' => 'entreprises', 'id' => $company->getId()]));
        $form = $page->filter('form[name="form"]')->form();
        $form['form[title]'] = 'Appeler le client';
        $form['form[content]'] = 'Préparer son projet';
        $form['form[dueAt]'] = '2026-12-01T09:00';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $this->url('app_portail_dashboard'));
        self::assertStringContainsString('Appeler le client', $page->text());
    }
    public function testEmailUsesClientAddressAndPdfAndDoesNotResend(): void
    {
        $mailer = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function ($email) {
            return $email->getTo()[0]->getAddress() === 'client@portfolio.test' && count($email->getAttachments()) === 1;
        }));
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $mailer);
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createPortraitBytes')->willReturn('%PDF-test');
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $company = $this->company('Facturé');
        $this->grant('entreprises', $company->getId());
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(12000)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setNumero('F-MAIL')->setEntrepriseDestinataire($company);
        $this->em->persist($invoice);
        $this->em->flush();
        $url = $this->url('app_portail_compose', ['module' => 'factures', 'id' => $invoice->getId()]);
        $page = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form[name="form"]')->form();
        $values = $form->getPhpValues();
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url, $values);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Entity\EmailLog::class)->count(['status' => 'SENT']));
    }
    public function testLearnerAttachmentDetachmentAndIdentityRemainScoped(): void
    {
        $company = $this->company('Portefeuille stagiaires');
        $this->grant('entreprises', $company->getId());
        $page = $this->client->request('GET', $this->url('app_portail_new', ['module' => 'clients']));
        $form = $page->filter('form[name="dossier"]')->form();
        $form['dossier[prenom]'] = 'Alice';
        $form['dossier[nom]'] = 'Apprenante';
        $form['dossier[email]'] = 'alice@portfolio.test';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'alice@portfolio.test']);
        $userId = $user->getId();
        $learner = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user]);
        $learnerId = $learner->getId();
        $url = $this->url('app_portail_company_learners', ['id' => $company->getId()]);
        foreach (['attach', 'detach'] as $operation) {
            $page = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $form = $page->filter('form[name="form"]')->form();
            $form['form[learner]']->select((string) $learnerId);
            $form['form[operation]']->select($operation);
            $this->client->submit($form);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertNotNull($this->em->find(Utilisateur::class, $userId));
            self::assertCount($operation === 'attach' ? 1 : 0, $this->em->find(Utilisateur::class, $userId)->getEntreprisesAssociees());
        }
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->url('app_portail_show', ['module' => 'clients', 'id' => $learnerId]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
    public function testEmptySessionCanBeEditedAndDeletedButCsrfIsMandatory(): void
    {
        $company = $this->company('Session client');
        $this->grant('entreprises', $company->getId());
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('delete-site');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setCode('DRAFT-SESSION')->setSite($site)->setEntrepriseCliente($company);
        $day = (new \App\Entity\SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setDateDebut(new \DateTimeImmutable('2026-12-01 08:30'))->setDateFin(new \DateTimeImmutable('2026-12-01 17:00'));
        $session->addJour($day);
        foreach ([$site, $session, $day] as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
        $sessionId = $session->getId();
        $dayId = $day->getId();
        $page = $this->client->request('GET', $this->url('app_portail_schedule_edit', ['id' => $sessionId, 'dayId' => $dayId]));
        $form = $page->filter('form[name="form"]')->form();
        $form['form[dateFin]'] = '2026-12-01T16:00';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('16:00', $this->em->find(\App\Entity\SessionJour::class, $dayId)->getDateFin()->format('H:i'));
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url('app_portail_delete', ['module' => 'sessions', 'id' => $sessionId]), ['_token' => 'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $this->url('app_portail_show', ['module' => 'sessions', 'id' => $sessionId]));
        $this->client->submit($page->selectButton('Confirmer la suppression')->form());
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em->find(\App\Entity\Session::class, $sessionId));
        self::assertNull($this->em->find(\App\Entity\SessionJour::class, $dayId));
    }
    public function testFailedMailCanBeRetriedWithoutLosingFailureState(): void
    {
        $company = $this->company('Relance client');
        $mailer = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $calls = 0;
        $mailer->expects(self::exactly(2))->method('send')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Test transport unavailable');
            }
        });
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $mailer);
        $service = self::getContainer()->get(\App\Service\Delegation\CommercialMail::class);
        try {
            $service->send($company, 'entreprises', $this->sales, 'Relance', 'Bonjour', 'test-retry');
            self::fail('Failure expected');
        } catch (\DomainException $e) {
            self::assertStringContainsString('échoué', $e->getMessage());
        }
        self::assertSame(1, $this->em->getRepository(\App\Entity\EmailLog::class)->count(['status' => 'FAILED']));
        self::assertTrue($service->send($company, 'entreprises', $this->sales, 'Relance', 'Bonjour', 'test-retry'));
        self::assertSame(1, $this->em->getRepository(\App\Entity\EmailLog::class)->count(['status' => 'SENT']));
    }
}
