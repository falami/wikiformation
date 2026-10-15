<?php

declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite, Formation, Utilisateur, UtilisateurEntite, QualityRecord, AuditLog};
use App\Form\Administrateur\FormationPublicInformationType;
use App\Service\Quality\QualityReadiness;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class QualityReadinessTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private Entite $entite;
    private Utilisateur $user;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('quality@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin')->setRoles(['ROLE_USER']);
        $this->entite = (new Entite())->setNom('Organisme de démonstration')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->user);
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']); else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }
    public function testReadOnlyInventoryAndExportAreTenantScoped(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        foreach ([$this->entite, $other] as $i => $entite) {
            $this->em->persist((new Formation())->setEntite($entite)->setCreateur($this->user)->setTitre($i ? 'Formation confidentielle autre organisme' : 'Formation à compléter')->setSlug('quality-'.$i)->setIsPublic(true));
            $site = (new \App\Entity\Site())->setEntite($entite)->setCreateur($this->user)->setNom('Salle')->setSlug('quality-site-'.$i);
            $this->em->persist($site);
            $session = (new \App\Entity\Session())->setEntite($entite)->setCreateur($this->user)->setCode('QUALITY-'.$i)->setSite($site)->setFormationIntituleLibre('Formation de test');
            $this->em->persist($session);
        }
        $this->em->flush();
        $url = '/fr/administrateur/'.$this->entite->getId().'/qualite';
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Formation à compléter', $html);
        self::assertStringNotContainsString('Formation confidentielle', $html);
        self::assertStringContainsString('aucun taux de certification', $html);
        if (getenv('QUALITY_REVIEW_DIR')) file_put_contents(getenv('QUALITY_REVIEW_DIR').'/quality-preview.html', $html);
        $this->client->request('GET', $url.'/export.json');
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['publicFormationCount']);
        self::assertCount(7, $data['criteria']);
        self::assertSame(0, $data['inventory']['traces']);
        self::assertSame(1, $data['distanceGapCount']);
        self::assertSame('QUALITY-0', $data['distanceGaps'][0]['code']);
        self::assertContains('Accessibilité et handicap', $data['publicationGaps'][0]['missing']);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/fr/administrateur/'.$other->getId().'/qualite/export.json');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
    public function testPublicInformationIsPersistedAndNoCertificationIsInferred(): void
    {
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(FormationPublicInformationType::class, [], ['csrf_protection' => false]);
        $input = ['accessDelay' => 'Inscription 7 jours avant', 'handicap' => 'Contactez le référent avant inscription', 'contact' => 'contact@example.test', 'recognition' => 'Attestation de réalisation', 'results' => 'Pas de résultats consolidés pour cet exercice'];
        $form->submit($input);
        self::assertTrue($form->isValid());
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->user)->setTitre('Public transparent')->setSlug('public-transparent')->setIsPublic(true)->setCodeQualiopi('REF-123')->setPublicInformation($form->getData());
        $this->em->persist($formation); $this->em->flush();
        $id = $formation->getId(); $this->em->clear();
        $formation = $this->em->find(Formation::class, $id);
        self::assertSame($input, $formation->getPublicInformation());
        self::assertNotContains('Accessibilité et handicap', QualityReadiness::formationMissing($formation));
        self::assertContains('Objectifs', QualityReadiness::formationMissing($formation));
        $this->client->request('GET', '/fr/formation/public-transparent');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Inscription 7 jours avant', $html);
        self::assertStringNotContainsString('Certification Qualiopi :', $html);
        self::assertStringNotContainsString('Parcours certifiant', $html);
        self::assertStringNotContainsString('Annulation gratuite', $html);
        self::assertStringContainsString('Référence de formation : REF-123', $html);
    }

    public function testEmptyRichTextDoesNotSatisfyPublicationRequirements(): void
    {
        $formation = (new Formation())->setDuree(1)->setPublicInformation(array_fill_keys(array_keys(FormationPublicInformationType::FIELDS), 'Renseigné'))
            ->setPublic('<p>&nbsp;<br></p>')->setConditionPrealable('<p>&#160;</p>')
            ->setObjectifs("<p>\u{200B}\u{FEFF}</p>")->setPedagogie("<div>\u{202F}\t</div>")
            ->setModalitesEvaluation('<p> &nbsp; </p>');
        self::assertSame(['Public visé', 'Prérequis', 'Objectifs', 'Méthodes pédagogiques', 'Modalités d’évaluation'], QualityReadiness::formationMissing($formation));
        $formation->setObjectifs('<p>&nbsp;Maîtriser les gestes professionnels.</p>');
        self::assertNotContains('Objectifs', QualityReadiness::formationMissing($formation));
    }

    public function testQualityRegisterKeepsVersionsAndRequiresEvidenceToClose(): void
    {
        $base = '/fr/administrateur/'.$this->entite->getId().'/qualite/registre';
        $crawler = $this->client->request('GET', $base.'/nouveau');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(QualityRecord::class)->count([]));
        if (getenv('QUALITY_REVIEW_DIR')) file_put_contents(getenv('QUALITY_REVIEW_DIR').'/quality-form.html', $this->client->getResponse()->getContent());
        $this->client->submit($crawler->selectButton('Enregistrer la fiche')->form([
            'quality_record[kind]' => 'complaint', 'quality_record[title]' => 'Délai de réponse',
            'quality_record[description]' => 'Demande reçue et prise en charge.',
            'quality_record[owner]' => 'Référente qualité', 'quality_record[occurredAt]' => '2026-10-08',
        ]));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $record = $this->em->getRepository(QualityRecord::class)->findOneBy([]);
        self::assertSame('complaint', $record->getKind());
        self::assertSame($this->entite->getId(), $record->getEntite()->getId());
        $recordId = $record->getId();
        $events = $this->em->getRepository(AuditLog::class)->findBy(['event' => 'QUALITY_RECORD_SAVED']);
        self::assertCount(1, $events);
        self::assertSame('open', $events[0]->getPayload()['status']);
        $url = $base.'/'.$recordId.'/modifier';
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Enregistrer la fiche')->form(['quality_record[status]' => 'closed']));
        self::assertStringContainsString('Pour clôturer', $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame('open', $this->em->find(QualityRecord::class, $recordId)->getStatus());
        self::assertSame(1, $this->em->getRepository(AuditLog::class)->count(['event' => 'QUALITY_RECORD_SAVED']));
        $crawler = $this->client->request('GET', $url);
        $stale = $crawler->selectButton('Enregistrer la fiche')->form()->getPhpValues();
        $this->client->submit($crawler->selectButton('Enregistrer la fiche')->form([
            'quality_record[status]' => 'closed', 'quality_record[actions]' => 'Réponse envoyée le 09/10.',
            'quality_record[evidence]' => 'Référence du courrier Q-12', 'quality_record[conclusion]' => 'Solution acceptée et vérifiée.',
        ]));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame('closed', $this->em->find(QualityRecord::class, $recordId)->getStatus());
        $events = $this->em->getRepository(AuditLog::class)->findBy(['event' => 'QUALITY_RECORD_SAVED'], ['id' => 'ASC']);
        self::assertCount(2, $events);
        self::assertSame('open', $events[0]->getPayload()['status']);
        self::assertSame('closed', $events[1]->getPayload()['status']);
        self::assertSame(2, $events[1]->getPayload()['version']);
        $this->client->request('POST', $url, $stale);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame('closed', $this->em->find(QualityRecord::class, $recordId)->getStatus());
        self::assertSame(2, $this->em->getRepository(AuditLog::class)->count(['event' => 'QUALITY_RECORD_SAVED']));
    }

    public function testRegistryRejectsCrossTenantRecordsAndInvalidCsrf(): void
    {
        $other = (new Entite())->setNom('Organisme isolé')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $record = (new QualityRecord())->setEntite($other)->setTitle('Preuve confidentielle')->setDescription('Privé')->setOwner('Autre responsable');
        $record->recordEdit($this->user);
        $this->em->persist($record); $this->em->flush();
        $base = '/fr/administrateur/'.$this->entite->getId().'/qualite/registre';
        $this->client->request('GET', $base);
        self::assertStringNotContainsString('Preuve confidentielle', $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', $base.'/nouveau');
        $values = $crawler->selectButton('Enregistrer la fiche')->form([
            'quality_record[title]' => 'CSRF invalide', 'quality_record[description]' => 'Tentative rejetée', 'quality_record[owner]' => 'Responsable',
        ])->getPhpValues();
        $values['quality_record']['_token'] = 'invalid';
        $this->client->request('POST', $base.'/nouveau', $values);
        self::assertSame(0, $this->em->getRepository(QualityRecord::class)->count(['entite' => $this->entite]));
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count(['event' => 'QUALITY_RECORD_SAVED']));
        $this->client->catchExceptions(true);
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, $base.'/'.$record->getId().'/modifier', $values);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('GET', $base.'/999999/modifier');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testIdentityCanBeCompletedWithoutChangingAnotherOrganismOrInventingEvidence(): void
    {
        $other = (new Entite())->setNom('Organisme privé')->setPublic(false)->setCreateur($this->user)->setSiret('11111111111111');
        $this->em->persist($other); $this->em->flush();
        $url = '/fr/administrateur/'.$this->entite->getId().'/qualite/identite';
        $page = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count([]));
        $form = $page->selectButton('Enregistrer l’identité')->form([
            'quality_identity[siret]' => '123 456 789 00012', 'quality_identity[numeroDeclarant]' => '12 345 678 901',
            'quality_identity[adresse]' => '1 rue des Écoles', 'quality_identity[ville]' => 'Paris', 'quality_identity[codePostal]' => '75001',
            'quality_identity[region]' => 'Île-de-France', 'quality_identity[email]' => 'contact@example.test', 'quality_identity[nomRepresentant]' => 'Martin',
        ]);
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        $entity = $this->em->find(Entite::class, $this->entite->getId());
        self::assertSame('12345678900012', $entity->getSiret());
        self::assertSame('12345678901', $entity->getNumeroDeclarant());
        self::assertSame('11111111111111', $this->em->find(Entite::class, $other->getId())->getSiret());
        self::assertNull($entity->getPreferences());
        self::assertCount(0, self::getContainer()->get(QualityReadiness::class)->inspect($entity)['identityGaps']);
        self::assertSame(1, $this->em->getRepository(AuditLog::class)->count(['event' => 'QUALITY_IDENTITY_SAVED']));
        $page = $this->client->request('GET', $url);
        $invalid = $page->selectButton('Enregistrer l’identité')->form(['quality_identity[siret]' => 'invalid']);
        $this->client->submit($invalid);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('14 chiffres', $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $url);
        $invalid = $page->selectButton('Enregistrer l’identité')->form(['quality_identity[siret]' => '98765432100012']);
        $invalid['quality_identity[_token]'] = 'invalid';
        $this->client->submit($invalid);
        $this->em->clear();
        self::assertSame('12345678900012', $this->em->find(Entite::class, $this->entite->getId())->getSiret());
        self::assertSame(1, $this->em->getRepository(AuditLog::class)->count(['event' => 'QUALITY_IDENTITY_SAVED']));
        $this->client->catchExceptions(true);
        $this->client->request('POST', '/fr/administrateur/'.$other->getId().'/qualite/identite', $invalid->getPhpValues());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testOperationalFollowUpIncludesClosedSessionsButExcludesForeignCancelledAndFutureRecords(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $wanted = null;
        foreach ([[$this->entite, 'done', '2025-09-10'], [$this->entite, 'canceled', '2025-09-10'], [$this->entite, 'published', '2099-09-10'], [$other, 'done', '2025-09-10']] as $n => [$entite, $status, $date]) {
            $site = (new \App\Entity\Site())->setNom('Salle')->setSlug('operational-'.$n)->setEntite($entite)->setCreateur($this->user);
            $session = (new \App\Entity\Session())->setEntite($entite)->setCreateur($this->user)->setCode('DOSSIER-'.$n)->setSite($site)->setFormationIntituleLibre('Formation')->setStatus(\App\Enum\StatusSession::from($status))->setTauxDistanciel(0);
            $session->addJour((new \App\Entity\SessionJour())->setEntite($entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable($date.' 09:00'))->setDateFin(new \DateTimeImmutable($date.' 12:00')));
            $inscription = (new \App\Entity\Inscription())->setEntite($entite)->setCreateur($this->user)->setSession($session)->setStagiaire($this->user)->setStatus(\App\Enum\StatusInscription::TERMINE);
            foreach ([$site,$session,$inscription] as $item) $this->em->persist($item);
            if ($n === 0) $wanted = $inscription;
        }
        $this->em->flush();
        $id = $wanted->getId();
        $report = self::getContainer()->get(QualityReadiness::class)->inspect($this->entite);
        self::assertSame(1, $report['followUp']['total']);
        self::assertSame(1, $report['followUp']['checked']);
        self::assertSame($id, $report['followUp']['items'][0]['id']);
        self::assertTrue($report['followUp']['items'][0]['attendance']);
        $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/qualite');
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('/inscription/'.$id.'#presences-papier', $html);
        self::assertStringNotContainsString('DOSSIER-3', $html);
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count([]));
        $wanted = $this->em->find(\App\Entity\Inscription::class, $id);
        self::assertSame([], $wanted->getPresencesManuelles());
        $wanted->enregistrerPresenceManuelle('2025-09-10:AM', 'paper', 'Feuille conservée', $this->em->find(Utilisateur::class, $this->user->getId()));
        $this->em->flush();
        $report = self::getContainer()->get(QualityReadiness::class)->inspect($this->em->find(Entite::class, $this->entite->getId()));
        self::assertSame([], $report['followUp']['items']);
    }

    public function testBulkPaperAttendanceUsesDepositDateAndPreservesExistingRecords(): void
    {
        $ids = [];
        foreach ([true, false] as $n => $withPaper) {
            $site = (new \App\Entity\Site())->setNom('Salle')->setSlug('bulk-'.$n)->setEntite($this->entite)->setCreateur($this->user);
            $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('BULK-'.$n)->setSite($site)->setFormationIntituleLibre('Formation')->setStatus(\App\Enum\StatusSession::DONE)->setTauxDistanciel(0);
            $session->addJour((new \App\Entity\SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2025-09-10 09:00'))->setDateFin(new \DateTimeImmutable('2025-09-10 17:00')));
            $ins = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->user)->setSession($session)->setStagiaire($this->user)->setStatus(\App\Enum\StatusInscription::TERMINE);
            if ($withPaper) {
                $ins->enregistrerPresenceManuelle('2025-09-10:AM', 'absent', 'Absence justifiée', $this->user);
                $piece = (new \App\Entity\SessionPiece())->setEntite($this->entite)->setCreateur($this->user)->setSession($session)->setType(\App\Enum\SessionPieceType::EMARGEMENT_SIGNE)->setFilename('attendance.pdf')->setUploadedAt(new \DateTimeImmutable('2025-09-15 10:00:00', new \DateTimeZone('Europe/Paris')));
                $this->em->persist($piece);
            }
            foreach ([$site, $session, $ins] as $entity) $this->em->persist($entity);
            $this->em->flush();
            $ids[] = $ins->getId();
        }
        $base = '/fr/administrateur/'.$this->entite->getId().'/qualite';
        $page = $this->client->request('GET', $base);
        self::assertSame(2, $page->filter('.quality-paper-row')->count());
        $token = $page->filter('#quality-paper-bulk input[name="_token"]')->attr('value');
        $this->client->request('POST', $base.'/presences-papier/groupe', ['_token' => $token, 'inscriptions' => $ids]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->em->find(\App\Entity\Inscription::class, $ids[0])->getPresencesManuelles());
        $this->client->request('POST', $base.'/presences-papier/groupe', ['_token' => $token, 'inscriptions' => $ids, 'confirmed' => '1']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $manual = $this->em->find(\App\Entity\Inscription::class, $ids[0])->getPresencesManuelles();
        self::assertSame('absent', $manual['2025-09-10:AM']['status']);
        self::assertSame('Absence justifiée', $manual['2025-09-10:AM']['reference']);
        self::assertSame('paper', $manual['2025-09-10:PM']['status']);
        self::assertStringContainsString('15/09/2025 à 10:00', $manual['2025-09-10:PM']['reference']);
        self::assertSame([], $this->em->find(\App\Entity\Inscription::class, $ids[1])->getPresencesManuelles());
    }

    public function testQualityShortcutsPrepareOnlyAnUnsavedDraft(): void
    {
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/qualite/registre/nouveau?kind=legal_watch');
        $form = $page->selectButton('Enregistrer la fiche')->form();
        self::assertSame('legal_watch', $form['quality_record[kind]']->getValue());
        self::assertSame('Camille Martin', $form['quality_record[owner]']->getValue());
        self::assertSame('open', $form['quality_record[status]']->getValue());
        self::assertSame('', $form['quality_record[description]']->getValue());
        self::assertSame(0, $this->em->getRepository(QualityRecord::class)->count([]));
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count([]));
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/qualite/registre/nouveau?kind=unknown');
        self::assertSame('improvement', $page->selectButton('Enregistrer la fiche')->form()['quality_record[kind]']->getValue());
    }
}
