<?php

namespace App\Tests\Service\Convention;

use App\Entity\{ConventionContrat, Devis, Entite, Entreprise, Formation, Inscription, Session, SessionJour, Site, Utilisateur};
use App\Enum\{DevisStatus, StatusInscription};
use App\Service\Convention\DevisConventionCreator;
use App\Service\Sequence\{ConventionContratNumberGenerator, SequenceNumberManager, SessionNumberGenerator};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;

final class DevisConventionCreatorTest extends TestCase
{
    private EntityManagerInterface $em;
    private DevisConventionCreator $creator;
    private Entite $entite;
    private Entreprise $entreprise;
    private Devis $devis;
    private Session $session;
    private Utilisateur $user;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn(callable $work) => $work());
        $sequence = $this->createMock(SequenceNumberManager::class);
        $number = 0;
        $sequence->method('next')->willReturnCallback(static function () use (&$number) { return [2026, ++$number]; });
        $this->creator = new DevisConventionCreator($this->em, new ConventionContratNumberGenerator($sequence), new SessionNumberGenerator($sequence));
        $this->entite = $this->identified(new Entite(), 1);
        $this->entreprise = $this->identified((new Entreprise())->setEntite($this->entite), 2);
        $formation = $this->identified((new Formation())->setEntite($this->entite), 3);
        $site = $this->identified((new Site())->setEntite($this->entite), 4);
        $this->devis = $this->identified((new Devis())->setEntite($this->entite)->setFormation($formation)->setEntrepriseDestinataire($this->entreprise), 5);
        $this->session = (new Session())->setEntite($this->entite)->setFormation($formation)->setSite($site)->setCapacite(8);
        $this->session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-01 17:00')));
        $this->user = $this->participant(10);
    }

    public function testNewSessionCreatesGroupConventionAndDossiers(): void
    {
        $one = $this->participant(11);
        $two = $this->participant(12);
        $convention = $this->creator->create($this->devis, $this->session, [$one, $two, $one], $this->user, 'Paiement à 30 jours');
        self::assertCount(2, $convention->getInscriptions());
        self::assertCount(2, $this->session->getInscriptions());
        self::assertCount(2, $this->devis->getInscriptions());
        self::assertSame($this->devis, $convention->getDevis());
        self::assertSame($this->entreprise, $convention->getEntreprise());
        self::assertStringStartsWith('SES-E1-', $this->session->getCode());
        self::assertStringStartsWith('CONV-E1-', $convention->getNumero());
        foreach ($convention->getInscriptions() as $inscription) {
            self::assertSame($inscription, $inscription->getDossier()->getInscription());
            self::assertSame($this->entite, $inscription->getEntite());
            self::assertTrue($inscription->getConventionContrats()->contains($convention));
            self::assertNull($inscription->getMontantDuCents(), 'Le montant total du devis ne doit pas être facturé à chaque stagiaire.');
        }
    }

    public function testExistingInscriptionAndOtherConventionsArePreserved(): void
    {
        $participant = $this->participant(11);
        $inscription = (new Inscription())->setEntite($this->entite)->setSession($this->session)->setStagiaire($participant)->setEntreprise($this->entreprise);
        $previous = (new ConventionContrat())->setEntite($this->entite)->setSession($this->session)->setEntreprise($this->entreprise)->addInscription($inscription);
        $this->existingSession([$inscription]);
        $convention = $this->creator->create($this->devis, $this->session, [$participant], $this->user, null);
        self::assertSame($inscription, $convention->getInscriptions()->first());
        self::assertCount(2, $this->session->getConventionContrats());
        self::assertTrue($previous->getInscriptions()->contains($inscription));
        self::assertCount(2, $inscription->getConventionContrats());
    }

    public function testIndividualRecipientCanUseExistingEmployeeInscription(): void
    {
        $participant = $this->participant(11);
        $this->devis->setEntrepriseDestinataire(null)->setDestinataire($participant);
        $inscription = (new Inscription())->setEntite($this->entite)->setSession($this->session)->setStagiaire($participant)->setEntreprise($this->entreprise);
        $this->existingSession([$inscription]);
        $convention = $this->creator->create($this->devis, $this->session, [$participant], $this->user, null);
        self::assertNull($convention->getEntreprise());
        self::assertSame($participant, $convention->getStagiaire());
        self::assertSame($this->entreprise, $inscription->getEntreprise());
    }

    public function testForeignTenantParticipantIsRejectedBeforeWrites(): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$this->identified(new Utilisateur(), 99)], $this->user, null);
    }

    public function testCapacityCountsExistingParticipantsOnlyOnce(): void
    {
        $participant = $this->participant(11);
        $this->session->setCapacite(1);
        $inscription = (new Inscription())->setEntite($this->entite)->setSession($this->session)->setStagiaire($participant)->setEntreprise($this->entreprise);
        $this->existingSession([$inscription]);
        self::assertCount(1, $this->creator->create($this->devis, $this->session, [$participant], $this->user, null)->getInscriptions());
    }

    public function testOverCapacityIsRejectedBeforeWrites(): void
    {
        $this->session->setCapacite(1);
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$this->participant(11), $this->participant(12)], $this->user, null);
    }

    public function testExistingCompanyCannotBeSilentlyReassigned(): void
    {
        $participant = $this->participant(11);
        $other = $this->identified((new Entreprise())->setEntite($this->entite), 99);
        $inscription = (new Inscription())->setEntite($this->entite)->setSession($this->session)->setStagiaire($participant)->setEntreprise($other);
        $this->existingSession([$inscription]);
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$participant], $this->user, null);
    }

    public function testCancelledInscriptionCannotBeReused(): void
    {
        $participant = $this->participant(11);
        $inscription = (new Inscription())->setEntite($this->entite)->setSession($this->session)->setStagiaire($participant)->setEntreprise($this->entreprise)->setStatus(StatusInscription::ANNULE);
        $this->existingSession([$inscription]);
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$participant], $this->user, null);
    }

    public function testCancelledQuoteCannotBeConverted(): void
    {
        $this->devis->setStatus(DevisStatus::CANCELED);
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$this->participant(11)], $this->user, null);
    }

    public function testOverlappingNewSessionDatesAreRejected(): void
    {
        $this->session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-01 16:00'))->setDateFin(new \DateTimeImmutable('2026-10-01 18:00')));
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->creator->create($this->devis, $this->session, [$this->participant(11)], $this->user, null);
    }

    private function existingSession(array $inscriptions): void
    {
        $this->identified($this->session, 20);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($inscriptions);
        $this->em->method('getRepository')->with(Inscription::class)->willReturn($repo);
        $this->em->expects(self::once())->method('lock');
    }

    private function participant(int $id): Utilisateur
    {
        return $this->identified((new Utilisateur())->setEntite($this->entite), $id);
    }

    private function identified(object $entity, int $id): mixed
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }
}
