<?php

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Devis, Entite, Entreprise, Formation, Inscription, Session, SessionJour, Site, Utilisateur};
use App\Form\Administrateur\{ConventionContratType, DevisConventionType};
use App\Service\Convention\DevisConventionCreator;
use App\Service\Sequence\{ConventionContratNumberGenerator, SequenceNumberManager, SessionNumberGenerator};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Base SQLite jetable : aucune connexion à la base de développement. */
final class DevisConventionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DevisConventionCreator $creator;
    private Entite $entite;
    private Utilisateur $user;
    private Entreprise $entreprise;
    private Devis $devis;
    private Session $session;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('learner@example.test')->setPassword('unused')->setNom('Durand')->setPrenom('Camille');
        $this->entite = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user);
        $this->em->persist($this->entite);
        $this->em->flush();
        $this->user->setEntite($this->entite);
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Formation test')->setSlug('formation-test');
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->user)->setNom('Salle test')->setSlug('salle-test');
        $this->entreprise = (new Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Entreprise test');
        $this->devis = (new Devis())->setEntite($this->entite)->setCreateur($this->user)->setEntrepriseDestinataire($this->entreprise)->setFormation($formation)->setNumero('DEV-TEST');
        foreach ([$formation, $site, $this->entreprise, $this->devis] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setFormation($formation)->setSite($site);
        $this->session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-01 17:00')));
        $sequence = $this->createMock(SequenceNumberManager::class);
        $number = 0;
        $sequence->method('next')->willReturnCallback(static function () use (&$number) { return [2026, ++$number]; });
        $this->creator = new DevisConventionCreator($this->em, new ConventionContratNumberGenerator($sequence), new SessionNumberGenerator($sequence));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testConversionPersistsAndAllowsSeveralConventionsForSameCompanySession(): void
    {
        $first = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, 'Paiement à 30 jours');
        $second = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $sessionId = $this->session->getId();
        $firstId = $first->getId();
        self::assertNotSame($firstId, $second->getId());
        $this->em->clear();
        $reloaded = $this->em->find(ConventionContrat::class, $firstId);
        self::assertSame('DEV-TEST', $reloaded->getDevis()->getNumero());
        self::assertCount(1, $reloaded->getInscriptions());
        self::assertNotNull($reloaded->getInscriptions()->first()->getDossier());
        self::assertSame(1, $this->em->getRepository(Inscription::class)->count(['session' => $sessionId]));
        self::assertCount(2, $reloaded->getSession()->getConventionContrats());
        self::assertCount(2, $reloaded->getInscriptions()->first()->getConventionContrats());
    }

    public function testNewSessionFormValidatesAndCreatesWithoutJavascript(): void
    {
        $form = self::getContainer()->get('form.factory')->create(DevisConventionType::class, ['formation' => $this->devis->getFormation()], [
            'devis' => $this->devis, 'create_session' => true, 'csrf_protection' => false,
        ]);
        $form->submit([
            'site' => (string) $this->session->getSite()->getId(), 'capacite' => '8',
            'jours' => [['dateDebut' => '2026-10-01T09:00', 'dateFin' => '2026-10-01T17:00']],
            'stagiaires' => [(string) $this->user->getId()], 'conditionsFinancieres' => 'Test', 'operation' => 'test',
        ]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($this->devis->getFormation(), $form->get('formation')->getData());
        self::assertInstanceOf(SessionJour::class, $form->get('jours')->getData()[0]);
    }

    public function testIndividualEditingKeepsInscriptionAndRejectsEmptySelection(): void
    {
        $this->devis->setEntrepriseDestinataire(null)->setDestinataire($this->user);
        $convention = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $options = ['entite' => $this->entite, 'lock_session' => true, 'lock_entreprise' => true, 'lock_stagiaire' => true, 'csrf_protection' => false];
        $factory = self::getContainer()->get('form.factory');
        $form = $factory->create(ConventionContratType::class, $convention, $options);
        $form->submit(['inscriptions' => [(string) $convention->getInscriptions()->first()->getId()], 'conditionsFinancieres' => 'Paiement']);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->em->flush();
        self::assertCount(1, $convention->getInscriptions());
        $empty = $factory->create(ConventionContratType::class, $convention, $options);
        $empty->submit(['inscriptions' => [], 'conditionsFinancieres' => 'Paiement']);
        self::assertFalse($empty->isValid());
    }

    public function testExistingSessionFormRejectsForeignTenantSession(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $this->em->flush();
        $this->session->setEntite($other)->setCode('FOREIGN');
        foreach ($this->session->getJours() as $jour) $jour->setEntite($other)->setCreateur($this->user);
        $this->em->persist($this->session);
        $this->em->flush();
        $form = self::getContainer()->get('form.factory')->create(DevisConventionType::class, null, ['devis' => $this->devis, 'csrf_protection' => false]);
        $form->submit(['session' => (string) $this->session->getId(), 'stagiaires' => [(string) $this->user->getId()], 'operation' => 'test']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('session')->getErrors(true)));
    }

    public function testConventionLookupNeverFallsBackToAnotherCompanyParticipant(): void
    {
        $first = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $second = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $inscription = $first->getInscriptions()->first();
        $repo = $this->em->getRepository(ConventionContrat::class);
        self::assertNull($repo->findOneForInscription($inscription));
        self::assertSame($second, $repo->findOneForInscription($inscription, $second->getId()));
        self::assertNull($repo->findOneForInscription($inscription, 9999));
    }

    public function testNewInscriptionCreatesPositioningAssignmentsWithoutRecursiveFlush(): void
    {
        $questionnaire = (new \App\Entity\PositioningQuestionnaire())->setEntite($this->entite)->setCreateur($this->user)->setTitle('Positionnement');
        $positioning = (new \App\Entity\SessionPositioning())->setEntite($this->entite)->setCreateur($this->user)->setQuestionnaire($questionnaire);
        $this->session->addSessionPositioning($positioning);
        $this->em->persist($questionnaire);
        $convention = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $id = $convention->getId();
        $this->em->clear();
        $inscription = $this->em->find(ConventionContrat::class, $id)->getInscriptions()->first();
        self::assertCount(1, $inscription->getPositioningAssignments());
        self::assertCount(1, $inscription->getPositioningAttempts());
        self::assertSame($inscription->getPositioningAssignments()->first(), $inscription->getPositioningAttempts()->first()->getAssignment());
    }

    public function testConfirmingInscriptionCreatesQcmAssignmentsOnce(): void
    {
        $qcm = (new \App\Entity\Qcm())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Évaluation');
        $this->em->persist($qcm);
        $convention = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $inscription = $convention->getInscriptions()->first();
        $inscription->setStatus(\App\Enum\StatusInscription::CONFIRME);
        $this->em->flush();
        $inscription->setStatus(\App\Enum\StatusInscription::TERMINE);
        $this->em->flush();
        self::assertCount(2, $inscription->getQcmAssignments());
        self::assertSame(2, $this->em->getRepository(\App\Entity\QcmAssignment::class)->count(['inscription' => $inscription]));
    }

    public function testConventionPdfUsesQuoteTotalsAndOnlySelectedParticipants(): void
    {
        $this->devis->setMontantHtCents(12345)->setMontantTvaCents(2469)->setMontantTtcCents(14814)->setDevise('USD');
        $convention = $this->creator->create($this->devis, $this->session, [$this->user], $this->user, null);
        $other = (new Utilisateur())->setNom('EXCLUDED-PARTICIPANT')->setPrenom('Autre');
        $this->session->addInscription((new Inscription())->setStagiaire($other));
        $html = self::getContainer()->get('twig')->render('pdf/convention_contrat.html.twig', [
            'entite' => $this->entite, 'convention' => $convention, 'session' => $this->session,
            'formation' => $this->session->getFormation(), 'entreprise' => $this->entreprise, 'stagiaire' => null,
        ]);
        self::assertStringContainsString('DEV-TEST', $html);
        self::assertStringContainsString('148,14 USD', $html);
        self::assertStringNotContainsString('EXCLUDED-PARTICIPANT', $html);
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'fontCache' => sys_get_temp_dir()]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        self::assertStringStartsWith('%PDF-', $pdf->output());
    }

    public function testHttpConversionCreatesOnceWhenTheSameFormIsPostedTwice(): void
    {
        $this->user->setRoles(['ROLE_SUPER_ADMIN']);
        $membership = (new \App\Entity\UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->user)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']);
        $this->user->addUtilisateurEntite($membership);
        $subscription = (new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active');
        $this->em->persist($subscription);
        $this->em->flush();
        self::getContainer()->set(DevisConventionCreator::class, $this->creator);
        $client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->user);
        $url = self::getContainer()->get('router')->generate('app_administrateur_devis_convention', [
            'entite' => $this->entite->getId(), 'id' => $this->devis->getId(), 'mode' => 'new',
        ]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Créer la session et la convention')->form([
            'devis_convention[site]' => (string) $this->session->getSite()->getId(),
            'devis_convention[capacite]' => '8',
            'devis_convention[jours][0][dateDebut]' => '2026-10-01T09:00',
            'devis_convention[jours][0][dateFin]' => '2026-10-01T17:00',
            'devis_convention[stagiaires]' => [(string) $this->user->getId()],
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $redirect = $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/conventions/', $redirect);
        $client->submit($form);
        self::assertSame($redirect, $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(ConventionContrat::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Session::class)->count([]));
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
