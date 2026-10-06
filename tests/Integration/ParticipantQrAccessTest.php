<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionParticipantAccess, Site, Utilisateur};
use App\Enum\{DemiJournee, StatusInscription, StatusSession};
use App\Service\Session\ParticipantQrAccess;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ParticipantQrAccessTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParticipantQrAccess $access;
    private Session $session;
    private ConventionContrat $convention;
    private Inscription $inscription;
    private Utilisateur $admin;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->access = self::getContainer()->get(ParticipantQrAccess::class);
        $this->admin = (new Utilisateur())->setEmail('qr-admin@example.test')->setPassword('unused')->setPrenom('Alex')->setNom('Admin');
        $learner = (new Utilisateur())->setEmail('qr-learner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Durand');
        $entite = (new Entite())->setNom('QR Test')->setCreateur($this->admin)->setPublic(false);
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom('Salle')->setSlug('qr-salle');
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre('Formation QR')->setSlug('formation-qr');
        $this->session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setFormation($formation)->setCode('QR-SESSION');
        $this->session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('today 08:30'))->setDateFin(new \DateTimeImmutable('today 17:00')));
        $this->inscription = (new Inscription())->setSession($this->session)->setEntite($entite)->setCreateur($this->admin)->setStagiaire($learner);
        $this->convention = (new ConventionContrat())->setSession($this->session)->setEntite($entite)->setCreateur($this->admin)->setNumero('QR-CONV')->setParticipantsLibres("Alex Martin\nJamie Dupont");
        foreach ([$this->admin, $learner, $entite, $site, $formation, $this->session, $this->inscription, $this->convention] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testOldUnfinishedSessionAccessIsRenewedOnlyByAuthorizedRoster(): void
    {
        foreach ($this->session->getJours() as $day) {
            $day->setDateDebut(new \DateTimeImmutable('-180 days 08:30'));
            $day->setDateFin(new \DateTimeImmutable('-180 days 17:00'));
        }
        $this->em->flush();
        $access = $this->access->forInscription($this->inscription);
        $expiredToken = $this->access->token($access, 'satisfaction');
        self::assertNull($this->access->resolve($expiredToken, 'satisfaction'));
        $roster = $this->access->roster($this->session);
        $expiry = $access->getExpiresAt();
        self::assertEqualsWithDelta(time() + 7 * 86400, $expiry->getTimestamp(), 5);
        $token = $this->access->token($access, 'satisfaction');
        self::assertSame($access, $this->access->resolve($token, 'satisfaction'));
        self::assertNull($this->access->resolve($expiredToken, 'satisfaction'));
        self::assertSame($expiry, $this->access->forInscription($this->inscription)->getExpiresAt());
        self::assertSame($expiry, $this->access->roster($this->session)[0]->getExpiresAt());
        // Newly created guest accesses also receive only seven days.
        self::assertEqualsWithDelta(time() + 7 * 86400, $roster[1]->getExpiresAt()->getTimestamp(), 5);
        $this->session->setStatus(StatusSession::DONE);
        self::assertNull($this->access->resolve($token, 'satisfaction'));
        self::assertLessThan(time(), $this->access->roster($this->session)[0]->getExpiresAt()->getTimestamp());
    }

    public function testSessionLifecycleStartsAndAssignsWithoutOverridingWaitingStates(): void
    {
        $lifecycle = self::getContainer()->get(\App\Service\Session\SessionLifecycle::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        foreach ($this->session->getJours() as $day) {
            $day->setDateDebut($now->modify('-1 hour'))->setDateFin($now->modify('+1 hour'));
        }
        $template = (new \App\Entity\SatisfactionTemplate())->setEntite($this->session->getEntite())->setCreateur($this->admin);
        $this->em->persist($template);
        $this->session->getFormation()->setSatisfactionTemplate($template);
        $this->session->setStatus(StatusSession::PUBLISHED);
        $this->em->flush();
        $lifecycle->synchronize($this->session->getEntite());
        self::assertSame(StatusSession::IN_PROGRESS, $this->session->getStatus());
        self::assertSame(1, $this->em->getRepository(\App\Entity\SatisfactionAssignment::class)->count(['session' => $this->session]));
        $lifecycle->assignQuestionnaires($this->session);
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(\App\Entity\SatisfactionAssignment::class)->count(['session' => $this->session]));
        foreach ([StatusSession::DRAFT, StatusSession::ON_HOLD, StatusSession::MISSING_DOCUMENTS, StatusSession::DONE, StatusSession::CANCELED] as $status) {
            $this->session->setStatus($status);
            self::assertFalse($lifecycle->shouldStart($this->session, $now));
        }
        $this->session->setStatus(StatusSession::FULL);
        self::assertFalse($lifecycle->shouldStart($this->session, $now->modify('-2 hours')));
        self::assertFalse($lifecycle->shouldStart($this->session, $now->modify('+2 hours')));
    }

    public function testRosterIsStableAndDoesNotCreateAccounts(): void
    {
        $roster = $this->access->roster($this->session);
        self::assertCount(3, $roster);
        self::assertSame(['Camille Durand', 'Alex Martin', 'Jamie Dupont'], array_map(fn($p) => $p->getDisplayName(), $roster));
        self::assertFalse($roster[0]->isGuest());
        self::assertTrue($roster[1]->isGuest());
        self::assertSame($roster[0], $this->access->forInscription($this->inscription));
        self::assertSame($roster, $this->access->roster($this->session));
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Inscription::class)->count([]));
    }

    public function testTokensCannotBeChangedOrUsedForAnotherPurpose(): void
    {
        $guest = $this->access->roster($this->session)[1];
        $token = $this->access->token($guest, 'attendance');
        self::assertSame($guest, $this->access->resolve($token, 'attendance'));
        self::assertNull($this->access->resolve($token, 'satisfaction'));
        self::assertNull($this->access->resolve(substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a'), 'attendance'));
        $guest->setExpiresAt(new \DateTimeImmutable('-1 second'));
        self::assertNull($this->access->resolve($this->access->token($guest, 'attendance'), 'attendance'));
    }

    public function testRemovingGuestImmediatelyInvalidatesQrAndPreservesSignature(): void
    {
        $guest = $this->access->roster($this->session)[1];
        $token = $this->access->token($guest, 'attendance');
        $signature = (new Emargement())->setSession($this->session)->setEntite($this->session->getEntite())->setCreateur($this->admin)->setParticipantAccess($guest)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('today'))->setPeriode(DemiJournee::AM)->setSignedAt(new \DateTimeImmutable())->setSignaturePath('test/signature.png');
        $this->em->persist($signature);
        $this->convention->setParticipantsLibres('Jamie Dupont');
        $this->em->flush();
        self::assertNull($this->access->resolve($token, 'attendance'), 'No roster refresh is needed to revoke a removed name.');
        self::assertCount(2, $this->access->roster($this->session));
        self::assertFalse($guest->isActive());
        self::assertSame('Alex Martin', $signature->getParticipantAccess()->getDisplayName());
        self::assertNotNull($signature->getSignedAt());
        self::assertSame(1, $this->em->getRepository(Emargement::class)->count([]));
        $this->convention->setParticipantsLibres("Alex Martin\nJamie Dupont");
        $this->em->flush();
        $this->access->roster($this->session);
        self::assertTrue($guest->isActive());
        self::assertNull($this->access->resolve($token, 'attendance'), 'Re-adding a name must not restore an old QR token.');
    }

    public function testCancelledEnrollmentOrSessionAndTenantMismatchRejectToken(): void
    {
        $participant = $this->access->forInscription($this->inscription);
        $token = $this->access->token($participant, 'satisfaction');
        $this->inscription->setStatus(StatusInscription::ANNULE);
        self::assertNull($this->access->resolve($token, 'satisfaction'));
        $this->inscription->setStatus(StatusInscription::ABSENT);
        self::assertNull($this->access->resolve($token, 'satisfaction'));
        $this->inscription->setStatus(StatusInscription::CONFIRME);
        $this->session->setStatus(StatusSession::CANCELED);
        self::assertNull($this->access->resolve($token, 'satisfaction'));
        $this->session->setStatus(StatusSession::DONE);
        self::assertSame($participant, $this->access->resolve($token, 'satisfaction'), 'Completion retains access to the post-training questionnaire.');
        $foreign = (new Entite())->setNom('Other')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($foreign);
        $this->em->flush();
        $participant->setEntite($foreign);
        self::assertNull($this->access->resolve($token, 'satisfaction'));
    }

    public function testReassigningEnrollmentDoesNotReuseThePreviousPersonsQr(): void
    {
        $participant = $this->access->forInscription($this->inscription);
        $token = $this->access->token($participant, 'attendance');
        $this->inscription->setStagiaire($this->admin);
        $this->em->flush();
        self::assertNull($this->access->resolve($token, 'attendance'));
        $replacement = $this->access->forInscription($this->inscription);
        self::assertNotSame($participant->getId(), $replacement->getId());
        self::assertSame('Camille Durand', $participant->getDisplayName());
        self::assertSame('Alex Admin', $replacement->getDisplayName());
    }

    public function testRenamingAndHomonymsKeepDistinctEvidence(): void
    {
        $this->convention->setParticipantsLibres("Alex Martin\nAlex Martin");
        $this->em->flush();
        $roster = $this->access->roster($this->session);
        self::assertCount(3, $roster);
        self::assertNotSame($roster[1]->getPublicId(), $roster[2]->getPublicId());
        $old = $this->access->token($roster[1], 'attendance');
        $this->convention->setParticipantsLibres('Alexandre Martin');
        $this->em->flush();
        self::assertNull($this->access->resolve($old, 'attendance'));
        $renamed = $this->access->roster($this->session)[1];
        self::assertSame('Alexandre Martin', $renamed->getDisplayName());
        self::assertNotSame($roster[1]->getId(), $renamed->getId());
        self::assertSame(4, $this->em->getRepository(SessionParticipantAccess::class)->count([]));
    }
}
