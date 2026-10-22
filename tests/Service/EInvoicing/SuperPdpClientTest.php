<?php

declare(strict_types=1);

namespace App\Tests\Service\EInvoicing;

use App\Service\EInvoicing\{SuperPdpClient, SuperPdpException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\{MockHttpClient, RetryableHttpClient, TraceableHttpClient};
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SuperPdpClientTest extends TestCase
{
    private const XML = '<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>TEST-001</ID></Invoice>';

    public function testPreparationErrorsIdentifyOperationWithoutProviderData(): void
    {
        $client = new SuperPdpClient(new MockHttpClient(fn () => new MockResponse('{"message":"private-customer-token"}', ['http_code' => 400])));
        foreach ([
            [fn () => $client->directoryEntries('123456789'), 'Recherche du destinataire'],
            [fn () => $client->convertInvoice(['invoice_number' => 'private-number']), 'Conversion de la facture'],
            [fn () => $client->validateInvoice(self::XML), 'Validation du document'],
        ] as [$call, $label]) {
            try {
                $call();
                self::fail('Expected HTTP rejection.');
            } catch (SuperPdpException $error) {
                self::assertStringContainsString($label, $error->getMessage());
                self::assertStringContainsString('HTTP 400', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
                self::assertFalse($error->isUncertain());
            }
        }
    }

    public function testAuthorizationUrlUsesPkceAndAccountHintsWithoutCredentials(): void
    {
        $url = (new SuperPdpClient(new MockHttpClient()))->authorizationUrl('app-1', 'https://wiki.test/oauth/callback', str_repeat('a', 32), str_repeat('b', 43), '123456789', 'fr_siren', 'test@example.test');
        self::assertStringStartsWith('https://api.superpdp.tech/oauth2/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('123456789', $query['superpdp_company_number']);
        self::assertSame('fr_siren', $query['superpdp_company_number_scheme']);
        self::assertSame('test@example.test', $query['login_hint']);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('client_secret', $query);
    }

    public function testOAuthCodeExchangeAndRefreshRotateTokensAndNeverPutSecretsInUrl(): void
    {
        $calls = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            $calls[] = [$method, $url, $options];
            return new MockResponse(json_encode(['access_token' => 'access-'.count($calls), 'refresh_token' => 'refresh-'.count($calls), 'token_type' => 'Bearer', 'expires_in' => 1800]));
        });
        $client = new SuperPdpClient($http);
        $token = $client->exchangeCode('app-1', 'secret-private', 'code-private', 'https://wiki.test/oauth/callback', str_repeat('v', 43));
        self::assertSame('refresh-1', $token['refresh_token']);
        $renewed = $client->refreshToken('app-1', 'secret-private', $token['refresh_token']);
        self::assertSame('refresh-2', $renewed['refresh_token']);
        foreach ($calls as [$method, $url, $options]) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.superpdp.tech/oauth2/token', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(20.0, $options['timeout']);
            self::assertSame(60.0, $options['max_duration']);
            self::assertFalse($options['extra']['trace_content']);
            parse_str($options['body'], $body);
            self::assertSame('secret-private', $body['client_secret']);
        }
        parse_str($calls[0][2]['body'], $first);
        self::assertSame('authorization_code', $first['grant_type']);
        self::assertSame(str_repeat('v', 43), $first['code_verifier']);
        parse_str($calls[1][2]['body'], $second);
        self::assertSame('refresh_token', $second['grant_type']);
        self::assertSame('refresh-1', $second['refresh_token']);
    }

    public function testVerificationAndCompanyAreSeparateCallsAndEnvironmentComesFromAccount(): void
    {
        $paths = [];
        $client = new SuperPdpClient(new MockHttpClient(function ($method, $url, $options) use (&$paths) {
            $paths[] = $url;
            self::assertContains('Authorization: Bearer tenant-a', $options['headers']);
            $body = str_ends_with($url, '/oauth2_sessions/me')
                ? ['client_id' => 'app', 'company_verification_status' => 'needs_review']
                : ['id' => 42, 'env' => 'sandbox', 'number_scheme' => 'sandbox', 'number' => '000000001'];
            return new MockResponse(json_encode($body));
        }));
        self::assertSame('needs_review', $client->session('tenant-a')['company_verification_status']);
        self::assertSame('sandbox', $client->company('tenant-a')['env']);
        self::assertSame(['https://api.superpdp.tech/v1.beta/oauth2_sessions/me', 'https://api.superpdp.tech/v1.beta/companies/me'], $paths);
    }

    public function testUploadUsesRawXmlAndDoesNotTreatQueuedResponseAsDelivered(): void
    {
        $client = new SuperPdpClient(new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.superpdp.tech/v1.beta/invoices?external_id=wf-e2-f3&processing_rule=B2B', $url);
            self::assertSame(self::XML, $options['body']);
            self::assertContains('Content-Type: application/xml', $options['headers']);
            self::assertContains('Authorization: Bearer tenant-b', $options['headers']);
            self::assertStringNotContainsString('disable_pre_check', $url);
            self::assertArrayNotHasKey('Idempotency-Key', $options['normalized_headers']);
            return new MockResponse('{"id":9223372036854775807,"company_id":2,"direction":"out","events":[{"status_code":"api:uploaded"}]}');
        }));
        $invoice = $client->sendInvoice('tenant-b', self::XML, 'wf-e2-f3');
        self::assertSame(PHP_INT_MAX, $invoice['id']);
        self::assertSame('api:uploaded', $invoice['events'][0]['status_code']);
    }

    public function testListingAndEventsUseDocumentedCursorAndRetainUnknownEvents(): void
    {
        $paths = [];
        $client = new SuperPdpClient(new MockHttpClient(function ($method, $url) use (&$paths) {
            $paths[] = $url;
            return new MockResponse('{"data":[{"id":20,"invoice_id":10,"status_code":"future:new-event"}],"has_after":true,"has_before":false,"count":1}');
        }));
        $list = $client->listInvoices('token', 'in', 18, 50);
        $events = $client->events('token', 10, 19, 100);
        self::assertTrue($list['has_after']);
        self::assertSame('future:new-event', $events['data'][0]['status_code']);
        self::assertSame('https://api.superpdp.tech/v1.beta/invoices?direction=in&order=asc&limit=50&starting_after_id=18', $paths[0]);
        self::assertSame('https://api.superpdp.tech/v1.beta/invoice_events?limit=100&starting_after_id=19&invoice_id=10', $paths[1]);
    }

    public function testConversionAndValidationAreAnonymousAndDoNotIssueInvoice(): void
    {
        $requests = [];
        $client = new SuperPdpClient(new MockHttpClient(function ($method, $url, $options) use (&$requests) {
            $requests[] = $url;
            self::assertSame('POST', $method);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            if (str_contains($url, '/convert')) {
                self::assertSame(['invoice' => ['invoice_number' => 'TEST-001']], json_decode($options['body'], true));
                self::assertContains('Accept: application/xml', $options['headers']);
                return new MockResponse(self::XML, ['response_headers' => ['Content-Type: application/xml']]);
            }
            self::assertStringContainsString('name="file_name"; filename="invoice.xml"', $options['body']);
            self::assertStringContainsString(self::XML, $options['body']);
            return new MockResponse('{"data":[{"file_name":"invoice.xml","is_valid":false,"subreports":[{"validator":"FR","failures":[{"code":"BR-FR-01"}]}]}]}');
        }));
        self::assertSame(self::XML, $client->convertInvoice(['invoice' => ['invoice_number' => 'TEST-001']]));
        self::assertFalse($client->validateInvoice(self::XML)['data'][0]['is_valid']);
        self::assertSame(['https://api.superpdp.tech/v1.beta/invoices/convert?from=en16931&to=ubl', 'https://api.superpdp.tech/v1.beta/validation_reports'], $requests);
    }

    public function testDownloadUsesSupportedFormatEndpointAndInfersSafeMime(): void
    {
        $responses = [new MockResponse(self::XML), new MockResponse('%PDF-1.7 mock')];
        $urls = [];
        $client = new SuperPdpClient(new MockHttpClient(function ($method, $url) use (&$responses, &$urls) {
            $urls[] = $url;
            return array_shift($responses);
        }));
        self::assertSame(['content' => self::XML, 'contentType' => 'application/xml'], $client->downloadInvoice('token', 42));
        self::assertSame('application/pdf', $client->downloadInvoice('token', 42, 'factur-x')['contentType']);
        self::assertSame(['https://api.superpdp.tech/v1.beta/invoices/42?format=original', 'https://api.superpdp.tech/v1.beta/invoices/42?format=factur-x'], $urls);
    }

    #[DataProvider('uploadFailures')]
    public function testUncertainSubmissionIsDistinguishedAndProviderPayloadIsNotLeaked(int $status, string $body, bool $uncertain): void
    {
        $calls = 0;
        $client = new SuperPdpClient(new MockHttpClient(function () use (&$calls, $status, $body) {
            ++$calls;
            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['Location: https://untrusted.test/secret']]);
        }));
        try {
            $client->sendInvoice('top-secret-token', self::XML, 'wf-1');
            self::fail('An exception was expected.');
        } catch (SuperPdpException $e) {
            self::assertSame($uncertain, $e->isUncertain());
            self::assertStringNotContainsString('top-secret', $e->getMessage());
            self::assertStringNotContainsString('customer-private', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertSame(1, $calls);
        }
    }

    public static function uploadFailures(): array
    {
        return [[400, '{"message":"customer-private"}', false], [401, 'top-secret', false],
            [403, 'top-secret', false], [429, 'top-secret', false], [302, 'top-secret', false],
            [500, 'top-secret', true], [408, 'top-secret', true], [200, 'not-json top-secret', true], [200, '{"message":"customer-private"}', true]];
    }

    public function testTransportFailureIsUncertainOnlyForMutatingRequestsAndDoesNotRetry(): void
    {
        $calls = 0;
        $client = new SuperPdpClient(new MockHttpClient(function () use (&$calls) {
            ++$calls;
            throw new TransportException('top-secret-token in a provider debug URL');
        }));
        foreach ([true, false] as $mutating) {
            try {
                $mutating ? $client->sendInvoice('token', self::XML, 'wf-1') : $client->company('token');
                self::fail('Expected transport error');
            } catch (SuperPdpException $e) {
                self::assertSame($mutating, $e->uncertain);
                self::assertNull($e->httpStatus);
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString('top-secret', $e->getMessage());
            }
        }
        self::assertSame(2, $calls);
    }

    public function testCrossInvoiceResponseCannotBeReturnedAndDownloadHtmlIsRejected(): void
    {
        $client = new SuperPdpClient(new MockHttpClient([new MockResponse('{"id":43}'), new MockResponse('<html>login</html>')]));
        foreach (['invoice', 'downloadInvoice'] as $method) {
            try {
                $client->$method('token', 42);
                self::fail('Invalid response accepted');
            } catch (SuperPdpException $e) {
                self::assertFalse($e->uncertain);
            }
        }
    }

    public function testRejectsLocalInvalidInputBeforeAnyNetworkCall(): void
    {
        $calls = 0;
        $client = new SuperPdpClient(new MockHttpClient(function () use (&$calls) { ++$calls; return new MockResponse('{}'); }));
        $operations = [
            fn () => $client->invoice('token', '../companies/me'),
            fn () => $client->invoice('token', '9223372036854775808'),
            fn () => $client->listInvoices('token', 'in', null, 1001),
            fn () => $client->listInvoices('token', 'bad'),
            fn () => $client->company("secret\r\nInjected: bad"),
            fn () => $client->sendInvoice('token', '<!DOCTYPE Invoice [<!ENTITY x SYSTEM "file:///etc/passwd">]><Invoice>&x;</Invoice>', 'test'),
            fn () => $client->sendInvoice('token', self::XML, str_repeat('x', 37)),
            fn () => $client->sendInvoice('token', self::XML, 'test', 'OutOfScope'),
            fn () => $client->authorizationUrl('app', 'https://evil.test/#fragment', str_repeat('a', 32), str_repeat('b', 43)),
            fn () => $client->authorizationUrl('app', 'http://production.test/callback', str_repeat('a', 32), str_repeat('b', 43)),
        ];
        foreach ($operations as $operation) {
            try { $operation(); self::fail('Invalid input accepted'); }
            catch (\InvalidArgumentException) { self::assertSame(0, $calls); }
        }
    }

    public function testRetriesAndSecretTracingTransportsAreRejected(): void
    {
        foreach ([new RetryableHttpClient(new MockHttpClient()), new TraceableHttpClient(new MockHttpClient())] as $transport) {
            try { new SuperPdpClient($transport); self::fail('Unsafe transport accepted'); }
            catch (\LogicException $e) { self::assertStringContainsString('transport dédié', $e->getMessage()); }
        }
    }

    public function testFrenchRecipientDirectoryIsAnonymousAndUsesExactNumberFilter(): void
    {
        $api = new SuperPdpClient(new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('GET', $method);
            self::assertSame('https://api.superpdp.tech/v1.beta/french_directory/entries?number=987654321', $url);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            return new MockResponse('{"data":[{"company":{"number":"987654321"},"identifier":"0225:987654321","is_active":true}]}');
        }));
        self::assertSame('0225:987654321', $api->directoryEntries('987654321')['data'][0]['identifier']);
    }
}
