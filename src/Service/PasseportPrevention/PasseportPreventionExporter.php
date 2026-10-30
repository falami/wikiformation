<?php

namespace App\Service\PasseportPrevention;

use App\Entity\{Entite, Session};
use PhpOffice\PhpSpreadsheet\Cell\{Coordinate, DataType};
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Preparation workbook, not an official submission or a ready-to-import file. */
final class PasseportPreventionExporter
{
    public function __construct(
        private readonly PasseportPreventionReadiness $readiness,
        private readonly PasseportPreventionCsvValidator $validator,
    ) {}

    /** @param Session[] $sessions Returns a private temporary file, to delete after download. */
    public function exportPreparationXlsx(Entite $entite, array $sessions, string $declarationType, int $category): string
    {
        $headers = PasseportPreventionFormat::headers($declarationType);
        if (!in_array($category, [1, 2, 3, 4], true)) {
            throw new \InvalidArgumentException('Choisissez une catégorie de formation de 1 à 4.');
        }
        if (!$entite->getId()) {
            throw new \InvalidArgumentException('Un organisme enregistré est nécessaire.');
        }
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('WikiFormation')->setTitle('Préparation Passeport de prévention '.$declarationType)->setDescription('Document de travail à compléter. Ce classeur ne vaut pas déclaration dans le service de l’État.');
        $guide = $spreadsheet->getActiveSheet()->setTitle('Guide');
        $data = $spreadsheet->createSheet()->setTitle('Donnees_'.$declarationType);
        $controls = $spreadsheet->createSheet()->setTitle('Controles');
        $this->textRow($data, 1, $headers);
        $this->textRow($controls, 1, ['LIGNE_DONNEES', 'SESSION', 'INSCRIPTION', 'PARTICIPANT_A_REPERER', 'EMPLOYEUR_A_VERIFIER', 'SIRET_A_VERIFIER', 'POINTS_A_COMPLETER_OU_VERIFIER']);
        $rowIndex = 2;
        $seenSessions = [];
        foreach ($sessions as $session) {
            if (!$session instanceof Session || !$session->getId()) {
                throw new \InvalidArgumentException('La sélection contient une session non enregistrée.');
            }
            if (isset($seenSessions[$session->getId()])) continue;
            $seenSessions[$session->getId()] = true;
            $state = $this->readiness->inspect($session, $entite);
            if (!$state['selectable']) {
                throw new \InvalidArgumentException('Une session sélectionnée ne peut pas être préparée : '.$state['reason'].'.');
            }
            $participants = $this->readiness->participants($session, $entite);
            usort($participants, static fn ($left, $right) => $left->getId() <=> $right->getId());
            $title = $session->getFormation()?->getTitre() ?? $session->getFormationIntituleLibre() ?? '';
            $remoteRate = $session->getTauxDistanciel();
            $modality = match (true) {
                $remoteRate === 0.0 => 'PRESENTIEL',
                $remoteRate === 100.0 => 'A_DISTANCE',
                $remoteRate !== null && $remoteRate > 0 && $remoteRate < 100 => 'MIXTE',
                default => '',
            };
            foreach ($participants as $participantIndex => $inscription) {
                if (!$inscription->getId()) {
                    throw new \InvalidArgumentException('Une inscription non enregistrée ne peut pas être exportée.');
                }
                $row = array_fill_keys($headers, '');
                $row['ID_DECLARATION'] = 'WF-E'.$entite->getId().'-S'.$session->getId().'-'.$declarationType.'-L'.(intdiv($participantIndex, 500) + 1);
                $row['REFERENCE_DECLARATION'] = $session->getCode() ?: $row['ID_DECLARATION'];
                $row['ID_UNIQUE_PARTENAIRE'] = 'WF-E'.$entite->getId().'-S'.$session->getId().'-I'.$inscription->getId().'-'.$declarationType;
                $row['NOM_FORMATION'] = $title;
                $row['DATE_DEBUT_FORMATION'] = \DateTimeImmutable::createFromInterface($state['start'])->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
                $row['DATE_FIN_FORMATION'] = \DateTimeImmutable::createFromInterface($state['end'])->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
                $row['MODALITE_DISPENSE'] = $modality;
                if ($declarationType === 'JDR') $row['PRESENCE_FORMATION'] = 'OUI';
                // No birth-name, NIR, issue date, certification, attendance or success inferred from a registration.
                $this->textRow($data, $rowIndex, array_values($row));
                foreach ($headers as $column => $field) {
                    if ($row[$field] === '') {
                        $data->getStyle([$column + 1, $rowIndex])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF7E5');
                    }
                }
                $participant = $inscription->getStagiaire();
                $company = $inscription->getEntreprise();
                $companyOutsideScope = $company !== null && $company->getEntite()?->getId() !== $entite->getId();
                if ($companyOutsideScope) $company = null;
                $issues = $this->validator->validateRow($row, $declarationType);
                if ($companyOutsideScope) $issues[] = 'Vérifier le rattachement de l’entreprise de prise en charge avant de renseigner les informations employeur.';
                $issues[] = $declarationType === 'ADF' ? 'Vérifier la participation assidue et totale et la délivrance effective de l’attestation.' : 'Vérifier le justificatif de réussite effectivement délivré à ce titulaire.';
                $issues[] = 'Confirmer le périmètre SST et la catégorie '.$category.' ; une inscription ne prouve ni l’assiduité ni la réussite.';
                $this->textRow($controls, $rowIndex, [(string) $rowIndex, $session->getCode() ?? '', (string) $inscription->getId(), trim(($participant?->getPrenom() ?? '').' '.($participant?->getNom() ?? '')), $company?->getRaisonSociale() ?? '', $company?->getSiret() ?? '', implode("\n", $issues)]);
                ++$rowIndex;
            }
        }
        if ($rowIndex === 2) throw new \InvalidArgumentException('Aucune inscription admissible dans la sélection.');
        $instructions = [
            ['PASSEPORT DE PRÉVENTION', 'CLASSEUR DE PRÉPARATION — NON DÉPOSÉ'],
            ['Type de déclaration', $declarationType === 'ADF' ? 'Attestation de formation (ADF)' : 'Justificatif de réussite (JDR)'],
            ['Catégorie sélectionnée', (string) $category.' — qualification à vérifier pour chaque formation'],
            ['Lignes préparées', (string) ($rowIndex - 2)],
            ['1. Vérifier le périmètre', 'Conservez uniquement les formations SST déclarables et les titulaires concernés. Pour ADF : participation assidue et totale attestée. Pour JDR : réussite justifiée.'],
            ['2. Compléter les données', 'Ouvrez Controles puis Donnees_'.$declarationType.'. Les cellules jaunes non renseignées ne sont pas toutes obligatoires : appliquez les règles ci-dessous et le guide officiel. Aucun NIR ni nom de naissance n’est déduit du nom usuel.'],
            ['Identité du titulaire', 'NIR : 13 caractères sans la clé. NOM_TITULAIRE : nom de naissance officiel, sans troncature. Conservez ces colonnes en texte. La correspondance d’identité est contrôlée uniquement par le portail officiel.'],
            ['Compétences transférables', '3 à 10 codes de compétences ou savoir-faire du ROME, séparés par /. Il ne s’agit pas des codes métiers de type K2302.'],
            ['Référentiels de formation', 'FORMATION_CERTIFIANTE : OUI ou NON. OUI : CERTIFICATION_VISEE = un code RS ; domaine et spécialité vides. NON : certification vide, 1 à 5 Formacodes et 1 à 3 codes NSF, séparés par /.'],
            ['Convention employeur', 'PRESENCE_EMPLOYEUR : OUI si une convention de formation est signée avec l’employeur du titulaire, sinon NON. En cas de OUI : SIRET obligatoire. En cas de NON : SIRET et REFERENCE_EMPLOYEUR vides. L’entreprise de prise en charge n’est qu’une aide dans Controles.'],
            ['Validité du document', 'DATE_DEBUT_VALIDITE est obligatoire et ne peut pas précéder la fin de la formation. Saisissez la date réelle du document. DATE_FIN_VALIDITE est facultative et ne peut pas précéder le début de validité.'],
            ['Règles JDR', 'TYPE_JDR : DIPLOME, TITRE, HABILITATION ou CERTIFICAT ; NOM_JDR obligatoire. MODE_OBTENTION facultatif : PAR_ADMISSION ou PAR_SCORING. Ne déclarez que les justificatifs effectivement délivrés.'],
            ['3. Construire le CSV', 'Copiez uniquement la feuille Donnees_'.$declarationType.' dans un fichier CSV UTF-8 séparé par |. Préservez exactement les en-têtes et leur ordre. Toutes les dates : jj/mm/aaaa. Ne renommez pas simplement le fichier XLSX en CSV.'],
            ['Limites du portail', 'Un seul type ADF ou JDR par fichier, maximum 500 titulaires par ID_DECLARATION, 200 Mo par fichier. Nom de fichier : au plus 200 caractères, sans @ < > : guillemets / antislash | ? * %.'],
            ['Identifiants stables', 'ID_UNIQUE_PARTENAIRE identifie une inscription et un type de justificatif. Conservez-le lors des corrections ; vérifiez les imports antérieurs pour éviter de redéclarer les mêmes titulaires. Le contrôle local ne connaît pas vos dépôts précédents.'],
            ['4. Contrôler puis déposer', 'Utilisez le contrôle CSV de WikiFormation pour les erreurs de structure, puis déposez le CSV sur le portail officiel et consultez son bilan. Aucun fichier ni aucune donnée n’est transmis automatiquement à l’État.'],
            ['Contrôles officiels restants', 'La validation locale ne vérifie pas l’existence des codes de référentiels et des SIRET, la correspondance NIR/nom, les dépôts antérieurs, la preuve de présence/réussite ou la conformité juridique de la déclaration.'],
            ['Confidentialité', 'Ce classeur contient des données personnelles. Complétez le NIR dans un espace de travail sécurisé, limitez les accès et supprimez les copies de travail après usage selon votre politique de conservation.'],
            ['Référence technique', PasseportPreventionFormat::VERSION],
            ['Guide officiel OF', PasseportPreventionFormat::guideUrl($declarationType)],
            ['Trame CSV officielle', PasseportPreventionFormat::templateUrl($declarationType)],
            ['Portail d’information', PasseportPreventionFormat::SOURCE],
        ];
        foreach ($instructions as $i => $instruction) $this->textRow($guide, $i + 1, $instruction);
        $this->styleTable($data, count($headers), $rowIndex - 1);
        $this->styleTable($controls, 7, $rowIndex - 1);
        $this->styleTable($guide, 2, count($instructions));
        $guide->getColumnDimension('A')->setWidth(32);
        $guide->getColumnDimension('B')->setWidth(120);
        $guide->setAutoFilter('');
        $controls->getColumnDimension('D')->setWidth(32);
        $controls->getColumnDimension('G')->setWidth(115);
        $spreadsheet->setActiveSheetIndex(0);
        $temporary = tempnam(sys_get_temp_dir(), 'wf_pp_');
        if ($temporary === false) throw new \RuntimeException('Le fichier de préparation ne peut pas être créé.');
        chmod($temporary, 0600);
        try {
            (new Xlsx($spreadsheet))->save($temporary);
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        return $temporary;
    }

    /** All user-provided values are explicit text, never executable spreadsheet formulae. */
    private function textRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $column => $value) {
            $sheet->setCellValueExplicit([$column + 1, $row], (string) $value, DataType::TYPE_STRING);
        }
    }

    private function styleTable(Worksheet $sheet, int $columns, int $lastRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columns);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF203748');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getFont()->setName('Aptos')->setSize(11);
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setVertical('top')->setWrapText(true);
        for ($column = 1; $column <= $columns; ++$column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(28);
        }
    }
}
