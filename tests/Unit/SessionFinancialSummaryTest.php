<?php
namespace App\Tests\Unit;
use App\Entity\{Session, Entite, ConventionContrat, ContratFormateur, Formateur, Devis, Formation, Inscription, Utilisateur};
use App\Enum\ContratFormateurStatus;
use App\Service\Session\SessionFinancialSummary;
use PHPUnit\Framework\TestCase;
final class SessionFinancialSummaryTest extends TestCase
{
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
