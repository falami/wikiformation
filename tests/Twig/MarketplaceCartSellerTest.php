<?php
declare(strict_types=1);
namespace App\Tests\Twig;

use App\Entity\Marketplace\Seller;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};
use Twig\TwigFunction;

final class MarketplaceCartSellerTest extends TestCase
{
    public function testProfessionalSellerDetailsAreVisibleAndEscapedBeforeCheckout(): void
    {
        $seller = new Seller();$seller->id = 1;$seller->legalName = 'Vendeur <script>unsafe</script>';
        $seller->registration = '12345678900012';$seller->address = "12 rue <img src=x onerror=alert(1)>\n75001 Paris";
        $seller->email = 'seller@example.test';$seller->taxNumber = 'FR123456789';$seller->taxExemption = 'Mention fiscale';
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['marketplace/base.html.twig' => '{% block content %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $params = []): string => '/'.$route));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(string $id): string => 'test-token'));
        $html = $twig->render('marketplace/cart.html.twig', ['groups' => [['seller' => $seller, 'offers' => [['id' => 1, 'snapshot' => ['title' => 'Cours'], 'priceCents' => 1200]], 'total' => 1200]], 'settings' => ['termsVersion' => 'v1', 'enabled' => true]]);
        foreach (['12345678900012', '75001 Paris', 'seller@example.test', 'FR123456789', 'Mention fiscale'] as $value) {
            self::assertStringContainsString($value, $html);
            self::assertLessThan(strpos($html, 'action="/app_market_checkout"'), strpos($html, $value));
        }
        self::assertStringNotContainsString('<script>', $html);self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x', $html);
    }
}
