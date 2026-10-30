<?php

namespace App\Tests\Unit;

use App\Service\PasseportPrevention\{PasseportPreventionCsvValidator, PasseportPreventionFormat};
use PHPUnit\Framework\TestCase;

final class PasseportPreventionCsvValidatorTest extends TestCase
{
    public function testFilenameRulesAndPersonalDataAreNotEchoed(): void
    {
        $validator = new PasseportPreventionCsvValidator();
        self::assertSame([], $validator->validateFilename('Déclarations SST 2026-10.csv'));
        self::assertSame([], $validator->validateFilename(str_repeat('é', 196).'.CSV'));
        self::assertStringContainsString('200 caractères', implode(' ', $validator->validateFilename(str_repeat('é', 197).'.csv')));
        foreach (['@', '<', '>', ':', '"', '“', '”', '/', '\\', '|', '?', '*', '%', "\0", "\n"] as $character) {
            $errors = implode(' ', $validator->validateFilename('CONFIDENTIEL'.$character.'test.csv'));
            self::assertStringContainsString('caractère interdit', $errors);
            self::assertStringNotContainsString('CONFIDENTIEL', $errors);
        }
        foreach (['', '.csv', 'rapport.xlsx', 'rapport.csv ', "test\xFF.csv"] as $filename) {
            self::assertNotEmpty($validator->validateFilename($filename));
        }
    }

    public function testOfficialAdfTemplateAndValidRow(): void
    {
        $expected = 'ID_DECLARATION|REFERENCE_DECLARATION|ID_UNIQUE_PARTENAIRE|NOM_FORMATION|DATE_DEBUT_FORMATION|DATE_FIN_FORMATION|MODALITE_DISPENSE|COMPETENCE_TRANSFERABLE|QUALIFICATION_FORMATEUR|FORMATION_CERTIFIANTE|CERTIFICATION_VISEE|DOMAINE_FORMATION|SPECIALITE_FORMATION|NIR|NOM_TITULAIRE|PRESENCE_EMPLOYEUR|SIRET_EMPLOYEUR|REFERENCE_EMPLOYEUR|DATE_DEBUT_VALIDITE|DATE_FIN_VALIDITE';
        self::assertSame($expected, implode('|', PasseportPreventionFormat::headers('ADF')));
        self::assertSame(['errors' => [], 'rows' => 1], $this->validate($this->csv([$this->row()])));
    }

    public function testOfficialJdrTemplateAndSuccessWithoutTrainingDetails(): void
    {
        $expected = 'ID_DECLARATION|REFERENCE_DECLARATION|ID_UNIQUE_PARTENAIRE|TYPE_JDR|NOM_JDR|NOM_OPTION_SPECIALITE|MODE_OBTENTION|COMPETENCE_TRANSFERABLE|PRESENCE_FORMATION|NOM_FORMATION|DATE_DEBUT_FORMATION|DATE_FIN_FORMATION|MODALITE_DISPENSE|QUALIFICATION_FORMATEUR|FORMATION_CERTIFIANTE|CERTIFICATION_VISEE|DOMAINE_FORMATION|SPECIALITE_FORMATION|NIR|NOM_TITULAIRE|PRESENCE_EMPLOYEUR|SIRET_EMPLOYEUR|REFERENCE_EMPLOYEUR|DATE_DEBUT_VALIDITE|DATE_FIN_VALIDITE|RESULTAT_OBTENU|MENTION_OBTENUE|LIEN_PREUVE|IDENTIFIANT_PREUVE';
        self::assertSame($expected, implode('|', PasseportPreventionFormat::headers('JDR')));
        $row = array_fill_keys(PasseportPreventionFormat::headers('JDR'), '') + $this->row();
        foreach ($this->row() as $field => $value) $row[$field] = $value;
        foreach (PasseportPreventionFormat::FORMATION_FIELDS as $field) $row[$field] = '';
        $row['TYPE_JDR'] = 'CERTIFICAT'; $row['NOM_JDR'] = 'Justificatif test';
        self::assertSame(['errors' => [], 'rows' => 1], $this->validate($this->csv([$row], 'JDR'), 'JDR'));
        $row['NOM_FORMATION'] = 'Information non autorisée sans présence de formation';
        self::assertStringContainsString('NOM_FORMATION doit rester vide', implode(' ', $this->validate($this->csv([$row], 'JDR'), 'JDR')['errors']));
    }

    public function testWrongDelimiterAndEmptyFileAreRejected(): void
    {
        self::assertNotEmpty($this->validate(str_replace('|', ';', $this->csv([$this->row()])))['errors']);
        self::assertSame(0, $this->validate($this->csv([]))['rows']);
        self::assertNotEmpty($this->validate($this->csv([]))['errors']);
    }

    public function testConditionalReferenceFieldsMustBeConsistent(): void
    {
        $row = $this->row();
        $row['FORMATION_CERTIFIANTE'] = 'OUI';
        $row['PRESENCE_EMPLOYEUR'] = 'OUI';
        $errors = implode(' ', $this->validate($this->csv([$row]))['errors']);
        foreach (['CERTIFICATION_VISEE est obligatoire', 'SIRET_EMPLOYEUR est obligatoire', 'DOMAINE_FORMATION doit rester vide', 'SPECIALITE_FORMATION doit rester vide'] as $expected) self::assertStringContainsString($expected, $errors);
        $row['CERTIFICATION_VISEE'] = 'RS6550'; $row['DOMAINE_FORMATION'] = $row['SPECIALITE_FORMATION'] = '';
        $row['SIRET_EMPLOYEUR'] = '00000000000000';
        // Structural checks deliberately do not pretend to verify existence in official registers.
        self::assertSame([], $this->validate($this->csv([$row]))['errors']);
    }

    public function testDatesFutureInvalidAndValidityOrdering(): void
    {
        $row = $this->row();
        $row['DATE_DEBUT_FORMATION'] = '31/02/2026';
        $row['DATE_FIN_FORMATION'] = '01/11/2026';
        $row['DATE_DEBUT_VALIDITE'] = '01/01/2026';
        $row['DATE_FIN_VALIDITE'] = '31/12/2025';
        $errors = implode(' ', $this->validate($this->csv([$row]))['errors']);
        foreach (['date invalide', 'futur', 'DATE_DEBUT_VALIDITE ne peut pas précéder', 'DATE_FIN_VALIDITE ne peut pas précéder'] as $expected) self::assertStringContainsString($expected, $errors);
    }

    public function testDuplicateIdentifiersAndInconsistentGroupAreRejected(): void
    {
        $a = $this->row(); $b = $a;
        $b['NOM_FORMATION'] = 'Autre formation';
        $errors = implode(' ', $this->validate($this->csv([$a, $b]))['errors']);
        self::assertStringContainsString('ID_UNIQUE_PARTENAIRE déjà utilisé', $errors);
        self::assertStringContainsString('informations communes diffèrent', $errors);
        self::assertStringContainsString('titulaire déjà présent', $errors);
    }

    public function testFiveHundredTraineesLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; ++$i) {
            $row = $this->row(); $row['ID_UNIQUE_PARTENAIRE'] = 'ID-'.$i;
            $row['NIR'] = '123456'.str_pad((string) $i, 7, '0', STR_PAD_LEFT); $rows[] = $row;
        }
        self::assertStringContainsString('500 titulaires', implode(' ', $this->validate($this->csv($rows))['errors']));
    }

    public function testErrorsDoNotExposePersonalDataAndRejectFormulaOrControlCharacters(): void
    {
        $row = $this->row();
        $row['NIR'] = '112233445566778';
        $row['NOM_TITULAIRE'] = str_repeat('PRIVATE-NAME', 5);
        $row['NOM_FORMATION'] = '=HYPERLINK("https://example.test")';
        $row['REFERENCE_EMPLOYEUR'] = "private@example.test\nInjected";
        $errors = implode(' ', $this->validate($this->csv([$row]))['errors']);
        foreach ([$row['NIR'], 'PRIVATE-NAME', 'private@example.test', 'HYPERLINK'] as $secret) self::assertStringNotContainsString($secret, $errors);
        self::assertStringContainsString('formule tableur', $errors);
        self::assertStringContainsString('caractère de contrôle', $errors);
    }

    public function testBomAcceptedAndSizeEncodingLimits(): void
    {
        self::assertSame([], $this->validate("\xEF\xBB\xBF".$this->csv([$this->row()]))['errors']);
        self::assertSame(0, $this->validate(str_repeat('a', PasseportPreventionCsvValidator::MAX_BYTES + 1))['rows']);
        self::assertStringContainsString('UTF-8', implode(' ', $this->validate("\xFF")['errors']));
    }

    private function row(): array
    {
        return array_replace(array_fill_keys(PasseportPreventionFormat::headers('ADF'), ''), [
            'ID_DECLARATION' => 'TEST-SESSION-1', 'REFERENCE_DECLARATION' => 'TEST-REF', 'ID_UNIQUE_PARTENAIRE' => 'TEST-INSCRIPTION-1', 'NOM_FORMATION' => 'Formation SST de test', 'DATE_DEBUT_FORMATION' => '05/10/2026', 'DATE_FIN_FORMATION' => '06/10/2026', 'COMPETENCE_TRANSFERABLE' => '115650/121885/400635', 'FORMATION_CERTIFIANTE' => 'NON', 'DOMAINE_FORMATION' => '42829', 'SPECIALITE_FORMATION' => '344r', 'NIR' => '1234567890123', 'NOM_TITULAIRE' => 'TEST', 'PRESENCE_EMPLOYEUR' => 'NON', 'DATE_DEBUT_VALIDITE' => '06/10/2026',
        ]);
    }

    private function csv(array $rows, string $type = 'ADF'): string
    {
        $stream = fopen('php://memory', 'r+');
        $headers = PasseportPreventionFormat::headers($type);
        fputcsv($stream, $headers, '|', '"', '');
        foreach ($rows as $row) fputcsv($stream, array_map(static fn ($field) => $row[$field], $headers), '|', '"', '');
        rewind($stream); $content = stream_get_contents($stream); fclose($stream);
        return $content;
    }

    private function validate(string $content, string $type = 'ADF'): array
    {
        return (new PasseportPreventionCsvValidator())->validateCsv($content, $type, new \DateTimeImmutable('2026-10-30'));
    }
}
