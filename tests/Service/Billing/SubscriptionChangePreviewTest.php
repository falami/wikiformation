<?php
namespace App\Tests\Service\Billing;

use App\Service\Billing\SubscriptionChangePreview;
use PHPUnit\Framework\TestCase;
use Stripe\Invoice;

final class SubscriptionChangePreviewTest extends TestCase
{
    public function testModernInvoiceSeparatesCreditUpgradeAndRenewalWithTax(): void
    {
        $invoice = Invoice::constructFrom(['amount_due' => 10677, 'lines' => ['data' => [
            ['amount' => -2898, 'parent' => ['subscription_item_details' => ['proration' => true]], 'taxes' => [['amount' => -580]]],
            ['amount' => 5896, 'parent' => ['invoice_item_details' => ['proration' => true]], 'taxes' => [['amount' => 1179]]],
            ['amount' => 5900, 'parent' => ['subscription_item_details' => ['proration' => false]], 'taxes' => [['amount' => 1180]]],
        ]]]);
        $summary = SubscriptionChangePreview::summarize($invoice);
        self::assertSame(2998, $summary['proration_cents']);
        self::assertSame(599, $summary['proration_tax_cents']);
        self::assertSame(5900, $summary['next_period_cents']);
        self::assertSame(1180, $summary['next_tax_cents']);
        self::assertSame(10677, $summary['amount_due_cents']);
    }
    public function testLegacyProrationAndUnchangedPlan(): void
    {
        $summary = SubscriptionChangePreview::summarize(Invoice::constructFrom(['amount_due' => 3480, 'lines' => ['data' => [
            ['amount' => 0, 'proration' => true, 'tax_amounts' => []],
            ['amount' => 2900, 'proration' => false, 'tax_amounts' => [['amount' => 580]]],
        ]]]));
        self::assertSame(0, $summary['proration_cents']);
        self::assertSame(2900, $summary['next_period_cents']);
        self::assertSame(580, $summary['next_tax_cents']);
    }
}
