<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Inscription, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** All writes use a disposable SQLite database; no activation or other real email. */
final class UtilisateurIdentityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $mailer = $this->createMock(MailerInterface::class); $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = $this->user('identity-admin@example.test', 'Admin');
        $this->entite = (new Entite())->setNom('Organisme identité')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($this->entite); $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->membership($this->admin, $this->entite, UtilisateurEntite::TENANT_ADMIN);
        $plan = (new Plan())->setCode('IDENTITY_TEST')->setName('Identité test')->setMaxApprenantsAn(100); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setPlan($plan)->setStatus('active'));
        $this->learner = $this->user('learner-identity@example.test', 'JAson')->setEntite($this->entite)->setCreateur($this->admin);
        $this->membership($this->learner, $this->entite);
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('identity-site'); $this->em->persist($site);
        foreach (['A', 'B'] as $code) {
            $session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('IDENTITY-'.$code);
            $this->em->persist($session);
            $this->em->persist((new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($this->learner));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testRolesCanBeAddedToVerifiedEnrolledAccount(): void
    {
        $this->learner->setIsVerified(true); $this->em->flush();
        $id = $this->learner->getId(); $client = $this->client();
        $page = $client->request('GET', $this->editUrl($id));
        $form = $page->filter('form[name="utilisateur"]')->form();
        $roles = [UtilisateurEntite::TENANT_STAGIAIRE, UtilisateurEntite::TENANT_ENTREPRISE,
            UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_OPCO, UtilisateurEntite::TENANT_OF];
        $form['utilisateur[ueRoles]']->select($roles);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $saved = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$id, 'entite'=>$this->entite->getId()]);
        self::assertEqualsCanonicalizing($roles, $saved->getRoles());
        self::assertFalse($saved->isTenantAdmin());
        self::assertCount(2, $saved->getUtilisateur()->getInscriptions());
    }

    public function testMultipleCompanyAssociationsCanBeSavedWithoutChangingPrimary(): void
    {
        $companies = [];
        foreach (['Principale', 'Deuxième', 'Troisième'] as $name) {
            $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale($name);
            $this->em->persist($company); $companies[] = $company;
        }
        $this->learner->setEntreprise($companies[0]); $this->em->flush();
        $ids = array_map(fn($c) => $c->getId(), $companies);
        $id = $this->learner->getId();
        $client = $this->client();
        $page = $client->request('GET', $this->editUrl($id));
        $form = $page->filter('form[name="utilisateur"]')->form();
        $form['utilisateur[entreprisesAssociees]']->select([(string) $ids[1], (string) $ids[2]]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $saved = $this->em->find(Utilisateur::class, $id);
        self::assertSame($ids[0], $saved->getEntreprise()->getId());
        self::assertCount(2, $saved->getEntreprisesAssociees());
        foreach ($saved->getEntreprisesAssociees() as $company) self::assertNull($company->getRepresentant());
    }

    public function testSwappingPrimaryAndAssociatedCompaniesPreservesBothCompanyRecords(): void
    {
        $companies = [];
        foreach (['Generale du solaire', 'HOLDING DU SOLAIRE'] as $index => $name) {
            $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale($name)->setVille('Ville '.$index)->setEmail('company'.$index.'@example.test');
            $this->em->persist($company); $companies[] = $company;
        }
        $this->learner->setEntreprise($companies[0])->addEntreprisesAssociee($companies[1]);
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$this->learner,'entite'=>$this->entite]);
        $membership->setRoles([UtilisateurEntite::TENANT_STAGIAIRE, UtilisateurEntite::TENANT_ENTREPRISE]);
        $this->em->flush();
        $ids = array_map(fn($c) => $c->getId(), $companies);
        $id = $this->learner->getId(); $client = $this->client();
        foreach ([[1,0],[0,1]] as [$primary,$secondary]) {
            $page = $client->request('GET', $this->editUrl($id));
            $form = $page->filter('form[name="utilisateur"]')->form();
            $form['utilisateur[entreprise]']->select((string)$ids[$primary]);
            $form['utilisateur[entreprisesAssociees]']->select([(string)$ids[$secondary]]);
            $client->submit($form);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $this->em->clear();
            $saved = $this->em->find(Utilisateur::class,$id);
            self::assertSame($ids[$primary],$saved->getEntreprise()->getId());
            self::assertSame([$ids[$secondary]],array_map(fn($c)=>$c->getId(),$saved->getEntreprisesAssociees()->toArray()));
            foreach ($ids as $index => $companyId) {
                $company = $this->em->find(\App\Entity\Entreprise::class,$companyId);
                self::assertSame(['Generale du solaire','HOLDING DU SOLAIRE'][$index],$company->getRaisonSociale());
                self::assertSame('Ville '.$index,$company->getVille());
                self::assertSame('company'.$index.'@example.test',$company->getEmail());
            }
            self::assertSame(2,$this->em->getRepository(\App\Entity\Entreprise::class)->count([]));
        }
    }

    public function testEnrolledVerifiedIdentityCanBeCorrectedWithoutChangingAccountOrEnrollments(): void
    {
        $this->learner->setIsVerified(true); $this->em->flush();
        $id = $this->learner->getId();
        $client = $this->client(); $crawler = $client->request('GET', $this->editUrl($id));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($crawler->filter('#utilisateur_prenom')->attr('disabled'));
        self::assertNotNull($crawler->filter('#utilisateur_email')->attr('disabled'));
        $form = $crawler->filter('form[name="utilisateur"]')->form();
        $values = $form->getPhpValues();
        $values['utilisateur']['prenom'] = 'Jason';
        // A forged disabled email must not detach the identity from its login.
        $values['utilisateur']['email'] = 'replacement@example.test';
        $client->request('POST', $this->editUrl($id), $values);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear(); $saved = $this->em->find(Utilisateur::class, $id);
        self::assertSame('Jason', $saved->getPrenom());
        self::assertSame('learner-identity@example.test', $saved->getEmail());
        self::assertTrue($saved->isVerified());
        self::assertCount(2, $saved->getInscriptions());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testUserWithTwoEnrollmentsAppearsOnlyOnceInUserListing(): void
    {
        $id = $this->learner->getId();
        $this->em->clear();
        $client = $this->client();
        $client->request('POST', '/fr/administrateur/'.$this->entite->getId().'/utilisateur/ajax', ['draw'=>1, 'search'=>['value'=>'learner-identity@example.test']]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $json = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['recordsFiltered']); self::assertCount(1, $json['data']);
        self::assertSame($id, $json['data'][0]['id']); self::assertSame(2, $json['data'][0]['inscriptions']);
    }

    public function testEmailNormalizationRejectsDuplicatesButExcludesCurrentIdentity(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $this->learner->setEmail(' LEARNER-Identity@Example.TEST ');
        self::assertSame('learner-identity@example.test', $this->learner->getEmail());
        self::assertCount(0, $validator->validate($this->learner));
        $duplicate = (new Utilisateur())->setEmail(' LEARNER-IDENTITY@example.test ')->setPrenom('Autre')->setNom('Personne');
        $errors = $validator->validate($duplicate);
        self::assertCount(1, $errors); self::assertSame('email', $errors[0]->getPropertyPath());
        self::assertStringContainsString('déjà', $errors[0]->getMessage());
        self::assertSame($this->learner, $this->em->getRepository(Utilisateur::class)->loadUserByIdentifier(' LEARNER-IDENTITY@EXAMPLE.TEST '));
    }

    public function testLegacyMixedCaseEmailAlsoPreventsDuplicateCreation(): void
    {
        $id = $this->learner->getId();
        $this->em->getConnection()->update('utilisateur', ['email'=>' Legacy.Email@Example.TEST '], ['id'=>$id]);
        $this->em->clear();
        $candidate = (new Utilisateur())->setEmail('legacy.email@example.test')->setNom('Test')->setPrenom('Test');
        $errors = self::getContainer()->get(ValidatorInterface::class)->validate($candidate);
        self::assertCount(1, $errors); self::assertSame('email', $errors[0]->getPropertyPath());
        self::assertSame($id, $this->em->getRepository(Utilisateur::class)->loadUserByIdentifier('legacy.email@example.test')->getId());
    }

    public function testDatabaseUniqueConstraintStopsConcurrentCanonicalDuplicates(): void
    {
        $this->em->persist((new Utilisateur())->setEmail(' LEARNER-IDENTITY@example.test ')->setPassword('unused')->setNom('Test')->setPrenom('Test'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSharedIdentityBelongingToAnotherTenantCannotBeRenamedHere(): void
    {
        $owner = $this->user('foreign-owner@example.test', 'Owner');
        $foreign = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($owner);
        $this->em->persist($foreign); $this->em->flush();
        $this->learner->setEntite($foreign); $this->membership($this->learner, $foreign); $this->em->flush();
        $id = $this->learner->getId(); $client = $this->client();
        $crawler = $client->request('GET', $this->editUrl($id));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotNull($crawler->filter('#utilisateur_prenom')->attr('disabled'));
        self::assertStringContainsString('Ce compte est géré par un autre organisme', $crawler->text());
        $profileForm = self::getContainer()->get('form.factory')->create(\App\Form\Administrateur\UtilisateurType::class, $this->learner, ['entite'=>$this->entite]);
        foreach ($profileForm->get('formateurData')->get('representant')->createView()->vars['choices'] as $choice) {
            self::assertNotSame((string) $owner->getId(), $choice->value);
        }
        self::assertStringNotContainsString('foreign-owner@example.test', $crawler->filter('#utilisateur_entrepriseData_representant')->text());
        $values = $crawler->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['prenom'] = 'Renommé sans autorisation';
        $client->request('POST', $this->editUrl($id), $values);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear(); self::assertSame('JAson', $this->em->find(Utilisateur::class, $id)->getPrenom());
        $client->catchExceptions(true);
        $client->request('GET', '/fr/administrateur/'.$foreign->getId().'/utilisateur/modifier/'.$id);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testMissingTenantRoleDoesNotSilentlyCreateGlobalRoleMembership(): void
    {
        $client = $this->client(); $id = $this->learner->getId();
        $crawler = $client->request('GET', $this->editUrl($id));
        $values = $crawler->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['ueRoles'] = [];
        $client->request('POST', $this->editUrl($id), $values);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Sélectionnez au moins un rôle', $client->getResponse()->getContent());
        $this->em->clear();
        $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$id, 'entite'=>$this->entite->getId()]);
        self::assertSame([UtilisateurEntite::TENANT_STAGIAIRE], $ue->getRoles());
    }

    public function testHttpCreationSelectsExistingEmailWithDifferentCase(): void
    {
        $client = $this->client(); $url = '/fr/administrateur/'.$this->entite->getId().'/utilisateur/ajouter';
        $crawler = $client->request('GET', $url);
        $values = $crawler->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['prenom'] = 'Nouveau'; $values['utilisateur']['nom'] = 'Compte';
        $values['utilisateur']['email'] = ' LEARNER-IDENTITY@EXAMPLE.TEST ';
        $client->request('POST', $url, $values);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($this->editUrl($this->learner->getId()), $client->getResponse()->headers->get('Location'));
        self::assertSame('JAson', $this->learner->getPrenom());
        self::assertSame('unused', $this->learner->getPassword());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(2, $this->em->getRepository(UtilisateurEntite::class)->count([]));
    }

    public function testHttpCreationPersistsCanonicalEmailAndTenantRoleWithoutSendingMail(): void
    {
        $client = $this->client(); $url = '/fr/administrateur/'.$this->entite->getId().'/utilisateur/ajouter';
        $crawler = $client->request('GET', $url);
        $values = $crawler->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['prenom'] = 'Camille'; $values['utilisateur']['nom'] = 'Nouveau';
        $values['utilisateur']['email'] = ' CAMILLE.NOUVEAU@EXAMPLE.TEST ';
        $client->request('POST', $url, $values);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $created = $this->em->getRepository(Utilisateur::class)->findOneBy(['email'=>'camille.nouveau@example.test']);
        self::assertNotNull($created); self::assertSame('Camille', $created->getPrenom());
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$created, 'entite'=>$this->entite->getId()]);
        self::assertSame([UtilisateurEntite::TENANT_STAGIAIRE], $membership->getRoles());
    }

    public function testExistingClientSelectionRequiresValidCsrf(): void
    {
        $client = $this->client(); $url = '/fr/administrateur/'.$this->entite->getId().'/utilisateur/ajouter';
        $crawler = $client->request('GET', $url);
        $values = $crawler->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['email'] = $this->learner->getEmail();
        $values['utilisateur']['_token'] = 'invalid';
        $client->request('POST', $url, $values);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($client->getResponse()->headers->get('Location'));
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testSessionCreationSelectsLegacyEmailWithoutOverwritingIdentityOrDuplicatingMembership(): void
    {
        $id = $this->learner->getId();
        $this->em->getConnection()->update('utilisateur', ['email'=>' Legacy.Learner@Example.TEST '], ['id'=>$id]);
        $this->em->clear();
        [$client, $data] = $this->sessionLearnerForm();
        $data['email'] = ' LEGACY.LEARNER@example.test ';
        foreach ([1, 2] as $attempt) {
            $client->request('POST', $this->sessionLearnerUrl(), $data);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $json = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($json['already']); self::assertSame($id, $json['id']);
        }
        $this->em->clear(); $saved = $this->em->find(Utilisateur::class, $id);
        self::assertSame('JAson', $saved->getPrenom()); self::assertNull($saved->getTelephone());
        self::assertSame('unused', $saved->getPassword());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(2, $this->em->getRepository(UtilisateurEntite::class)->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\EntiteUsageYear::class)->count([]));
    }

    public function testSessionLearnerCreationRejectsForeignAccountAndInvalidCsrf(): void
    {
        $foreign = $this->user('foreign-learner@example.test', 'Confidentiel');
        $this->em->flush();
        [$client, $data] = $this->sessionLearnerForm();
        $data['email'] = $foreign->getEmail();
        $client->request('POST', $this->sessionLearnerUrl(), $data);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Confidentiel', $client->getResponse()->getContent());
        $data['_token'] = 'invalid'; $data['email'] = $this->learner->getEmail();
        $client->request('POST', $this->sessionLearnerUrl(), $data);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(2, $this->em->getRepository(UtilisateurEntite::class)->count([]));
    }

    public function testSessionLearnerCreationCreatesOnceThenSelectsSameAccount(): void
    {
        [$client, $data] = $this->sessionLearnerForm();
        $data['email'] = ' NEW.LEARNER@EXAMPLE.TEST ';
        $client->request('POST', $this->sessionLearnerUrl(), $data);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $first = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($first['already']);
        $client->request('POST', $this->sessionLearnerUrl(), $data);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($second['already']); self::assertSame($first['id'], $second['id']);
        self::assertSame(3, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(1, $this->em->getRepository(\App\Entity\Billing\EntiteUsageYear::class)->findOneBy(['entite'=>$this->entite->getId()])->getApprenantsCount());
    }

    public function testCompanyCreatedFromParticipantModalIsReusedAndAttachedToNewLearner(): void
    {
        [$client, $learnerData] = $this->sessionLearnerForm();
        $crawler = $client->getCrawler();
        $token = $crawler->filter('#form-participant-entreprise input[name="_token"]')->attr('value');
        $url = '/fr/administrateur/'.$this->entite->getId().'/session/ajax/entreprise/new';
        $data = ['_token'=>$token, 'raisonSociale'=>'Entreprise exemple', 'siret'=>'12345678901234', 'email'=>'contact@example.test', 'ville'=>'Nîmes'];
        $client->request('POST', $url, $data);
        self::assertSame(201, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $company = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($company['already']);
        $data['raisonSociale'] = ' ENTREPRISE EXEMPLE '; $data['ville'] = 'Paris';
        $client->request('POST', $url, $data);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $reused = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($reused['already']); self::assertSame($company['id'], $reused['id']);
        self::assertSame('Nîmes', $this->em->find(\App\Entity\Entreprise::class, $company['id'])->getVille());
        $learnerData['entreprise'] = $company['id']; $learnerData['email'] = 'company-learner@example.test';
        $client->request('POST', $this->sessionLearnerUrl(), $learnerData);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $created = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($company['id'], $created['entrepriseId']);
        self::assertSame($company['id'], $this->em->find(Utilisateur::class, $created['id'])->getEntreprise()->getId());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Entreprise::class)->count([]));
        $enrollment = new Inscription();
        $form = self::getContainer()->get('form.factory')->create(\App\Form\Administrateur\SessionInscriptionType::class, $enrollment, ['entite'=>$this->entite, 'csrf_protection'=>false]);
        $form->submit(['stagiaire'=>$created['id'], 'entreprise'=>$company['id'], 'status'=>\App\Enum\StatusInscription::cases()[0]->value]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($company['id'], $enrollment->getEntreprise()->getId());
    }

    public function testCompanyModalValidationCsrfAndSubscriptionLimit(): void
    {
        [$client] = $this->sessionLearnerForm();
        $token = $client->getCrawler()->filter('#form-participant-entreprise input[name="_token"]')->attr('value');
        $url = '/fr/administrateur/'.$this->entite->getId().'/session/ajax/entreprise/new';
        $client->request('POST', $url, ['raisonSociale'=>'Sans jeton']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, ['_token'=>$token, 'raisonSociale'=>'Test', 'siret'=>'123', 'email'=>'invalid']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\Entreprise::class)->count([]));
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['code'=>'IDENTITY_TEST']);
        $plan->setMaxEntreprises(1);
        $company = (new \App\Entity\Entreprise())->setEntite($this->em->find(Entite::class, $this->entite->getId()))->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))->setRaisonSociale('Déjà présente');
        $this->em->persist($company); $this->em->flush();
        $client->request('POST', $url, ['_token'=>$token, 'raisonSociale'=>'Autre entreprise']);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Limite', $client->getResponse()->getContent());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Entreprise::class)->count([]));
    }

    public function testCompanyPageEnforcesSameQuotaAsSessionModal(): void
    {
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['code' => 'IDENTITY_TEST']);
        $plan->setMaxEntreprises(1);
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Existante');
        $this->em->persist($company);
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/entreprise/ajouter');
        $form = $crawler->filter('form[name="entreprise"]')->form();
        $form['entreprise[raisonSociale]'] = 'Nouvelle';
        $client->submit($form);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Limite', $client->getResponse()->getContent());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Entreprise::class)->count([]));
    }

    public function testParticipantRejectsACompanyFromAnotherTenant(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->admin);
        $company = (new \App\Entity\Entreprise())->setEntite($other)->setCreateur($this->admin)->setRaisonSociale('Confidentiel');
        $this->em->persist($other); $this->em->persist($company); $this->em->flush();
        [$client, $data] = $this->sessionLearnerForm();
        self::assertStringNotContainsString('Confidentiel', $client->getCrawler()->filter('#new-stagiaire-entreprise')->text());
        $data['entreprise'] = $company->getId();
        $client->request('POST', $this->sessionLearnerUrl(), $data);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNull($this->learner->getEntreprise());
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    private function sessionLearnerForm(): array
    {
        $client = $this->client();
        $session = $this->em->getRepository(Session::class)->findOneBy(['code'=>'IDENTITY-A']);
        $crawler = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/session/modifier/'.$session->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return [$client, ['_token'=>$crawler->filter('#form-new-stagiaire input[name="_token"]')->attr('value'),
            'email'=>$this->learner->getEmail(), 'prenom'=>'Autre', 'nom'=>'Personne', 'civilite'=>'M.', 'telephone'=>'0600000000']];
    }
    private function sessionLearnerUrl(): string
    { return '/fr/administrateur/'.$this->entite->getId().'/session/ajax/stagiaire/new'; }

    private function user(string $email, string $prenom): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($email)->setPassword('unused')->setNom('Test')->setPrenom($prenom);
        $this->em->persist($user); return $user;
    }
    private function membership(Utilisateur $user, Entite $entity, string $role = UtilisateurEntite::TENANT_STAGIAIRE): void
    { $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($user)->setEntite($entity)->setRoles([$role])); }
    private function client(): KernelBrowser
    { $client=self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin); return $client; }
    private function editUrl(int $id): string
    { return '/fr/administrateur/'.$this->entite->getId().'/utilisateur/modifier/'.$id; }
}
