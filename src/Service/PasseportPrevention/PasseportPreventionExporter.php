<?php

namespace App\Service\PasseportPrevention;

use App\Entity\{Entite, Session, SessionJour, Inscription};
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PasseportPreventionExporter
{
  /**
   * @param Session[] $sessions
   * Retourne le chemin du fichier XLSX généré (temp).
   */
  public function exportAttestationsXlsx(Entite $entite, array $sessions): string
  {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('attestations');

    // Colonnes “pratiques” (alignées sur les champs demandés dans le guide)
    // ⚠️ À ajuster au modèle exact d’import quand l’État publie un fichier type.
    $headers = [
      'type_declaration',          // ATTESTATION
      'intitule_formation',
      'date_debut_session',
      'date_fin_session',
      'modalite',                  // présentiel / distanciel / mixte (à mapper)
      'codes_rome',                // séparés par ;
      'qualification_formateur',   // texte
      'code_certification',        // RNCP/RS/CertifInfo si applicable
      'formacode',                 // sinon
      'nsf',                       // sinon
      'nir_titulaire',             // 13 chiffres (obligatoire)
      'nom_naissance_titulaire',   // obligatoire
      'prenom_titulaire',
      'nom_usage_titulaire',
      'siret_employeur',           // si financé employeur
      'reference_employeur',
      'date_debut_validite',       // souvent = fin session
      'date_fin_validite',         // optionnelle
    ];

    $col = 1;
    foreach ($headers as $h) {
      $sheet->setCellValue([$col++, 1], $h);
    }
    $sheet->freezePane('A2');

    $row = 2;

    foreach ($sessions as $session) {
      [$start, $end] = $this->sessionStartEnd($session);

      $intitule = $session->getFormation()
        ? $session->getFormation()->getTitre()
        : ($session->getFormationIntituleLibre() ?: '');

      // TODO: à mapper depuis Formation (ex: stocker formacode/nsf/rome/certif)
      $codesRome = '';   // ex: "K2302;I1501;..." (3 à 10)
      $formacode = '';   // 5 chiffres
      $nsf = '';         // ex: "311p"
      $codeCertif = '';  // RNCPxxxx / RSxxxx / CertifInfo

      $qualificationFormateur = $session->getFormateur() ? 'Formateur' : '';

      foreach ($session->getInscriptions() as $inscription) {
        if (!$inscription instanceof Inscription) continue;
        $u = $inscription->getStagiaire();

        // ⚠️ À implémenter : ajouter nir + nomNaissance dans Utilisateur
        $nir = ''; // $u->getNir()
        $nomNaissance = ''; // $u->getNomNaissance()

        $prenom = $u?->getPrenom() ?? '';
        $nomUsage = $u?->getNom() ?? '';

        $siretEmployeur = $inscription->getEntreprise()?->getSiret() ?? '';
        $refEmployeur = ''; // si tu as un champ/ méta

        $dateDebValidite = $end ? $end->format('Y-m-d') : '';
        $dateFinValidite = ''; // optionnel

        $values = [
          'ATTESTATION',
          $intitule,
          $start ? $start->format('Y-m-d') : '',
          $end ? $end->format('Y-m-d') : '',
          'presentiel', // à mapper selon ta donnée
          $codesRome,
          $qualificationFormateur,
          $codeCertif,
          $formacode,
          $nsf,
          $nir,
          $nomNaissance,
          $prenom,
          $nomUsage,
          $siretEmployeur,
          $refEmployeur,
          $dateDebValidite,
          $dateFinValidite,
        ];

        $c = 1;
        foreach ($values as $v) {
          $sheet->setCellValue([$c++, $row], (string) $v);
        }
        $row++;
      }
    }

    // auto-size
    for ($i = 1; $i <= count($headers); $i++) {
      $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
      . 'passeport_prevention_' . $entite->getId() . '_' . date('Ymd_His') . '.xlsx';

    (new Xlsx($spreadsheet))->save($tmp);

    return $tmp;
  }

  /** @return array{0:? \DateTimeImmutable, 1:? \DateTimeImmutable} */
  private function sessionStartEnd(Session $session): array
  {
    $start = null;
    $end = null;

    /** @var SessionJour $j */
    foreach ($session->getJours() as $j) {
      $d1 = $j->getDateDebut();
      $d2 = $j->getDateFin();
      if ($d1 && (!$start || $d1 < $start)) $start = $d1;
      if ($d2 && (!$end || $d2 > $end)) $end = $d2;
    }
    return [$start, $end];
  }
}
