<?php

namespace App\Service\Entreprise;

final class EntrepriseResolver
{
  public function __construct(private SireneClient $sirene) {}

  /**
   * @return array{success:bool, siren?:string, siret?:string, tva?:string, confidence?:float, message?:string}
   */
  public function resolve(string $raisonSociale, ?string $cp = null, ?string $ville = null): array
  {
    $q = trim($raisonSociale);
    if ($q === '') {
      return ['success' => false, 'message' => 'Nom requis.'];
    }

    try {
      $best = $this->sirene->searchEtablissement($q, $cp, $ville);
    } catch (\Throwable $e) {
      return ['success' => false, 'message' => 'INSEE indisponible: ' . $e->getMessage()];
    }

    if (!$best) {
      return ['success' => false, 'message' => 'Aucun résultat INSEE.'];
    }

    // Ex: $best['siren'] / $best['siret'] selon structure INSEE
    $siret = (string)($best['siret'] ?? '');
    $siren = (string)($best['siren'] ?? '');

    // parfois le siren est dans uniteLegale
    if ($siren === '' && isset($best['uniteLegale']['siren'])) {
      $siren = (string)$best['uniteLegale']['siren'];
    }

    if ($siren === '' && $siret !== '' && strlen(preg_replace('/\D/', '', $siret)) >= 9) {
      $siren = substr(preg_replace('/\D/', '', $siret), 0, 9);
    }

    if ($siren === '') {
      return ['success' => false, 'message' => 'Résultat sans SIREN.'];
    }

    $tva = $this->computeTvaFromSiren($siren);

    return [
      'success' => true,
      'siren' => $siren,
      'siret' => $siret !== '' ? $siret : null,
      'tva' => $tva,
      'confidence' => 0.85,
    ];
  }

  private function computeTvaFromSiren(string $siren): ?string
  {
    $siren = preg_replace('/\D/', '', $siren);
    if (strlen($siren) !== 9) return null;

    $mod = intval($siren) % 97;
    $key = (12 + 3 * $mod) % 97;

    return 'FR' . str_pad((string)$key, 2, '0', STR_PAD_LEFT) . $siren;
  }
}
