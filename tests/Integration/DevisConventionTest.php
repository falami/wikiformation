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
        $client = $this->createHttpClient();
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

    public function testHttpExistingSessionConversionDoesNotValidateAnUnusedEmptySlot(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[name^="devis_convention[jours]"]'));
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [(string) $this->user->getId()],
        ]);
        $result = $client->submit($form);
        $errors = $result->filter('.invalid-feedback, .alert-danger')->each(static fn($node) => trim($node->text()));
        self::assertSame(302, $client->getResponse()->getStatusCode(), implode("\n", $errors));
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(ConventionContrat::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Session::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testHttpDifferentTrainingSessionRequiresConfirmationAndRemainsVisibleOnQuote(): void
    {
        $this->devis->getFormation()->setDuree(2);
        $this->persistSession();
        $different = $this->persistDifferentTrainingSession();
        $canceled = $this->additionalSession('SES-CANCELED', $this->entite, $different->getFormation());
        $canceled->setStatus(\App\Enum\StatusSession::CANCELED);
        $other = (new Entite())->setNom('Organisme étranger')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $foreign = $this->additionalSession('SES-FOREIGN', $other, $different->getFormation());
        $this->em->flush();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('0', $crawler->filter('#convention-workspace')->attr('data-submitted'));
        $options = $crawler->filter('select[name="devis_convention[session]"] option');
        self::assertCount(1, $options->filter('[value="' . $this->session->getId() . '"]'));
        $option = $options->filter('[value="' . $different->getId() . '"]');
        self::assertCount(1, $option);
        self::assertStringContainsString('Habilitation électrique', $option->text());
        self::assertTrue(json_decode($option->attr('data-session'), true, flags: JSON_THROW_ON_ERROR)['differentFormation']);
        self::assertCount(0, $options->filter('[value="' . $canceled->getId() . '"]'));
        self::assertCount(0, $options->filter('[value="' . $foreign->getId() . '"]'));

        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $different->getId(),
            'devis_convention[stagiaires]' => [],
            'devis_convention[effectifPrevisionnel]' => '3',
            // Even values deliberately restored to the quote defaults must survive an invalid POST.
            'devis_convention[intituleFormation]' => 'Formation test',
            'devis_convention[dureeFormation]' => '2 jours',
        ]);
        $crawler = $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('1', $crawler->filter('#convention-workspace')->attr('data-submitted'));
        self::assertStringContainsString('Confirmez ce choix', $crawler->filter('body')->text());
        self::assertSame(0, $this->em->getRepository(ConventionContrat::class)->count([]));
        self::assertSame('Formation test', $crawler->filter('input[name="devis_convention[intituleFormation]"]')->attr('value'));
        self::assertSame('2 jours', $crawler->filter('input[name="devis_convention[dureeFormation]"]')->attr('value'));
        self::assertSame('3', $crawler->filter('input[name="devis_convention[effectifPrevisionnel]"]')->attr('value'));
        self::assertSame((string) $different->getId(), $crawler->filter('select[name="devis_convention[session]"] option[selected]')->attr('value'));
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[confirmerFormationDifferente]' => '1',
            'devis_convention[intituleFormation]' => 'H0B0 — indices adaptés',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $redirect = $client->getResponse()->headers->get('Location');
        $client->submit($form);
        self::assertSame($redirect, $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(ConventionContrat::class)->count([]));
        $this->em->clear();
        $saved = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertSame($different->getId(), $saved->getSession()->getId());
        self::assertSame('H0B0 — indices adaptés', $saved->getIntituleFormation());
        self::assertSame('2 jours', $saved->getDureeFormation());
        self::assertSame('Formation test', $saved->getDevis()->getFormation()->getTitre(), 'Le choix de session ne modifie pas la formation du devis.');
        $crawler = $client->request('GET', $this->quoteUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($saved->getNumero(), $crawler->filter('body')->text());
        self::assertStringContainsString('H0B0 — indices adaptés', $crawler->filter('body')->text());
    }

    public function testCreatorRejectsDifferentTrainingWithoutExplicitConfirmation(): void
    {
        $different = $this->persistDifferentTrainingSession();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Confirmez explicitement');
        $this->creator->create($this->devis, $different, [], $this->user, null, effectifPrevisionnel: 2);
    }

    public function testHttpLegacyConventionOfDifferentTrainingNeedsSeparateConfirmation(): void
    {
        $different = $this->persistDifferentTrainingSession();
        $convention = $this->legacyConvention($different, 'CONV-LEGACY-DIFFERENT');
        $convention->setPdfPath('documents/previous-convention.pdf');
        $signed = $this->legacyConvention($different, 'CONV-SIGNED');
        $signed->setDateSignatureEntreprise(new \DateTimeImmutable('2026-09-01'));
        $other = (new Entite())->setNom('Organisme étranger')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $foreign = $this->legacyConvention($different, 'CONV-FOREIGN');
        $foreign->setEntite($other);
        $this->em->flush();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->quoteUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $options = $crawler->filter('select[name="devis_convention_link[convention]"] option');
        self::assertSame('1', $options->filter('[value="' . $convention->getId() . '"]')->attr('data-different-formation'));
        self::assertCount(0, $options->filter('[value="' . $signed->getId() . '"]'));
        self::assertCount(0, $options->filter('[value="' . $foreign->getId() . '"]'));
        self::assertNull($convention->getDevis(), 'Consulter le devis ne doit jamais rattacher automatiquement une convention.');
        $form = $crawler->selectButton('Rattacher la convention')->form([
            'devis_convention_link[convention]' => (string) $convention->getId(),
            'devis_convention_link[confirmation]' => '1',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();
        self::assertStringContainsString('confirmez la différence de formation', $crawler->filter('body')->text());
        self::assertNull($this->em->find(ConventionContrat::class, $convention->getId())->getDevis());
        self::assertSame('documents/previous-convention.pdf', $convention->getPdfPath());
        $form = $crawler->selectButton('Rattacher la convention')->form([
            'devis_convention_link[convention]' => (string) $convention->getId(),
            'devis_convention_link[confirmation]' => '1',
            'devis_convention_link[confirmerFormationDifferente]' => '1',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $saved = $this->em->find(ConventionContrat::class, $convention->getId());
        self::assertSame($this->devis->getId(), $saved->getDevis()?->getId());
        self::assertNull($saved->getPdfPath());
        self::assertStringContainsString('CONV-LEGACY-DIFFERENT', $crawler->filter('body')->text());
        self::assertCount(1, $this->em->getRepository(ConventionContrat::class)->findForDevis($saved->getDevis()));
    }

    public function testLinkerRejectsDifferentTrainingWithoutExplicitConfirmation(): void
    {
        $convention = $this->legacyConvention($this->persistDifferentTrainingSession(), 'CONV-UNCONFIRMED');
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Confirmez explicitement');
        self::getContainer()->get(\App\Service\Convention\DevisConventionLinker::class)->link($this->devis, $convention);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedLegacyConventionCases')]
    public function testDifferentTrainingConfirmationCannotBypassLegacyConventionGuards(string $restriction): void
    {
        $convention = $this->legacyConvention($this->persistDifferentTrainingSession(), 'CONV-PROTECTED');
        if ($restriction === 'signed') {
            $convention->setDateSignatureEntreprise(new \DateTimeImmutable('2026-09-01'));
            $message = 'signée';
        } else {
            $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
            $this->em->persist($other);
            $convention->setEntite($other);
            $message = 'même organisme';
        }
        $this->em->flush();
        self::assertSame([], $this->em->getRepository(ConventionContrat::class)->findAttachableToDevis($this->devis));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($message);
        self::getContainer()->get(\App\Service\Convention\DevisConventionLinker::class)->link($this->devis, $convention, true);
    }

    public static function protectedLegacyConventionCases(): array
    {
        return ['signed' => ['signed'], 'foreign tenant' => ['foreign']];
    }

    public function testHttpCompanyConventionPersistsFreeNamesAndEditableDocumentDetails(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [],
            'devis_convention[intituleFormation]' => 'Atelier Excel avancé — équipe finance',
            'devis_convention[dureeFormation]' => '14 heures sur 2 journées',
            'devis_convention[participantsLibres]' => "Aïcha Ben Salem\n\nMarc Legrand",
            'devis_convention[effectifPrevisionnel]' => '3',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $convention = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertNotNull($convention);
        self::assertSame('Atelier Excel avancé — équipe finance', $convention->getIntituleFormation());
        self::assertSame('14 heures sur 2 journées', $convention->getDureeFormation());
        self::assertSame(['Aïcha Ben Salem', 'Marc Legrand'], $convention->getParticipantsLibresListe());
        self::assertSame(3, $convention->getEffectifPrevisionnel());
        self::assertCount(0, $convention->getInscriptions());
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame('Formation test', $convention->getSession()->getFormation()->getTitre());
        self::assertSame('2026-10-01 09:00', $convention->getSession()->getDateDebut()->format('Y-m-d H:i'));
        self::assertSame('2026-10-01 17:00', $convention->getSession()->getDateFin()->format('Y-m-d H:i'));
        $html = self::getContainer()->get('twig')->render('pdf/convention_contrat.html.twig', [
            'entite' => $convention->getEntite(), 'convention' => $convention, 'session' => $convention->getSession(),
            'formation' => $convention->getSession()->getFormation(), 'entreprise' => $convention->getEntreprise(), 'stagiaire' => null,
        ]);
        self::assertStringContainsString('Atelier Excel avancé — équipe finance', $html);
        self::assertStringContainsString('14 heures sur 2 journées', $html);
        self::assertStringContainsString('Aïcha Ben Salem', $html);
        self::assertStringContainsString('Marc Legrand', $html);
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testHttpCompanyConventionCanUseOnlyFreeNamesWithoutAnEffectif(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [],
            'devis_convention[participantsLibres]' => "Anna Martin\nPaul Petit",
            'devis_convention[effectifPrevisionnel]' => '',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $convention = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertSame(['Anna Martin', 'Paul Petit'], $convention->getParticipantsLibresListe());
        self::assertCount(0, $convention->getInscriptions());
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testHttpCompanyConventionCanUseOnlyAnExpectedHeadcount(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [],
            'devis_convention[participantsLibres]' => '',
            'devis_convention[effectifPrevisionnel]' => '4',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $convention = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertSame(4, $convention->getEffectifPrevisionnel());
        self::assertSame([], $convention->getParticipantsLibresListe());
        self::assertCount(0, $convention->getInscriptions());
    }

    public function testHttpCompanyConventionCreatesInscriptionsOnlyForSelectedExistingClients(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [(string) $this->user->getId()],
            'devis_convention[participantsLibres]' => 'Anna Martin',
            'devis_convention[effectifPrevisionnel]' => '3',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $convention = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertSame(3, $convention->getEffectifPrevisionnel());
        self::assertSame(['Anna Martin'], $convention->getParticipantsLibresListe());
        self::assertCount(1, $convention->getInscriptions());
        self::assertSame($this->user->getId(), $convention->getInscriptions()->first()->getStagiaire()->getId());
        self::assertSame(1, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testHttpCompanyConventionRejectsHeadcountSmallerThanNamedParticipants(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [(string) $this->user->getId()],
            'devis_convention[participantsLibres]' => "Anna Martin\nPaul Petit",
            'devis_convention[effectifPrevisionnel]' => '2',
        ]);
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(ConventionContrat::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testHttpCompanyConventionStillRejectsAnEmptyParticipantScope(): void
    {
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[stagiaires]' => [],
            'devis_convention[participantsLibres]' => " \n ",
            'devis_convention[effectifPrevisionnel]' => '',
        ]);
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(ConventionContrat::class)->count([]));
    }

    public function testHttpIndividualConventionKeepsQuotePayeeAndAllowsDocumentDetails(): void
    {
        $this->devis->setEntrepriseDestinataire(null)->setDestinataire($this->user);
        $this->persistSession();
        $client = $this->createHttpClient();
        $crawler = $client->request('GET', $this->conversionUrl());
        self::assertCount(0, $crawler->filter('[name="devis_convention[stagiaires][]"]'));
        $form = $crawler->selectButton('Créer la convention')->form([
            'devis_convention[session]' => (string) $this->session->getId(),
            'devis_convention[intituleFormation]' => 'Accompagnement individuel Excel',
            'devis_convention[dureeFormation]' => '7 heures',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/conventions/', $client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $convention = $this->em->getRepository(ConventionContrat::class)->findOneBy(['devis' => $this->devis->getId()]);
        self::assertSame($this->user->getId(), $convention->getStagiaire()->getId());
        self::assertNull($convention->getEntreprise());
        self::assertCount(1, $convention->getInscriptions());
        self::assertSame($this->user->getId(), $convention->getInscriptions()->first()->getStagiaire()->getId());
        self::assertSame('Accompagnement individuel Excel', $convention->getIntituleFormation());
        self::assertSame('7 heures', $convention->getDureeFormation());
    }

    public function testHttpCompanyConventionCanBeCompletedWithRealInscriptionsLater(): void
    {
        $this->persistSession();
        $convention = $this->creator->create($this->devis, $this->session, [], $this->user, null,
            'H0B0 adapté', '7 heures', "Camille Durand", 2);
        $convention->setPdfPath('documents/ancien-document.pdf');
        $secondUser = (new Utilisateur())->setEntite($this->entite)->setCreateur($this->user)
            ->setPrenom('Alex')->setNom('Martin')->setEmail('alex@example.test')->setPassword('unused');
        $this->em->persist($secondUser);
        $ids = [];
        foreach ([$this->user, $secondUser] as $learner) {
            $inscription = (new Inscription())->setEntite($this->entite)->setCreateur($this->user)
                ->setSession($this->session)->setEntreprise($this->entreprise)->setStagiaire($learner);
            $this->em->persist($inscription);
            $ids[] = $inscription;
        }
        $this->em->flush();
        $conventionId = $convention->getId();
        $inscriptionIds = array_map(static fn(Inscription $i) => (string) $i->getId(), $ids);
        $client = $this->createHttpClient();
        $url = self::getContainer()->get('router')->generate('app_administrateur_convention_edit', [
            'entite' => $this->entite->getId(), 'id' => $conventionId,
        ]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="convention_contrat"]')->form([
            'convention_contrat[inscriptions]' => $inscriptionIds,
            'convention_contrat[participantsLibres]' => '',
            'convention_contrat[effectifPrevisionnel]' => '2',
            'convention_contrat[intituleFormation]' => 'H0B0 adapté après confirmation',
            'convention_contrat[dureeFormation]' => '7 heures',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $saved = $this->em->find(ConventionContrat::class, $conventionId);
        self::assertSame(2, $saved->getEffectifTotal());
        self::assertCount(2, $saved->getInscriptions());
        self::assertSame([], $saved->getParticipantsLibresListe());
        self::assertSame('H0B0 adapté après confirmation', $saved->getIntituleFormation());
        self::assertSame('7 heures', $saved->getDureeFormation());
        self::assertNull($saved->getPdfPath());
        self::assertCount(2, $saved->getDevis()->getInscriptions());

        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form[name="convention_contrat"]')->form([
            'convention_contrat[effectifPrevisionnel]' => '1',
        ]);
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame(2, $this->em->find(ConventionContrat::class, $conventionId)->getEffectifTotal());
    }

    private function persistSession(): void
    {
        $this->session->setCode('SES-EXISTING');
        foreach ($this->session->getJours() as $jour) {
            $jour->setEntite($this->entite)->setCreateur($this->user);
        }
        $this->em->persist($this->session);
        $this->em->flush();
    }

    private function persistDifferentTrainingSession(): Session
    {
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->user)
            ->setTitre('Habilitation électrique H0B0')->setSlug('habilitation-electrique')->setDuree(1);
        $this->em->persist($formation);
        $session = $this->additionalSession('SES-HABILITATION', $this->entite, $formation);
        $this->em->flush();
        return $session;
    }

    private function additionalSession(string $code, Entite $entite, Formation $formation): Session
    {
        $session = (new Session())->setEntite($entite)->setCreateur($this->user)->setFormation($formation)
            ->setSite($this->session->getSite())->setCode($code)->setCapacite(8);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->user)
            ->setDateDebut(new \DateTimeImmutable('2026-10-02 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-02 17:00')));
        $this->em->persist($session);
        return $session;
    }

    private function legacyConvention(Session $session, string $numero): ConventionContrat
    {
        $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->user)
            ->setSession($session)->setEntreprise($this->entreprise)->setNumero($numero)->setEffectifPrevisionnel(2);
        $this->em->persist($convention);
        return $convention;
    }

    private function quoteUrl(): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_devis_show', [
            'entite' => $this->entite->getId(), 'id' => $this->devis->getId(),
        ]);
    }

    private function conversionUrl(): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_devis_convention', [
            'entite' => $this->entite->getId(), 'id' => $this->devis->getId(),
        ]);
    }

    private function createHttpClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $this->user->setRoles(['ROLE_SUPER_ADMIN']);
        $membership = (new \App\Entity\UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->user)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']);
        $this->user->addUtilisateurEntite($membership);
        $subscription = (new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active');
        $this->em->persist($subscription);
        $this->em->flush();
        self::getContainer()->set(DevisConventionCreator::class, $this->creator);
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->user);
        return $client;
    }
}
