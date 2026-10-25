<?php
declare(strict_types=1);
namespace App\Tests\Twig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
final class AnalyticsPrivacyTest extends TestCase
{
    private function render(string $route, array $cookies, ?object $user = null, string $host = 'wikiformation.fr'): string
    {
        $request = Request::create('https://'.$host.'/fr/tarifs?token=secret', 'GET', [], $cookies);
        $request->attributes->set('_route', $route);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]);
        return $twig->render('_public_analytics.html.twig', ['app' => (object) ['request' => $request, 'user' => $user]]);
    }
    public function testAnalyticsRequiresCurrentExplicitConsentAndSafePublicContext(): void
    {
        $consent = ['cookieConsent' => 'accepted', 'cookieConsentVersion' => '2026-10-25'];
        foreach ([[], ['cookieConsent' => 'accepted'], ['cookieConsent' => 'rejected', 'cookieConsentVersion' => '2026-10-25']] as $cookies) {
            self::assertStringNotContainsString('googletagmanager', $this->render('app_public', $cookies));
        }
        self::assertStringNotContainsString('googletagmanager', $this->render('app_public', $consent, (object) ['id' => 1]));
        self::assertStringNotContainsString('googletagmanager', $this->render('app_login', $consent));
        self::assertStringNotContainsString('googletagmanager', $this->render('app_public', $consent, null, 'client.example.test'));
        $html = $this->render('app_public_pricing', $consent);
        self::assertStringContainsString('googletagmanager', $html);
        self::assertStringContainsString('data-turbo-track="reload"', $html);
        self::assertStringNotContainsString('token=secret', $html);
        self::assertStringContainsString('location.origin + location.pathname', $html);
    }
}
