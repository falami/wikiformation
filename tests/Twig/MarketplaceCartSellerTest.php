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
    public function testUnverifiedProcessingFeesAreNotPresentedAsFinalAmounts(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['marketplace/base.html.twig' => '{% block content %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $params = []): string => '/'.$route));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(string $id): string => 'test-token'));
        $sales = [];
        foreach (['pending', 'reconciliation_required', 'verified', null] as $status) {
            $sales[] = ['createdAt' => new \DateTimeImmutable(), 'items' => [], 'totalCents' => 5000,
                'commissionCents' => 1000, 'commissionTaxCents' => 200, 'stripeFeeCents' => $status === 'pending' ? 0 : 145,
                'consent' => $status ? ['stripe_processing_fees' => ['status' => $status]] : [],
                'platformSale' => false, 'transferredCents' => 0, 'status' => 'paid'];
        }
        $html = $twig->render('marketplace/seller.html.twig', ['seller' => new Seller(), 'platformSeller' => false,
            'settings' => ['commissionBps' => 2000], 'mandateText' => '', 'entite' => ['id' => 1], 'sales' => $sales,
            'sellerPaymentPolicy' => \App\Service\Marketplace\SellerPaymentPolicy::snapshot(new \App\Entity\Marketplace\Settings())]);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $cells = (new \DOMXPath($document))->query('//tbody/tr/td[5]');
        self::assertSame('En cours de rapprochement', trim($cells->item(0)->textContent));
        self::assertSame('En cours de rapprochement', trim($cells->item(1)->textContent));
        self::assertSame('1,45 €', trim($cells->item(2)->textContent));
        self::assertSame('1,45 €', trim($cells->item(3)->textContent));
        self::assertStringContainsString('sans supplément ajouté au prix payé par l’acheteur', $html);
    }

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
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        self::assertSame(0, $xpath->query('//form[@action="/app_market_cart_remove"]//*[starts-with(@name, "billing[")]')->length);
        self::assertGreaterThan(0, $xpath->query('//form[@action="/app_market_checkout"]//*[starts-with(@name, "billing[")]')->length);
        self::assertSame(1, $xpath->query('//form[@action="/app_market_checkout"]/button[@disabled]')->length, 'No payment is offered when readiness is missing.');
    }
}
