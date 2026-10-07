<?php

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Enum\{DemiJournee, SessionPieceType, StatusSession, TypeFinancement};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Attendance alerts are tested against an isolated database, including legacy records. */
final class InscriptionBatchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private Session $internal;
    private Session $subcontracted;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('attendance-admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->learner = (new Utilisateur())->setEmail('attendance-learner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->learner, $this->entite] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('attendance-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->internal = $this->session('SES-INTERNAL', $this->entite, false);
        $this->subcontracted = $this->session('SES-SUBCONTRACTED', $this->entite, true);
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($other);
        $foreign = $this->session('SES-FOREIGN', $other, false);
        foreach ([$this->internal, $this->subcontracted, $foreign] as $session) {
            $this->em->persist((new Emargement())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setUtilisateur($this->learner)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('yesterday'))->setPeriode(DemiJournee::AM));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testBulkStatusCloseAndDelete(): void
    {
        $numbers = $this->createMock(\App\Service\Sequence\AttestationNumberGenerator::class);
        $numbers->method('nextForEntite')->willReturnOnConsecutiveCalls('ATT-TEST-1', 'ATT-TEST-2');
        self::getContainer()->set(\App\Service\Sequence\AttestationNumberGenerator::class, $numbers);
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->expects(self::exactly(1))->method('attestation')->willReturn('/tmp/attestation-test.pdf');
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $repo = $this->em->getRepository(Inscription::class);
        $items = $repo->findBy(['entite' => $this->entite]);
        foreach ($items as $item) {
            foreach (self::getContainer()->get(\App\Service\AssiduiteCalculator::class)->periods($item) as $key => $_) {
                $item->enregistrerPresenceManuelle($key, 'paper', 'Feuille déposée', $this->admin);
            }
        }
        $this->em->flush();
        $ids = array_map(fn($item) => $item->getId(), $items);
        $router = self::getContainer()->get('router');
        $url = $router->generate('app_administrateur_inscription_batch', ['entite' => $this->entite->getId()]);
        $client = $this->client();
        $crawler = $client->request('GET', $router->generate('app_administrateur_inscription_index', ['entite' => $this->entite->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#inscriptionBatchModal')->attr('data-token');
        $params = ['_token' => $token, 'ids' => json_encode($ids), 'action' => 'status', 'status' => 'en_cours'];
        $client->request('POST', $url, array_replace($params, ['_token' => 'invalid']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, array_replace($params, ['status' => 'unknown']));
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $foreign = array_values(array_filter($repo->findAll(), fn($item) => $item->getEntite()->getId() !== $this->entite->getId()))[0];
        $before = $items[0]->getStatus();
        $client->request('POST', $url, array_replace($params, ['ids' => json_encode([$ids[0], $foreign->getId()])]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($before, $items[0]->getStatus());
        $client->request('POST', $url, $params);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $items = $repo->findBy(['id' => $ids]);
        foreach ($items as $item) self::assertSame(\App\Enum\StatusInscription::EN_COURS, $item->getStatus());
        $client->request('POST', $url, array_replace($params, ['action' => 'close']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $items = $repo->findBy(['id' => $ids]);
        foreach ($items as $item) {
            self::assertSame(\App\Enum\StatusInscription::TERMINE, $item->getStatus());
            if ($item->getSession()->isSousTraitance()) self::assertNull($item->getTauxAssiduite());
            else self::assertSame(100.0, $item->getTauxAssiduite());
        }
        self::assertSame(1, $this->em->getRepository(\App\Entity\Attestation::class)->count([]));
        $client->request('POST', $url, array_replace($params, ['action' => 'delete']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $repo->count(['entite' => $this->entite]));
        self::assertSame(1, $repo->count([]));
    }

    public function testManualAttendanceAndUnknownRate(): void
    {
        $calculator = self::getContainer()->get(\App\Service\AssiduiteCalculator::class);
        $ins = $this->em->getRepository(Inscription::class)->findOneBy(['session' => $this->internal]);
        $id = $ins->getId();
        self::assertNull($calculator->computeForInscription($ins));
        $keys = array_keys($calculator->periods($ins));
        $router = self::getContainer()->get('router');
        $client = $this->client();
        $crawler = $client->request('GET', $router->generate('app_administrateur_inscription_show', ['entite' => $this->entite->getId(), 'id' => $id]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#presences-papier input[name="_token"]')->attr('value');
        $url = $router->generate('app_administrateur_inscription_paper_attendance', ['entite' => $this->entite->getId(), 'id' => $id]);
        $data = ['_token' => $token, 'presence' => array_fill_keys($keys, 'paper'), 'reference' => array_fill_keys($keys, 'Feuille signée déposée')];
        $data['presence'][$keys[0]] = 'absent';
        $client->request('POST', $url, $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $ins = $this->em->find(Inscription::class, $id);
        self::assertSame(75.0, $ins->getTauxAssiduite());
        self::assertSame($this->admin->getId(), $ins->getPresencesManuelles()[$keys[0]]['actor']);
        $data['presence'][$keys[0]] = 'paper';
        $client->request('POST', $url, $data);
        $ins = $this->em->find(Inscription::class, $id);
        self::assertSame(100.0, $ins->getTauxAssiduite());
        self::assertCount(1, $ins->getPresencesManuelles()[$keys[0]]['history']);
        $data['presence'][$keys[0]] = 'unknown';
        $client->request('POST', $url, $data);
        $ins = $this->em->find(Inscription::class, $id);
        self::assertNull($ins->getTauxAssiduite());
        $data['presence']['2099-01-01:AM'] = 'paper';
        $client->request('POST', $url, $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $ins = $this->em->find(Inscription::class, $id);
        self::assertArrayNotHasKey('2099-01-01:AM', $ins->getPresencesManuelles());
        [$day, $half] = explode(':', $keys[0]);
        $signed = (new Emargement())->setSession($ins->getSession())->setEntite($ins->getEntite())->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))
            ->setUtilisateur($ins->getStagiaire())->setRole('stagiaire')->setDateJour(new \DateTimeImmutable($day))
            ->setPeriode(DemiJournee::from($half))->setSignaturePath('signed.png');
        $this->em->persist($signed); $this->em->flush();
        self::assertSame(100.0, $calculator->computeForInscription($ins));
        unset($data['presence']['2099-01-01:AM']);
        $data['presence'][$keys[0]] = 'absent';
        $client->request('POST', $url, $data);
        $ins = $this->em->find(Inscription::class, $id);
        self::assertSame('unknown', $ins->getPresencesManuelles()[$keys[0]]['status']);
        self::assertSame('signed.png', $signed->getSignaturePath());

    }

    public function testTrainerPaperAttendanceAccessAndPage(): void
    {
        $trainer = (new \App\Entity\Formateur())->setUtilisateur($this->admin)->setEntite($this->entite)->setCreateur($this->admin);
        $this->em->persist($trainer);
        $this->internal->setFormateur($trainer);
        $this->em->flush();
        $client = $this->client();
        $client->catchExceptions(true);
        $url = self::getContainer()->get('router')->generate('app_formateur_paper_manage', ['entite'=>$this->entite->getId(), 'id'=>$this->internal->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Les feuilles justificatives', $client->getResponse()->getContent());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $tmp = tempnam(sys_get_temp_dir(), 'paper-test');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $client->request('POST', $url, ['_token'=>$token,'action'=>'upload'], ['file'=>new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'sheet.png', 'image/png', null, true)]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $piece = $this->em->getRepository(SessionPiece::class)->findOneBy(['session'=>$this->internal, 'type'=>SessionPieceType::EMARGEMENT_SIGNE]);
        self::assertNotNull($piece);
        try {
            $crawler = $client->request('GET', $url);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $ins = $this->em->getRepository(Inscription::class)->findOneBy(['session'=>$this->internal]);
            $periods = self::getContainer()->get(\App\Service\AssiduiteCalculator::class)->periods($ins);
            $states = array_fill_keys(array_keys($periods), 'paper');
            $states[array_key_first($states)] = 'absent';
            $client->request('POST', $url, ['_token'=>$token, 'inscription'=>$ins->getId(), 'piece'=>$piece->getId(), 'presence'=>$states]);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $ins = $this->em->find(Inscription::class, $ins->getId());
            self::assertSame('absent', $ins->getPresencesManuelles()[array_key_first($states)]['status']);
            self::assertEquals(75, $ins->getTauxAssiduite());
        } finally {
            unlink(self::getContainer()->getParameter('session_piece_dir').'/'.$piece->getFilename());
        }

        $client->request('POST', $url, ['_token'=>'invalid', 'action'=>'upload']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $otherUrl = self::getContainer()->get('router')->generate('app_formateur_paper_manage', ['entite'=>$this->entite->getId(), 'id'=>$this->subcontracted->getId()]);
        $client->request('GET', $otherUrl);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function session(string $code, Entite $entite, bool $subcontracted): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom($code)->setSlug(strtolower($code));
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre($code)->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setCode($code);
        if ($subcontracted) $session->setTypeFinancement(TypeFinancement::OUI)->setFormationIntituleLibre('Formation sous-traitée');
        else $session->setFormation($formation);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('yesterday 08:30'))->setDateFin(new \DateTimeImmutable('yesterday 17:00')));
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('-2 days 08:30'))->setDateFin(new \DateTimeImmutable('-2 days 17:00')));
        $inscription = (new Inscription())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setStagiaire($this->learner);
        $dossier = (new DossierInscription())->setInscription($inscription)->setEntite($entite)->setCreateur($this->admin);
        $piece = (new SessionPiece())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setType(SessionPieceType::CONVENTION_SIGNEE)->setFilename('convention-test.pdf');
        foreach ([$site, $formation, $session, $inscription, $dossier, $piece] as $entity) $this->em->persist($entity);
        return $session;
    }

    private function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        return $client;
    }

}
