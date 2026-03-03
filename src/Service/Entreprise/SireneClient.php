<?php

namespace App\Service\Entreprise;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SireneClient
{
  private const TOKEN_URL = 'https://api.insee.fr/token';
  private const API_BASE  = 'https://api.insee.fr/entreprises/sirene/V3';

  public function __construct(
    private HttpClientInterface $http,
    private CacheInterface $cache,
    private string $clientId,
    private string $clientSecret,
  ) {}

  private function getAccessToken(): string
  {
    return $this->cache->get('insee_sirene_access_token', function (ItemInterface $item) {
      $item->expiresAfter(3300);

      $res = $this->http->request('POST', self::TOKEN_URL, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
          'Content-Type'  => 'application/x-www-form-urlencoded',
          'Accept'        => 'application/json',
        ],
        'body' => 'grant_type=client_credentials',
      ]);

      $status = $res->getStatusCode();

      // VÉRIFICATION AVANT LE TOARRAY
      if ($status !== 200) {
        throw new \RuntimeException(sprintf('L\'API INSEE est actuellement indisponible (Erreur HTTP %d). Veuillez réessayer plus tard.', $status));
      }

      try {
        $data = $res->toArray(); // Ne pas mettre false ici pour laisser Symfony lever une erreur propre si c'est pas du JSON
      } catch (\Exception $e) {
        throw new \RuntimeException("Réponse invalide de l'INSEE (Maintenance en cours).");
      }

      if (empty($data['access_token'])) {
        throw new \RuntimeException('Jeton d\'accès absent de la réponse INSEE.');
      }

      return (string) $data['access_token'];
    });
  }

  private function authHeaders(): array
  {
    return [
      'Authorization' => 'Bearer ' . $this->getAccessToken(),
      'Accept'        => 'application/json',
    ];
  }

  public function searchEtablissement(string $raisonSociale, ?string $cp = null, ?string $ville = null): ?array
  {
    $name = str_replace('"', ' ', trim($raisonSociale));

    $qParts = [];
    $qParts[] = sprintf(
      '(uniteLegale.denominationUniteLegale:"%s" OR uniteLegale.denominationUsuelle1UniteLegale:"%s")',
      $name,
      $name
    );

    if ($cp) {
      $cp = preg_replace('/\D/', '', $cp);
      if ($cp !== '') $qParts[] = 'codePostalEtablissement:' . $cp;
    }

    if ($ville) {
      $v = str_replace('"', ' ', trim($ville));
      if ($v !== '') $qParts[] = sprintf('libelleCommuneEtablissement:"%s"', $v);
    }

    $q = implode(' AND ', $qParts);

    $res = $this->http->request('GET', self::API_BASE . '/siret', [
      'headers' => $this->authHeaders(),
      'query' => [
        'q' => $q,
        'nombre' => 5,
      ],
    ]);

    if ($res->getStatusCode() !== 200) {
      throw new \RuntimeException("INSEE search error HTTP {$res->getStatusCode()} | " . $res->getContent(false));
    }

    $data = $res->toArray(false);
    $etabs = $data['etablissements'] ?? null;

    return (is_array($etabs) && count($etabs) > 0) ? $etabs[0] : null;
  }

  public function getUniteLegale(string $siren): ?array
  {
    $siren = preg_replace('/\D/', '', $siren);

    $res = $this->http->request('GET', self::API_BASE . '/siren/' . $siren, [
      'headers' => $this->authHeaders(),
    ]);

    return $res->getStatusCode() === 200 ? $res->toArray(false) : null;
  }
}
