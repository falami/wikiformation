<?php
namespace App\Tests\Unit;
use App\Entity\{Session, Entite, ConventionContrat, ContratFormateur, Formateur, Devis, Formation, Inscription, Utilisateur};
use App\Enum\ContratFormateurStatus;
use App\Service\Session\SessionFinancialSummary;
use PHPUnit\Framework\TestCase;
final class SessionFinancialSummaryTest extends TestCase
{
    public function testDelegatedTrainerIsCoveredWithoutDuplicatingContractCost(): void
    {
        $entity = new Entite();
        $holder = (new Formateur())->setEntite($entity);
        $actual = (new Formateur())->setEntite($entity);
        $session = (new Session())->setEntite($entity)->setFormateur($actual);
        $contract = $this->contract($session, $holder, 90000);
        $calculator = new SessionFinancialSummary();
        self::assertNull($calculator->calculate($session, [$this->convention($session, 189000)], [$contract])['remaining']);
        $contract->addIntervenantCouvert($actual);
        $result = $calculator->calculate($session, [$this->convention($session, 189000)], [$contract]);
        self::assertSame(0, $result['missingTrainers']);
        self::assertSame(99000, $result['remaining']);
        self::assertSame($holder, $contract->getFormateur());
        self::assertSame([$actual], $session->getFormateursEffectifs());
        $contract->setStatus(ContratFormateurStatus::RESILIE);
        self::assertSame(1, $calculator->calculate($session, [], [$contract])['missingTrainers']);
    }

    public function testTwoContractsCoverThreeActualTrainersWithoutDuplicateCosts(): void
    {
        $entity = new Entite();
        $session = (new Session())->setEntite($entity);
        $a = (new Formateur())->setEntite($entity);
        $b = (new Formateur())->setEntite($entity);
        $c = (new Formateur())->setEntite($entity);
        foreach ([$a, $b, $c] as $trainer) $session->addJour((new \App\Entity\SessionJour())->setFormateur($trainer));
        $one = $this->contract($session, $a, 30000)->addIntervenantCouvert($b);
        $two = $this->contract($session, new Formateur(), 20000)->addIntervenantCouvert($c);
        $result = (new SessionFinancialSummary())->calculate($session, [$this->convention($session)], [$one, $two]);
        self::assertSame(0, $result['missingTrainers']);
        self::assertSame(50000, $result['trainerCost']);
        self::assertSame(50000, $result['remaining']);
        self::assertSame([$a, $b, $c], $session->getFormateursEffectifs());
    }

    public function testCoverageCannotCrossOrganisations(): void
    {
        $contract = (new ContratFormateur())->setEntite(new Entite());
        $this->expectException(\InvalidArgumentException::class);
        $contract->addIntervenantCouvert((new Formateur())->setEntite(new Entite()));
    }

    public function testResponsibleTeachingAloneDoesNotRequireContractOrDeduction(): void
    {
        $entity = new Entite();
        $user = (new Utilisateur())->setEntite($entity);
        $trainer = (new Formateur())->setUtilisateur($user)->setSansContratRemuneration(true);
        $session = (new Session())->setEntite($entity)->setFormateur($trainer);
        $calculator = new SessionFinancialSummary();
        $result = $calculator->calculate($session, [], [$this->contract($session, $trainer, 25000)]);
        self::assertTrue($session->isFormationAssureeParResponsable());
        self::assertTrue($result['responsibleOnly']);
        self::assertSame(0, $result['trainerCost']);
        self::assertSame(0, $result['missingTrainers']);
        $trainer->setSansContratRemuneration(false); // Same organisation, but ordinary paid trainer.
        $result = $calculator->calculate($session, [], []);
        self::assertFalse($result['responsibleOnly']);
        self::assertSame(1, $result['missingTrainers']);
    }

    public function testSessionVatOverridesFormationAndSupportsExemption(): void
    {
        $session = (new Session())->setMontantCents(83200);
        self::assertSame(20.0, $session->getTauxTvaEffectif());
        self::assertSame(99840, $session->getTarifTtcCents());
        $session->setFormation((new Formation())->setTauxTva(10));
        self::assertSame(91520, $session->getTarifTtcCents());
        $session->setTauxTva(5.5);
        $result = (new SessionFinancialSummary())->calculate($session, [], []);
        self::assertSame(4576, $result['sessionTax']);
        self::assertSame(87776, $result['sessionTtc']);
        $session->setTauxTva(0);
        self::assertSame(83200, $session->getTarifTtcCents());
        $session->setTauxTva(null);
        self::assertSame(10.0, $session->getTauxTvaEffectif());
    }

    public function testGlobalPriceIsNotMultipliedAndCanReturnToPerParticipant(): void
    {
        $s = (new Session())->setMontantCents(60000)->setMontantGlobalCents(150000)->setTauxTva(20)->setEntite(new Entite());
        foreach (range(1, 3) as $i) $s->addInscription((new Inscription())->setEntite($s->getEntite())->setStagiaire(new Utilisateur()));
        $calculator = new SessionFinancialSummary();
        $result = $calculator->calculate($s, [], []);
        self::assertSame(150000, $result['amounts']['EUR']['ht']);
        self::assertSame(30000, $result['amounts']['EUR']['tax']);
        self::assertSame(180000, $result['sessionTtc']);
        self::assertTrue($result['global']);
        $result = $calculator->calculate($s, [$this->convention($s, 90000)], []);
        self::assertSame(90000, $result['amounts']['EUR']['ht']);
        $s->setMontantGlobalCents(0);
        self::assertSame(0, $calculator->calculate($s, [], [])['amounts']['EUR']['ht']);
        $s->setMontantGlobalCents(null);
        self::assertSame(180000, $calculator->calculate($s, [], [])['amounts']['EUR']['ht']);
    }

    public function testTrainerVatUsesContractSnapshotAndIsRoundedPerContract(): void
    {
        $s = (new Session())->setEntite(new Entite());
        $trainer = (new Formateur())->setAssujettiTva(true)->setTauxTvaParDefaut(10);
        $s->setFormateur($trainer);
        $contract = $this->contract($s, $trainer, 30000)->setAssujettiTva(true)->setTauxTva(20);
        $result = (new SessionFinancialSummary())->calculate($s, [$this->convention($s)], [$contract]);
        self::assertSame(6000, $contract->getMontantTvaCents());
        self::assertSame(36000, $contract->getTotalTtcCents());
        self::assertSame(6000, $result['trainerTax']);
        self::assertSame(36000, $result['trainerTtc']);
        self::assertSame(70000, $result['remaining']);
        $contract->setFraisMissionCents(1001)->setTauxTva(5.5);
        self::assertSame(1705, $contract->getMontantTvaCents());
        $contract->setAssujettiTva(false);
        self::assertSame(0, $contract->getMontantTvaCents());
        self::assertSame(31001, $contract->getTotalTtcCents());
        $contract->setStatus(ContratFormateurStatus::RESILIE);
        self::assertSame(0, (new SessionFinancialSummary())->calculate($s, [], [$contract])['trainerTax']);
    }

    private function convention(Session $s, ?int $ht = 100000): ConventionContrat { return (new ConventionContrat())->setSession($s)->setEntite($s->getEntite())->setMontantHtCents($ht)->setTauxTva(20); }
    private function contract(Session $s, Formateur $f, int $cost): ContratFormateur { return (new ContratFormateur())->setSession($s)->setEntite($s->getEntite())->setFormateur($f)->setMontantPrevuCents($cost); }
    public function testTotalsIncludeAllTrainersAndMissionExpenses(): void {
        $s=(new Session())->setEntite(new Entite());$a=new Formateur();$b=new Formateur();$s->setFormateur($a);
        $result=(new SessionFinancialSummary())->calculate($s,[$this->convention($s)],[$this->contract($s,$a,20000)->setFraisMissionCents(5000),$this->contract($s,$b,30000)]);
        self::assertSame(['EUR'=>['ht'=>100000,'tax'=>20000]],$result['amounts']);self::assertSame(55000,$result['trainerCost']);self::assertSame(45000,$result['remaining']);
    }
    public function testSharedQuoteUsesItsExactTaxOnlyOnce(): void {
        $s=(new Session())->setEntite(new Entite());$quote=(new Devis())->setEntite($s->getEntite())->setMontantHtCents(100000)->setMontantTvaCents(13500)->setDevise('EUR');
        $result=(new SessionFinancialSummary())->calculate($s,[$this->convention($s,null)->setDevis($quote),$this->convention($s,null)->setDevis($quote)],[]);
        self::assertSame(['EUR'=>['ht'=>100000,'tax'=>13500]],$result['amounts']);self::assertNull($result['remaining']);
    }
    public function testMissingTrainerContractDoesNotProduceFalseMargin(): void {
        $s=(new Session())->setEntite(new Entite())->setFormateur(new Formateur());
        $result=(new SessionFinancialSummary())->calculate($s,[$this->convention($s)],[]);
        self::assertSame(1,$result['missingTrainers']);self::assertNull($result['remaining']);
    }
    public function testCancelledContractExcludedAndNegativeRemainingPreserved(): void {
        $s=(new Session())->setEntite(new Entite());$a=new Formateur();$s->setFormateur($a);
        $result=(new SessionFinancialSummary())->calculate($s,[$this->convention($s,10000)],[$this->contract($s,$a,20000),$this->contract($s,$a,50000)->setStatus(ContratFormateurStatus::RESILIE)]);
        self::assertSame(20000,$result['trainerCost']);self::assertSame(-10000,$result['remaining']);
    }
    public function testOtherTenantDocumentsAndForeignCurrencyDoNotCreateEuroMargin(): void {
        $s=(new Session())->setEntite(new Entite());$quote=(new Devis())->setEntite($s->getEntite())->setDevise('USD');
        $result=(new SessionFinancialSummary())->calculate($s,[$this->convention($s)->setDevis($quote),$this->convention($s)->setEntite(new Entite())],[$this->contract($s,new Formateur(),5000)]);
        self::assertSame(['USD'=>['ht'=>100000,'tax'=>20000]],$result['amounts']);self::assertNull($result['remaining']);
    }
    public function testNoConventionIsClearlyEstimatedFromParticipants(): void {
        $s=(new Session())->setEntite(new Entite())->setFormation((new Formation())->setPrixBaseCents(10000)->setTauxTva(20));
        $inscription=(new Inscription())->setEntite($s->getEntite())->setStagiaire(new Utilisateur());$s->addInscription($inscription);
        $result=(new SessionFinancialSummary())->calculate($s,[],[]);
        self::assertTrue($result['estimated']);self::assertSame(['EUR'=>['ht'=>10000,'tax'=>2000]],$result['amounts']);
    }
}
