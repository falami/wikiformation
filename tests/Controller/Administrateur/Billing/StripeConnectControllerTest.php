<?php
namespace App\Tests\Controller\Administrateur\Billing;

use App\Controller\Administrateur\Billing\StripeConnectController;
use App\Entity\Billing\EntiteConnect;
use App\Entity\Entite;
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\{Request, RequestStack, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StripeConnectControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function testOnboarding(int $status, ?string $code, bool $replaced, bool $linked, array $replacement = [], string $key = 'sk_live_fixture', bool $creationFails = false): void
    {
        $entite = (new Entite())->setEmail('test@example.org')->setNom('Test');
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, 42);
        $connect = (new EntiteConnect())->setEntite($entite)->setStripeAccountId('acct_old')
            ->setDetailsSubmitted(true)->setChargesEnabled(true)->setPayoutsEnabled(true);
        $entite->setConnect($connect);
        $calls = [];
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$calls, $status, $code, $creationFails) {
            $path = parse_url($url, PHP_URL_PATH);
            $calls[] = [$method, $path, $params];
            if ($method === 'get') {
                return [json_encode($code ? ['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => 'Unavailable']] : ['id' => 'acct_old', 'object' => 'account']), $status, []];
            }
            if ($path === '/v1/accounts') {
                if ($creationFails) {
                    return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Denied']]), 403, []];
                }
                return [json_encode(['id' => 'acct_new', 'object' => 'account']), 200, []];
            }
            self::assertSame('/v1/account_links', $path);
            return [json_encode(['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/test']), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($replaced ? self::once() : self::never())->method('flush');
        $controller = new StripeConnectController(new StripeClientFactory($key), $em, 'https://example.org');
        $container = new Container();
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $container->set('security.csrf.token_manager', $csrf);
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/connect');
        $container->set('router', $router);
        $request = Request::create('/connect/start', 'POST', ['_token' => 'valid'] + $replacement);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $container->set('request_stack', $stack);
        $controller->setContainer($container);
        $response = $controller->start($entite, $request);
        self::assertSame($replaced ? 'acct_new' : 'acct_old', $connect->getStripeAccountId());
        self::assertSame(!$replaced, $connect->isChargesEnabled());
        self::assertSame($linked ? 'https://connect.stripe.com/setup/test' : '/connect', $response->headers->get('Location'));
        self::assertCount($creationFails ? 2 : ($replaced ? 3 : ($linked ? 2 : 1)), $calls);
    }

    public static function scenarios(): array
    {
        return [
            'existing account reused' => [200, null, false, true],
            'missing test account replaced' => [404, 'resource_missing', true, true],
            'permission error preserves account' => [403, 'permission_denied', false, false],
            'confirmed production replacement' => [403, 'permission_denied', true, true, ['replaceAccount' => 'acct_old', 'confirmReplacement' => '1']],
            'stale confirmation rejected' => [403, 'permission_denied', false, false, ['replaceAccount' => 'acct_other', 'confirmReplacement' => '1']],
            'unchecked confirmation rejected' => [403, 'permission_denied', false, false, ['replaceAccount' => 'acct_old']],
            'test mode replacement rejected' => [403, 'permission_denied', false, false, ['replaceAccount' => 'acct_old', 'confirmReplacement' => '1'], 'sk_test_fixture'],
            'creation failure preserves old account' => [403, 'permission_denied', false, false, ['replaceAccount' => 'acct_old', 'confirmReplacement' => '1'], 'sk_live_fixture', true],
            'other invalid request preserves account' => [400, 'parameter_invalid', false, false],
        ];
    }
}
