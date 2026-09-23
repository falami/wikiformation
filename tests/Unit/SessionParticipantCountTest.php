<?php

namespace App\Tests\Unit;

use App\Entity\{ConventionContrat, Entite, Inscription, Session, Utilisateur};
use App\Enum\StatusInscription;
use App\Service\Session\SessionParticipantCount;
use PHPUnit\Framework\TestCase;

final class SessionParticipantCountTest extends TestCase
{
    private Session $session;
    private SessionParticipantCount $counter;

    protected function setUp(): void
    {
        $this->session = (new Session())->setEntite(new Entite());
        $this->counter = new SessionParticipantCount();
    }

    public function testDeclaredCountIsUsedBeforeNamesAndUnchangedWhenNamesArrive(): void
    {
        $convention = $this->convention(12);
        self::assertSame(12, $this->counter->count($this->session));
        $convention->addInscription($this->inscription());
        $this->inscription();
        self::assertSame(12, $this->counter->count($this->session));
        // Une ancienne convention incohérente conserve le chiffre explicitement déclaré.
        $convention->setEffectifPrevisionnel(1)->addInscription($this->inscription());
        self::assertSame(1, $this->counter->count($this->session));
    }

    public function testSeveralConventionsAddAnonymousPlacesButOnlyCountKnownPeopleOnce(): void
    {
        $shared = $this->inscription();
        $this->convention(5)->addInscription($shared);
        $this->convention(3)->addInscription($shared);
        $this->convention(2);
        self::assertSame(9, $this->counter->count($this->session));
    }

    public function testInconsistentHistoricalNamesCannotMakeTotalDependOnConventionOrder(): void
    {
        $first = $this->inscription();
        $second = $this->inscription();
        $old = $this->convention(1)->addInscription($first)->addInscription($second);
        $this->convention(5)->addInscription($first)->addInscription($second);
        self::assertSame(6, $this->counter->count($this->session));
        $this->session->removeConventionContrat($old)->addConventionContrat($old);
        self::assertSame(6, $this->counter->count($this->session));
    }

    public function testWithoutDeclaredCountConventionUsesActiveNamedAndFreeParticipants(): void
    {
        $shared = $this->inscription();
        $cancelled = $this->inscription()->setStatus(StatusInscription::ANNULE);
        $this->convention()->addInscription($shared)->addInscription($cancelled)->setParticipantsLibres("Alice Martin\nBob Durand");
        $this->convention()->addInscription($shared)->addInscription($this->inscription());
        self::assertSame(4, $this->counter->count($this->session));
        self::assertSame(['Alice Martin', 'Bob Durand'], $this->counter->freeParticipantNames($this->session));
    }

    public function testWithoutConventionFallbackCountsUniqueActiveStudentsAndExcludesTrainer(): void
    {
        $participant = new Utilisateur();
        $trainer = new Utilisateur();
        $this->inscription($participant);
        $this->inscription($participant);
        $this->inscription($trainer);
        $this->inscription()->setStatus(StatusInscription::ANNULE);
        self::assertSame(1, $this->counter->count($this->session, $trainer));
        self::assertCount(1, $this->counter->participants($this->session, $trainer));
    }

    public function testForeignSessionOrOrganismRecordsCannotContribute(): void
    {
        $this->convention(30)->setEntite(new Entite())->setParticipantsLibres('Foreign participant');
        $this->convention(20)->setSession(new Session());
        $this->inscription()->setEntite(new Entite());
        $this->inscription()->setSession(new Session());
        $this->inscription();
        self::assertSame(1, $this->counter->count($this->session));
        self::assertSame([], $this->counter->freeParticipantNames($this->session));
    }

    public function testEmptyConventionFallsBackAndSignedConventionStillDefinesExpectedCount(): void
    {
        $convention = $this->convention();
        self::assertSame(0, $this->counter->count($this->session));
        $this->inscription();
        self::assertSame(1, $this->counter->count($this->session));
        $convention->setEffectifPrevisionnel(10)->setDateSignatureEntreprise(new \DateTimeImmutable());
        self::assertSame(10, $this->counter->count($this->session));
    }

    private function convention(?int $count = null): ConventionContrat
    {
        $convention = (new ConventionContrat())->setEntite($this->session->getEntite())->setEffectifPrevisionnel($count);
        $this->session->addConventionContrat($convention);
        return $convention;
    }

    private function inscription(?Utilisateur $participant = null): Inscription
    {
        $inscription = (new Inscription())->setEntite($this->session->getEntite())->setStagiaire($participant ?? new Utilisateur())->setStatus(StatusInscription::CONFIRME);
        $this->session->addInscription($inscription);
        return $inscription;
    }
}
