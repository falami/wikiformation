<?php
declare(strict_types=1);
namespace App\Tests\Service\Marketplace;

use App\Exception\MarketplaceInvoiceUnavailable;
use App\Service\Marketplace\MarketplaceInvoiceReadiness;
use PHPUnit\Framework\TestCase;

final class MarketplaceInvoiceReadinessTest extends TestCase
{
    public function testRealGateIsClosedUntilSellerAndPlatformHaveAnIssuancePipeline(): void
    {
        $guard = new MarketplaceInvoiceReadiness();
        self::assertFalse($guard->status()['available']);
        self::assertSame(MarketplaceInvoiceReadiness::MESSAGE, $guard->status()['message']);
        $this->expectException(MarketplaceInvoiceUnavailable::class);
        $guard->assertAvailable();
    }
}
