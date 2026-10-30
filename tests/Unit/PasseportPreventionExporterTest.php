<?php

namespace App\Tests\Unit;

use App\Entity\{Entite, Entreprise, Inscription, Session, SessionJour, Utilisateur};
use App\Enum\{StatusInscription, StatusSession};
use App\Service\PasseportPrevention\{PasseportPreventionCsvValidator, PasseportPreventionExporter, PasseportPreventionFormat, PasseportPreventionReadiness};
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class PasseportPreventionExporterTest extends TestCase
{
    public function testPreparationKeepsOfficialColumnsAndUnknownDataEmpty(): void
    {
        [$entite, $session] = $this->fixture();
        $filename = $this->exporter()->exportPreparationXlsx($entite, [$session], 'ADF', 1);
        try {
            self::assertSame(0600, fileperms($filename) & 0777);
            $book = IOFactory::load($filename);
            self::assertSame(['Guide', 'Donnees_ADF', 'Controles'], $book->getSheetNames());
            $rows = $book->getSheetByName('Donnees_ADF')->toArray();
            self::assertSame(PasseportPreventionFormat::ADF_HEADERS, $rows[0]);
            $data = array_combine($rows[0], $rows[1]);
            self::assertSame('WF-E1-S10-I100-ADF', $data['ID_UNIQUE_PARTENAIRE']);
            self::assertSame('04/01/2020', $data['DATE_DEBUT_FORMATION']);
            foreach (['NIR', 'NOM_TITULAIRE', 'DATE_DEBUT_VALIDITE', 'QUALIFICATION_FORMATEUR', 'PRESENCE_EMPLOYEUR', 'FORMATION_CERTIFIANTE'] as $field) self::assertEmpty($data[$field]);
            self::assertSame('MIXTE', $data['MODALITE_DISPENSE']);
            self::assertSame('s', $book->getSheetByName('Donnees_ADF')->getCell('D2')->getDataType());
            self::assertSame('=HYPERLINK("https://example.test")', $book->getSheetByName('Donnees_ADF')->getCell('D2')->getValue());
            self::assertSame('s', $book->getSheetByName('Controles')->getCell('D2')->getDataType());
            self::assertStringContainsString('NON DÉPOSÉ', $book->getSheetByName('Guide')->getCell('B1')->getValue());
            $book->disconnectWorksheets();
        } finally { unlink($filename); }
    }

    public function testJdrPreparationDoesNotInferSuccessOrDocumentType(): void
    {
        [$entite, $session] = $this->fixture();
        $filename = $this->exporter()->exportPreparationXlsx($entite, [$session], 'JDR', 2);
        try {
            $book = IOFactory::load($filename);
            $rows = $book->getSheetByName('Donnees_JDR')->toArray();
            self::assertSame(PasseportPreventionFormat::JDR_HEADERS, $rows[0]);
            $data = array_combine($rows[0], $rows[1]);
            self::assertSame('OUI', $data['PRESENCE_FORMATION']);
            foreach (['TYPE_JDR', 'NOM_JDR', 'RESULTAT_OBTENU', 'MODE_OBTENTION', 'IDENTIFIANT_PREUVE'] as $field) self::assertEmpty($data[$field]);
            $book->disconnectWorksheets();
        } finally { unlink($filename); }
    }

    public function testExcludedRegistrationsDoNotLeakIntoWorkbook(): void
    {
        [$entite, $session] = $this->fixture();
        foreach ([StatusInscription::ABSENT, StatusInscription::ANNULE, StatusInscription::PREINSCRIT] as $i => $status) {
            $registration = (new Inscription())->setEntite($entite)->setStagiaire(new Utilisateur())->setStatus($status);
            $this->id($registration, $i + 101); $session->addInscription($registration);
        }
        $foreign = new Entite(); $this->id($foreign, 2);
        $registration = (new Inscription())->setEntite($foreign)->setStagiaire(new Utilisateur())->setStatus(StatusInscription::CONFIRME);
        $this->id($registration, 105); $session->addInscription($registration);
        $filename = $this->exporter()->exportPreparationXlsx($entite, [$session, $session], 'ADF', 1);
        try {
            $book = IOFactory::load($filename);
            self::assertSame(2, $book->getSheetByName('Donnees_ADF')->getHighestDataRow());
            $book->disconnectWorksheets();
        } finally { unlink($filename); }
    }

    public function testForeignSessionIsRejected(): void
    {
        [$entite, $session] = $this->fixture();
        $foreign = new Entite(); $this->id($foreign, 2); $session->setEntite($foreign);
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter()->exportPreparationXlsx($entite, [$session], 'ADF', 1);
    }

    public function testCompanyFromAnotherTenantIsNotDisclosed(): void
    {
        [$entite, $session] = $this->fixture();
        $foreign = new Entite(); $this->id($foreign, 2);
        $company = (new Entreprise())->setEntite($foreign)->setRaisonSociale('PRIVATE-FOREIGN-COMPANY')->setSiret('12345678901234');
        $session->getInscriptions()->first()->setEntreprise($company);
        $filename = $this->exporter()->exportPreparationXlsx($entite, [$session], 'ADF', 1);
        try {
            $book = IOFactory::load($filename);
            self::assertEmpty($book->getSheetByName('Controles')->getCell('E2')->getValue());
            self::assertEmpty($book->getSheetByName('Controles')->getCell('F2')->getValue());
            foreach ($book->getAllSheets() as $sheet) {
                $contents = json_encode($sheet->toArray());
                self::assertStringNotContainsString('PRIVATE-FOREIGN-COMPANY', $contents);
                self::assertStringNotContainsString('12345678901234', $contents);
            }
            $book->disconnectWorksheets();
        } finally { unlink($filename); }
    }

    public function testCompanyInSameTenantRemainsAvailableAsUnverifiedHelp(): void
    {
        [$entite, $session] = $this->fixture();
        $company = (new Entreprise())->setEntite($entite)->setRaisonSociale('LOCAL-COMPANY')->setSiret('01234567890123');
        $session->getInscriptions()->first()->setEntreprise($company);
        $filename = $this->exporter()->exportPreparationXlsx($entite, [$session], 'ADF', 1);
        try {
            $book = IOFactory::load($filename);
            self::assertSame('LOCAL-COMPANY', $book->getSheetByName('Controles')->getCell('E2')->getValue());
            self::assertSame('01234567890123', $book->getSheetByName('Controles')->getCell('F2')->getValue());
            self::assertSame('s', $book->getSheetByName('Controles')->getCell('F2')->getDataType());
            $book->disconnectWorksheets();
        } finally { unlink($filename); }
    }

    public function testDraftIsRejected(): void
    {
        [$entite, $session] = $this->fixture(); $session->setStatus(StatusSession::DRAFT);
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter()->exportPreparationXlsx($entite, [$session], 'ADF', 1);
    }

    private function fixture(): array
    {
        $entite = new Entite(); $this->id($entite, 1);
        $session = (new Session())->setEntite($entite)->setStatus(StatusSession::DONE)->setCode('TEST-SESSION')->setFormationIntituleLibre('=HYPERLINK("https://example.test")')->setTauxDistanciel(50);
        $this->id($session, 10);
        $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2020-01-04 08:30'))->setDateFin(new \DateTimeImmutable('2020-01-04 17:00')));
        $user = (new Utilisateur())->setPrenom('Test')->setNom('Nom usuel');
        $registration = (new Inscription())->setEntite($entite)->setStagiaire($user)->setStatus(StatusInscription::TERMINE);
        $this->id($registration, 100); $session->addInscription($registration);
        return [$entite, $session];
    }

    private function id(object $object, int $id): void
    {
        (new \ReflectionProperty($object::class, 'id'))->setValue($object, $id);
    }

    private function exporter(): PasseportPreventionExporter
    {
        return new PasseportPreventionExporter(new PasseportPreventionReadiness(), new PasseportPreventionCsvValidator());
    }
}
