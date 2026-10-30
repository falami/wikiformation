<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite, Session, SessionJour, Site, Inscription};
use App\Enum\{StatusSession, StatusInscription, TypeFinancement};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LearnerQuestionnairesTest extends KernelTestCase
{
    private array $database; private $em; private $client;
    private Entite $entite; private Entite $other; private Utilisateur $admin; private Session $session;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel(); $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@passport.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin');
        $this->entite = (new Entite())->setNom('WikiFormation Démonstration')->setCreateur($this->admin)->setPublic(false);
        $this->other = (new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);
        foreach ([$this->admin, $this->entite, $this->other] as $e) $this->em->persist($e);
        $this->em->flush(); $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->admin)->setEntite($this->entite)->setCreateur($this->admin)->setRoles(['TENANT_STAGIAIRE']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->session = $this->createSession($this->entite, 'SES-2026-0042', 'Prévention des risques électriques');
        $this->addLearner($this->session, StatusInscription::TERMINE);
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false);
        $this->client->loginUser($this->admin);
    }
    private function createSession(Entite $entite, string $code, string $title): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom('Montpellier')->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setCode($code)->setFormationIntituleLibre($title)->setSite($site)->setStatus(StatusSession::DONE);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('2026-10-05 09:00 Europe/Paris'))->setDateFin(new \DateTimeImmutable('2026-10-05 17:00 Europe/Paris')));
        $this->em->persist($site); $this->em->persist($session);
        return $session;
    }
    private function addLearner(Session $session, StatusInscription $status): Inscription
    {
        $i = (new Inscription())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setStagiaire($this->admin)->setStatus($status);
        $session->addInscription($i); $this->em->persist($i); return $i;
    }
    protected function tearDown(): void
    {
        parent::tearDown(); foreach (['ENV','SERVER'] as $i => $n) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$n]['DATABASE_URL']); else $GLOBALS['_'.$n]['DATABASE_URL'] = $this->database[$i];
        }
    }

    private function position(string $title, ?Utilisateur $learner = null): \App\Entity\PositioningAssignment
    {
        $q = (new \App\Entity\PositioningQuestionnaire())->setTitle($title)->setIsPublished(true)->setCreateur($this->admin)->setEntite($this->entite);
        $this->em->persist($q);
        return (new \App\Service\Positioning\PositioningAssigner($this->em))->assignToUser($learner ?? $this->admin, $q, $this->admin, $this->entite, true);
    }
    private function pending(): array
    {
        return (new \App\Service\Stagiaire\PendingQuestionnaires($this->em, self::getContainer()->get('router')))->forLearner($this->entite, $this->admin);
    }
    public function testPendingCardsAreScopedAndDisappearAfterSubmission(): void
    {
        $position = $this->position('Positionnement <Excel>');
        $done = $this->position('Déjà terminé'); $done->getAttempt()->setSubmittedAt(new \DateTimeImmutable());
        $otherUser = (new Utilisateur())->setEmail('other@learner.test')->setPassword('unused')->setNom('Autre')->setPrenom('Camille'); $this->em->persist($otherUser);
        $this->position('Autre stagiaire', $otherUser);
        $hidden = $this->position('Autre organisme'); $hidden->setEntite($this->other); $hidden->getQuestionnaire()->setEntite($this->other); $hidden->getAttempt()->setEntite($this->other);
        $q = (new \App\Entity\Qcm())->setTitre('Niveau Excel')->setEntite($this->entite)->setCreateur($this->admin); $this->em->persist($q);
        $a = (new \App\Entity\QcmAssignment())->setSession($this->session)->setInscription($this->session->getInscriptions()->first())->setQcm($q)->setEntite($this->entite)->setCreateur($this->admin);
        $this->em->persist($a); $this->em->flush();
        $items = $this->pending(); self::assertCount(2, $items); self::assertFalse($items[0]['started']); self::assertFalse($items[1]['started']);
        self::assertStringEndsWith('/'.$position->getAttempt()->getId().'/fill', $items[0]['url']);
        self::assertStringEndsWith('/qcm/'.$a->getId(), $items[1]['url']);
        $position->getAttempt()->setStagiaireComment('En cours'); $this->em->flush(); self::assertTrue($this->pending()[0]['started']);
        $position->getAttempt()->setSubmittedAt(new \DateTimeImmutable());
        $attempt = (new \App\Entity\QcmAttempt())->setAssignment($a)->setEntite($this->entite)->setCreateur($this->admin)->setSubmittedAt(new \DateTimeImmutable());
        $a->setAttempt($attempt); $this->em->persist($attempt); $this->em->flush();
        self::assertSame([], $this->pending());
    }
    public function testDashboardAndCancelledRegistration(): void
    {
        $position = $this->position('Positionnement Excel');
        $position->setInscription($this->session->getInscriptions()->first())->setSession($this->session);
        $q = (new \App\Entity\Qcm())->setTitre('Excel — évaluation des acquis')->setEntite($this->entite)->setCreateur($this->admin); $this->em->persist($q);
        $a = (new \App\Entity\QcmAssignment())->setSession($this->session)->setInscription($this->session->getInscriptions()->first())->setQcm($q)->setEntite($this->entite)->setCreateur($this->admin)->setPhase(\App\Enum\QcmPhase::POST)->setStatus(\App\Enum\QcmAssignmentStatus::STARTED);
        $attempt = (new \App\Entity\QcmAttempt())->setAssignment($a)->setEntite($this->entite)->setCreateur($this->admin);
        $a->setAttempt($attempt); $this->em->persist($a); $this->em->persist($attempt);
        $this->em->flush();
        $this->client->request('GET', '/fr/stagiaire/'.$this->entite->getId().'/dashboard');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Questionnaires à compléter', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Positionnement Excel', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Reprendre', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Commencer', $this->client->getResponse()->getContent());
        if ($dir = getenv('LEARNER_PREVIEW_DIR')) file_put_contents($dir.'/index.html', $this->client->getResponse()->getContent());
        $registration = $this->em->getRepository(Inscription::class)->findOneBy(['stagiaire'=>$this->admin->getId()]);
        $registration->setStatus(StatusInscription::ANNULE); $this->em->flush();
        self::assertSame([], $this->pending());
    }
}
