<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stateless transport; callers own tenant authorization, token rotation and durable outbox state.
 * Contract: https://api.superpdp.tech/openapi/superpdp.json (1.36.0.beta, checked 2026-10-08).
 * Bind a dedicated, untraced, non-retrying HTTP transport. No real request runs at construction.
 */
final class SuperPdpClient
{
    public const BASE_URL = 'https://api.superpdp.tech';
    private const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        if ($httpClient instanceof RetryableHttpClient || $httpClient instanceof TraceableHttpClient) {
            throw new \LogicException('SuperPDP nécessite un transport dédié sans reprise automatique ni collecte des secrets.');
        }
    }

    public function authorizationUrl(
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        ?string $companyNumber = null,
        string $companyScheme = 'fr_siren',
        ?string $loginHint = null,
    ): string {
        $this->credential($clientId);
        $this->redirectUri($redirectUri);
        if (strlen($state) < 32 || strlen($state) > 512 || !preg_match('/^[A-Za-z0-9._~-]+$/D', $state)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $codeChallenge)) {
            throw new \InvalidArgumentException('État OAuth ou challenge PKCE invalide.');
        }
        $query = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri,
            'state' => $state, 'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256'];
        if ($companyNumber !== null) {
            if (!in_array($companyScheme, ['sandbox', 'fr_siren', 'be_numero_entreprise'], true)
                || trim($companyNumber) === '' || strlen($companyNumber) > 64) {
                throw new \InvalidArgumentException('Identifiant de l’organisme invalide.');
            }
            $query['superpdp_company_number'] = $companyNumber;
            $query['superpdp_company_number_scheme'] = $companyScheme;
        }
        if ($loginHint !== null && $loginHint !== '') {
            if (!filter_var($loginHint, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Adresse de connexion invalide.');
            $query['login_hint'] = $loginHint;
        }

        return self::BASE_URL.'/oauth2/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $clientId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, string $redirectUri, #[\SensitiveParameter] string $verifier): array
    {
        $this->redirectUri($redirectUri);
        $this->credential($code);
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) throw new \InvalidArgumentException('Vérificateur PKCE invalide.');

        return $this->token($clientId, $secret, ['grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $redirectUri, 'code_verifier' => $verifier]);
    }

    /** Persist the returned refresh_token atomically: SuperPDP rotates it on every use. */
    public function refreshToken(string $clientId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $refreshToken): array
    {
        $this->credential($refreshToken);

        return $this->token($clientId, $secret, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function session(#[\SensitiveParameter] string $token): array
    {
        $data = $this->json('GET', '/v1.beta/oauth2_sessions/me', $token);
        if (!is_string($data['company_verification_status'] ?? null)) $this->invalidResponse();

        return $data;
    }

    /** Same API host for both environments; inspect env, number_scheme and number before use. */
    public function company(#[\SensitiveParameter] string $token): array
    {
        $data = $this->json('GET', '/v1.beta/companies/me', $token);
        if (!$this->validId($data['id'] ?? null) || !in_array($data['env'] ?? null, ['sandbox', 'production'], true)
            || !is_string($data['number'] ?? null) || !is_string($data['number_scheme'] ?? null)) $this->invalidResponse();

        return $data;
    }

    /** Public French directory, not /directory_entries (which lists our own addresses). */
    public function directoryEntries(string $siren): array
    {
        if (!preg_match('/^\d{9}$/D', $siren)) throw new \InvalidArgumentException('SIREN destinataire invalide.');
        $data = $this->json('GET', '/v1.beta/french_directory/entries', null, ['query' => ['number' => $siren]]);
        if (!is_array($data['data'] ?? null) || !array_is_list($data['data'])) $this->invalidResponse();
        foreach ($data['data'] as $entry) {
            if (!is_array($entry) || !is_string($entry['identifier'] ?? null)
                || !is_bool($entry['is_active'] ?? null) || !is_string($entry['company']['number'] ?? null)) $this->invalidResponse();
        }
        return $data;
    }

    /** A 200 response means queued, not fiscally accepted. external_id is NOT an idempotency key. */
    public function sendInvoice(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $xml, string $externalId, string $processingRule = 'B2B'): array
    {
        $this->xml($xml);
        // The published query schema still caps at 36 despite the newer response schema allowing 64.
        if (!preg_match('/^[A-Za-z0-9._:-]{1,36}$/D', $externalId)
            || !in_array($processingRule, ['B2B', 'B2C', 'B2BInt'], true)) {
            throw new \InvalidArgumentException('Référence externe ou règle de traitement invalide.');
        }
        $data = $this->json('POST', '/v1.beta/invoices', $token, [
            'query' => ['external_id' => $externalId, 'processing_rule' => $processingRule],
            'headers' => ['Content-Type' => 'application/xml'], 'body' => $xml,
        ], true);
        if (!$this->validId($data['id'] ?? null)) $this->invalidResponse(true);

        return $data;
    }

    public function invoice(#[\SensitiveParameter] string $token, string|int $id): array
    {
        $this->id($id);
        $data = $this->json('GET', '/v1.beta/invoices/'.$id, $token, ['query' => ['format' => 'en16931']]);
        if (!$this->validId($data['id'] ?? null) || (string) $data['id'] !== (string) $id) $this->invalidResponse();

        return $data;
    }

    /** No external_id filter is documented. Reconciliation must inspect the paginated outgoing list. */
    public function listInvoices(#[\SensitiveParameter] string $token, string $direction = 'in', string|int|null $afterId = null, int $limit = 100): array
    {
        if (!in_array($direction, ['in', 'out'], true)) throw new \InvalidArgumentException('Sens de facture invalide.');
        $query = ['direction' => $direction, 'order' => 'asc'] + $this->pagination($afterId, $limit);
        $data = $this->json('GET', '/v1.beta/invoices', $token, ['query' => $query]);
        $this->page($data);

        return $data;
    }

    /** Keep all events; the provider explicitly does not define an exclusive status state machine. */
    public function events(#[\SensitiveParameter] string $token, string|int|null $invoiceId = null, string|int|null $afterId = null, int $limit = 100): array
    {
        $query = $this->pagination($afterId, $limit);
        if ($invoiceId !== null) {
            $this->id($invoiceId);
            $query['invoice_id'] = $invoiceId;
        }
        $data = $this->json('GET', '/v1.beta/invoice_events', $token, ['query' => $query]);
        $this->page($data);

        return $data;
    }

    /** @return array{content:string,contentType:string} */
    public function downloadInvoice(#[\SensitiveParameter] string $token, string|int $id, string $format = 'original'): array
    {
        $this->id($id);
        if (!in_array($format, ['original', 'cii', 'ubl', 'factur-x'], true)) throw new \InvalidArgumentException('Format de téléchargement invalide.');
        $result = $this->raw('GET', '/v1.beta/invoices/'.$id, $token, [
            'query' => ['format' => $format], 'headers' => ['Accept' => 'application/pdf, application/xml'],
        ]);
        if (str_starts_with($result['content'], '%PDF-')) {
            $result['contentType'] = 'application/pdf';
        } else {
            $this->xmlResponse($result['content']);
            $result['contentType'] = 'application/xml';
        }

        return $result;
    }

    /** Public conversion endpoint: this operation does not issue or deliver an invoice. */
    public function convertInvoice(#[\SensitiveParameter] array $enInvoice): string
    {
        if ($enInvoice === [] || array_is_list($enInvoice)) throw new \InvalidArgumentException('Facture structurée absente.');
        $result = $this->raw('POST', '/v1.beta/invoices/convert', null, [
            'query' => ['from' => 'en16931', 'to' => 'ubl'],
            'headers' => ['Accept' => 'application/xml', 'Content-Type' => 'application/json'],
            'body' => json_encode($enInvoice, JSON_THROW_ON_ERROR),
        ]);
        $this->xmlResponse($result['content']);

        return $result['content'];
    }

    /** Public validator; check data[0].is_valid === true, not just the HTTP status. */
    public function validateInvoice(#[\SensitiveParameter] string $xml): array
    {
        $this->xml($xml);
        $form = new FormDataPart(['file_name' => new DataPart($xml, 'invoice.xml', 'application/xml')]);
        $data = $this->json('POST', '/v1.beta/validation_reports', null, [
            'headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToString(),
        ]);
        if (!is_array($data['data'] ?? null) || count($data['data']) !== 1
            || !is_bool($data['data'][0]['is_valid'] ?? null)) $this->invalidResponse();

        return $data;
    }

    private function token(string $clientId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] array $fields): array
    {
        $this->credential($clientId);
        $this->credential($secret);
        $data = $this->json('POST', '/oauth2/token', null, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            // Standard OAuth client_secret_post; no credential appears in a URL.
            'body' => http_build_query($fields + ['client_id' => $clientId, 'client_secret' => $secret], '', '&', PHP_QUERY_RFC3986),
        ], true);
        if (!is_string($data['access_token'] ?? null) || $data['access_token'] === ''
            || !is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === ''
            || !is_numeric($data['expires_in'] ?? null) || (int) $data['expires_in'] <= 0
            || strcasecmp((string) ($data['token_type'] ?? ''), 'Bearer') !== 0) $this->invalidResponse(true);

        return $data;
    }

    private function json(string $method, string $path, #[\SensitiveParameter] ?string $token, #[\SensitiveParameter] array $options = [], bool $mutation = false): array
    {
        $result = $this->raw($method, $path, $token, $options, $mutation);
        try {
            $data = json_decode($result['content'], true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            $this->invalidResponse($mutation);
        }
        if (!is_array($data) || array_is_list($data)) $this->invalidResponse($mutation);

        return $data;
    }

    /** @return array{content:string,contentType:string} */
    private function raw(string $method, string $path, #[\SensitiveParameter] ?string $token, #[\SensitiveParameter] array $options = [], bool $mutation = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $this->credential($token);
            $headers['Authorization'] = 'Bearer '.$token;
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            if (is_int($name)) $headers[] = $value;
            else $headers[$name] = $value;
        }
        $options['headers'] = $headers;
        $options += ['max_redirects' => 0, 'timeout' => 20.0, 'max_duration' => 60.0,
            'extra' => ['trace_content' => false]];
        if (is_string($options['body'] ?? null) && strlen($options['body']) > self::MAX_DOCUMENT_BYTES + 16384) {
            throw new \InvalidArgumentException('Document trop volumineux pour ce connecteur.');
        }
        try {
            $response = $this->httpClient->request($method, self::BASE_URL.$path, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();
                $uncertain = $mutation && ($status >= 500 || $status === 408);
                throw new SuperPdpException($this->operationLabel($method, $path). ' : '. match ($status) {
                    401 => 'La connexion à la plateforme agréée a expiré. Reconnectez l’organisme.',
                    403 => 'La plateforme agréée n’autorise pas cette opération. Vérifiez le compte et sa validation.',
                    429 => 'La plateforme agréée limite temporairement les requêtes.',
                    default => $uncertain ? 'La plateforme n’a pas confirmé le résultat. Vérifiez la réception avant tout nouvel envoi.' : 'La plateforme agréée a refusé la requête (HTTP '.$status.').',
                }, $status, $uncertain);
            }
            $body = $response->getContent(false);
            if (strlen($body) > self::MAX_DOCUMENT_BYTES) $this->invalidResponse($mutation);

            return ['content' => $body, 'contentType' => $response->getHeaders(false)['content-type'][0] ?? 'application/octet-stream'];
        } catch (TransportExceptionInterface) {
            // Discard the underlying exception; its message/debug data may contain credentials.
            throw new SuperPdpException($mutation
                ? 'Résultat non confirmé après une interruption réseau. Vérifiez la réception avant tout nouvel envoi.'
                : 'La plateforme agréée est momentanément inaccessible.', null, $mutation);
        }
    }

    /** Only fixed labels are logged, never provider bodies, URLs or query values. */
    private function operationLabel(string $method, string $path): string
    {
        return match ($path) {
            '/oauth2/token' => 'Autorisation ou renouvellement de connexion',
            '/v1.beta/oauth2_sessions/me' => 'Vérification de la session',
            '/v1.beta/companies/me' => 'Vérification de l’organisme',
            '/v1.beta/french_directory/entries' => 'Recherche du destinataire dans l’annuaire',
            '/v1.beta/invoices/convert' => 'Conversion de la facture en UBL',
            '/v1.beta/validation_reports' => 'Validation du document UBL',
            '/v1.beta/invoices' => $method === 'POST' ? 'Dépôt de la facture' : 'Lecture des factures distantes',
            '/v1.beta/invoice_events' => 'Lecture des événements',
            default => 'Lecture du document distant',
        };
    }

    private function pagination(string|int|null $afterId, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Taille de page invalide.');
        $query = ['limit' => $limit];
        if ($afterId !== null) {
            $this->id($afterId);
            $query['starting_after_id'] = $afterId;
        }

        return $query;
    }

    private function page(array $data): void
    {
        if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || !is_bool($data['has_after'] ?? null)) $this->invalidResponse();
        foreach ($data['data'] as $item) if (!is_array($item) || !$this->validId($item['id'] ?? null)) $this->invalidResponse();
    }

    private function validId(mixed $id): bool
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)
            && (strlen((string) $id) < 19 || strcmp((string) $id, '9223372036854775807') <= 0);
    }

    private function id(string|int $id): void
    {
        if (!$this->validId($id)) throw new \InvalidArgumentException('Identifiant de facture invalide.');
    }

    private function credential(#[\SensitiveParameter] string $value): void
    {
        if ($value === '' || strlen($value) > 16384 || preg_match('/[\x00-\x20\x7f]/', $value)) throw new \InvalidArgumentException('Identifiant de connexion invalide.');
    }

    private function redirectUri(string $uri): void
    {
        $parts = parse_url($uri);
        $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))) {
            throw new \InvalidArgumentException('URL de retour OAuth invalide.');
        }
    }

    private function xml(#[\SensitiveParameter] string $xml): void
    {
        if (!$this->isXml($xml)) throw new \InvalidArgumentException('Le document doit être une facture XML UBL ou CII valide.');
    }

    private function xmlResponse(#[\SensitiveParameter] string $xml): void
    {
        if (!$this->isXml($xml)) $this->invalidResponse();
    }

    private function isXml(#[\SensitiveParameter] string $xml): bool
    {
        if ($xml === '' || strlen($xml) > self::MAX_DOCUMENT_BYTES || stripos($xml, '<!DOCTYPE') !== false) return false;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            return $doc->loadXML($xml, LIBXML_NONET) && in_array($doc->documentElement?->localName, ['Invoice', 'CreditNote', 'CrossIndustryInvoice'], true);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function invalidResponse(bool $uncertain = false): never
    {
        throw new SuperPdpException('La plateforme agréée a renvoyé une réponse inexploitable.', null, $uncertain);
    }
}
