<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Service\Entreprise\EntreprisePortalContext;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};
use Symfony\Component\Mailer\MailerInterface;

final class EntrepriseClientEditorTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Entite $tenant;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = $this->account('admin@client.test', 'Admin');
        $this->tenant = (new Entite())->setNom('Organisme client')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($this->tenant); $this->em->flush();
        $this->admin->setEntite($this->tenant);
        $this->membership($this->admin, UtilisateurEntite::TENANT_ADMIN);
        $plan = (new Plan())->setCode('CLIENT_EDITOR')->setName('Client editor')->setMaxApprenantsAn(100)->setMaxUtilisateurs(20)->setMaxEntreprises(20);
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->tenant)->setPlan($plan)->setStatus('active'));
        $this->learner = $this->account('learner@client.test', 'Camille')->setEntite($this->tenant);
        $this->membership($this->learner, UtilisateurEntite::TENANT_STAGIAIRE);
        $this->em->flush();
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testCompanyCanBeCreatedWithoutRepresentativeAndWithAssociatedUsers(): void
    {
        $this->submit(['associatedUsers' => [(string) $this->learner->getId()]]);
        self::assertResponseRedirects();
        $company = $this->company();
        self::assertNull($company->getRepresentant());
        self::assertTrue($this->learner->getEntreprisesAssociees()->contains($company));
        self::assertNull($this->learner->getEntreprise());
        self::assertFalse(EntreprisePortalContext::canRepresent($this->learner, $company));
        self::assertFalse($this->membershipOf($this->learner)->hasRole(UtilisateurEntite::TENANT_ENTREPRISE));
        $page = $this->client->request('GET', $this->base().'/modifier/'.$company->getId());
        self::assertSame('later', $page->filter('form[name="entreprise"]')->form()['entreprise[contactMode]']->getValue());
        self::assertSame([(string) $this->learner->getId()], $page->filter('form[name="entreprise"]')->form()['entreprise[associatedUsers]']->getValue());
    }

    public function testNewRepresentativeIsCreatedWithCompanyRole(): void
    {
        $this->submit(['contactMode' => 'new', 'newContact' => [
            'email' => 'New.Contact@client.test', 'prenom' => 'Lou', 'nom' => 'Martin', 'telephone' => '0612345678', 'civilite' => 'Madame',
        ]]);
        self::assertResponseRedirects();
        $contact = $this->company()->getRepresentant();
        self::assertSame('new.contact@client.test', $contact->getEmail());
        self::assertSame('Lou', $contact->getPrenom());
        self::assertSame('0612345678', $contact->getTelephone());
        self::assertNotEmpty($contact->getPassword());
        self::assertTrue($this->membershipOf($contact)->hasRole(UtilisateurEntite::TENANT_ENTREPRISE));
        self::assertTrue(EntreprisePortalContext::canRepresent($contact, $this->company()));
        self::assertNull($contact->getEntreprise());
        $page = $this->client->request('GET', $this->base().'/modifier/'.$this->company()->getId());
        self::assertSame('existing', $page->filter('form[name="entreprise"]')->form()['entreprise[contactMode]']->getValue());
    }

    public function testExistingSelectionKeepsRolesAndIdentity(): void
    {
        $this->submit(['contactMode' => 'existing', 'representant' => (string) $this->learner->getId()]);
        self::assertResponseRedirects();
        self::assertSame($this->learner->getId(), $this->company()->getRepresentant()->getId());
        self::assertSame('Camille', $this->learner->getPrenom());
        self::assertTrue($this->membershipOf($this->learner)->hasRole(UtilisateurEntite::TENANT_STAGIAIRE));
        self::assertTrue($this->membershipOf($this->learner)->hasRole(UtilisateurEntite::TENANT_ENTREPRISE));
    }

    public function testEmailReuseRequiresConfirmationAndDoesNotOverwriteAccount(): void
    {
        $data = ['contactMode' => 'new', 'newContact' => ['email' => 'LEARNER@client.test', 'prenom' => 'Overwrite', 'nom' => 'Ignored']];
        $this->submit($data);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Confirmez l’utilisation', $this->client->getResponse()->getContent());
        self::assertNull($this->company());
        $data['newContact']['reuse'] = '1';
        $this->submit($data);
        self::assertResponseRedirects();
        self::assertSame('Camille', $this->company()->getRepresentant()->getPrenom());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testForeignAccountIsNeitherDisclosedNorAttached(): void
    {
        $foreign = $this->account('private@client.test', 'Private'); $this->em->flush();
        $this->client->request('GET', $this->base().'/rechercher-contact', ['email' => $foreign->getEmail()]);
        self::assertSame(['found' => false], json_decode($this->client->getResponse()->getContent(), true));
        $this->submit(['contactMode' => 'new', 'newContact' => ['email' => $foreign->getEmail(), 'reuse' => '1']]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->company());
        self::assertStringContainsString('ne peut pas être associé', $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->base().'/rechercher-contact', ['email' => 'LEARNER@client.test']);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['found']); self::assertSame('Camille', $data['contact']['prenom']);
    }

    public function testInvalidNewContactDoesNotCreateAnyRecords(): void
    {
        $this->submit(['contactMode' => 'new', 'newContact' => ['email' => 'new@client.test']]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
        self::assertStringContainsString('prénom et le nom', $this->client->getResponse()->getContent());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testLaterIgnoresStaleContactValuesAndRequiresCompanyName(): void
    {
        $this->submit(['raisonSociale' => ' ', 'newContact' => ['email' => 'invalid']]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
        $this->submit(['newContact' => ['email' => 'invalid'], 'representant' => (string) $this->learner->getId()]);
        self::assertResponseRedirects(); self::assertNull($this->company()->getRepresentant());
    }

    public function testRemovingAssociationPreservesOtherCompaniesAndHistoricRoles(): void
    {
        $other = (new Entreprise())->setEntite($this->tenant)->setCreateur($this->admin)->setRaisonSociale('Autre client');
        $this->em->persist($other); $this->learner->addEntreprisesAssociee($other); $this->em->flush();
        $this->submit(['associatedUsers' => [(string) $this->learner->getId()]]);
        self::assertResponseRedirects();
        $company = $this->company();
        $this->learner->setEntreprise($company); $this->em->flush();
        $this->submit(['associatedUsers' => []], $company);
        self::assertResponseRedirects();
        self::assertFalse($this->learner->getEntreprisesAssociees()->contains($company));
        self::assertContains($other->getId(), $this->learner->getEntreprisesAssociees()->map(fn(Entreprise $e) => $e->getId())->toArray());
        self::assertNull($this->learner->getEntreprise());
        self::assertTrue($this->membershipOf($this->learner)->hasRole(UtilisateurEntite::TENANT_STAGIAIRE));
    }

    public function testDuplicateCompanyDoesNotCreateContact(): void
    {
        $this->submit([]); self::assertResponseRedirects();
        $this->submit(['contactMode' => 'new', 'newContact' => ['email' => 'notcreated@client.test', 'nom' => 'Martin', 'prenom' => 'Lou']]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(Entreprise::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testSuspendedRepresentativeRemainsSuspended(): void
    {
        $this->membershipOf($this->learner)->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        $this->submit(['contactMode' => 'existing', 'representant' => (string) $this->learner->getId()]);
        self::assertResponseRedirects();
        self::assertSame(UtilisateurEntite::STATUS_SUSPENDED, $this->membershipOf($this->learner)->getStatus());
    }

    public function testForgedForeignSelectionsAreRejected(): void
    {
        $foreign = $this->account('foreign-selection@client.test', 'Other'); $this->em->flush();
        $this->submit(['associatedUsers' => [(string) $foreign->getId()]]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
        $this->submit(['contactMode' => 'existing', 'representant' => (string) $foreign->getId()]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
    }

    public function testMissingExistingSelectionAndInvalidEmailAreRejected(): void
    {
        $this->submit(['contactMode' => 'existing']);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
        $this->submit(['contactMode' => 'new', 'newContact' => ['email' => 'invalid-address', 'prenom' => 'Lou', 'nom' => 'Martin']]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
    }

    public function testCompanyQuotaPreventsAccountCreationAsWell(): void
    {
        $this->em->getRepository(Plan::class)->findOneBy(['code' => 'CLIENT_EDITOR'])->setMaxEntreprises(1);
        $this->em->persist((new Entreprise())->setEntite($this->tenant)->setCreateur($this->admin)->setRaisonSociale('Déjà présente'));
        $this->em->flush();
        $this->submit(['contactMode' => 'new', 'newContact' => ['email' => 'quota@client.test', 'prenom' => 'Lou', 'nom' => 'Martin']]);
        self::assertResponseIsSuccessful(); self::assertNull($this->company());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testPromotingTraineeDoesNotGrantAccessToTheirOtherEmployer(): void
    {
        $employer = (new Entreprise())->setEntite($this->tenant)->setCreateur($this->admin)->setRaisonSociale('Employeur historique');
        $this->em->persist($employer); $this->learner->setEntreprise($employer); $this->em->flush();
        $this->submit(['contactMode' => 'existing', 'representant' => (string) $this->learner->getId()]);
        self::assertResponseRedirects();
        self::assertNull($this->learner->getEntreprise());
        self::assertContains($employer->getId(), $this->learner->getEntreprisesAssociees()->map(fn(Entreprise $e) => $e->getId())->toArray());
        self::assertFalse(EntreprisePortalContext::canRepresent($this->learner, $employer));
        self::assertTrue(EntreprisePortalContext::canRepresent($this->learner, $this->company()));
    }

    private function submit(array $values, ?Entreprise $company = null): void
    {
        $page = $this->client->request('GET', $this->base().($company ? '/modifier/'.$company->getId() : '/ajouter'));
        self::assertResponseIsSuccessful();
        $form = $page->filter('form[name="entreprise"]')->form();
        $data = array_replace_recursive($form->getPhpValues()['entreprise'], ['raisonSociale' => 'Client Exemple'], $values);
        // A deselected multi-select must replace its previous complete value.
        if (array_key_exists('associatedUsers', $values)) $data['associatedUsers'] = $values['associatedUsers'];
        $this->client->request('POST', $form->getUri(), ['entreprise' => $data]);
        $this->learner = $this->em->find(Utilisateur::class, $this->learner->getId());
        $this->admin = $this->em->find(Utilisateur::class, $this->admin->getId());
        $this->tenant = $this->em->find(Entite::class, $this->tenant->getId());
    }
    private function base(): string { return '/fr/administrateur/'.$this->tenant->getId().'/entreprise'; }
    private function company(): ?Entreprise { return $this->em->getRepository(Entreprise::class)->findOneBy(['raisonSociale' => 'Client Exemple']); }
    private function account(string $email, string $prenom): Utilisateur {
        $user = (new Utilisateur())->setEmail($email)->setPassword('unused')->setNom('Test')->setPrenom($prenom);
        $this->em->persist($user); return $user;
    }
    private function membership(Utilisateur $user, string $role): void {
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->tenant)->setCreateur($this->admin)->setRoles([$role]));
    }
    private function membershipOf(Utilisateur $user): UtilisateurEntite {
        return $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $this->tenant]);
    }
}
