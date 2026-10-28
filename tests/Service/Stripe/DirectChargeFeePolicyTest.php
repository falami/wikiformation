<?php

declare(strict_types=1);

namespace App\Tests\Service\Stripe;

use App\Exception\StripeFeeConfigurationUnavailable;
use App\Service\Stripe\DirectChargeFeePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectChargeFeePolicyTest extends TestCase
{
    private static function account(): array
    {
        return ['id' => 'acct_organism', 'object' => 'account', 'charges_enabled' => true, 'details_submitted' => true,
            'metadata' => ['entite_id' => '42'], 'controller' => ['fees' => ['payer' => 'account']]];
    }

    public function testStandardProcessingFeesAreNotChargedTwiceOrAddedToClientPrice(): void
    {
        foreach (['account', 'application_express', 'application_custom'] as $payer) {
            $account = self::account();
            $account['controller']['fees']['payer'] = $payer;
            $snapshot = DirectChargeFeePolicy::snapshot($account, 'acct_organism', 42);
            self::assertSame('connected_account', $snapshot['standardProcessingFeesPaidBy']);
            self::assertSame(0, $snapshot['clientSurchargeCents']);
            self::assertSame($payer, $snapshot['feePayer']);
            self::assertArrayNotHasKey('stripeFeeAmount', $snapshot, 'Do not invent fees that Stripe has not yet calculated.');
        }
    }

    #[DataProvider('invalidAccounts')]
    public function testUnknownFeesAndWrongOrDisabledAccountsFailClosed(array $replacement): void
    {
        $this->expectException(StripeFeeConfigurationUnavailable::class);
        DirectChargeFeePolicy::snapshot(array_replace(self::account(), $replacement), 'acct_organism', 42);
    }

    public static function invalidAccounts(): iterable
    {
        yield 'platform pays' => [['controller' => ['fees' => ['payer' => 'application']]]];
        yield 'unknown payer' => [['controller' => ['fees' => ['payer' => 'new_api_value']]]];
        yield 'missing payer' => [['controller' => []]];
        yield 'wrong account' => [['id' => 'acct_other']];
        yield 'deleted account' => [['deleted' => true]];
        yield 'wrong type' => [['object' => 'customer']];
        yield 'other tenant' => [['metadata' => ['entite_id' => '43']]];
        yield 'missing tenant mapping' => [['metadata' => []]];
        yield 'charges disabled' => [['charges_enabled' => false]];
        yield 'onboarding incomplete' => [['details_submitted' => false]];
    }
}
