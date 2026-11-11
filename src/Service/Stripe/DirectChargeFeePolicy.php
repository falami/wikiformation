<?php

declare(strict_types=1);

namespace App\Service\Stripe;

use App\Exception\StripeFeeConfigurationUnavailable;

/** Standard processing fees are debited by Stripe, never added to the client's invoice. */
final class DirectChargeFeePolicy
{
    public static function snapshot(array $account, string $expectedAccount, int $entiteId): array
    {
        if (!str_starts_with($expectedAccount, 'acct_') || ($account['id'] ?? null) !== $expectedAccount
            || ($account['object'] ?? null) !== 'account' || ($account['deleted'] ?? false)
            || $entiteId <= 0 || ($account['metadata']['entite_id'] ?? null) !== (string) $entiteId) {
            throw new StripeFeeConfigurationUnavailable('Le rattachement du compte Stripe à cet organisme doit être vérifié avant un nouvel encaissement.');
        }
        if (($account['charges_enabled'] ?? false) !== true || ($account['details_submitted'] ?? false) !== true) {
            throw new StripeFeeConfigurationUnavailable('Le compte Stripe de cet organisme ne permet pas encore les encaissements. Terminez sa configuration.');
        }
        $payer = $account['controller']['fees']['payer'] ?? null;
        // Only account-paid fees meet the platform policy, including ancillary products.
        if ($payer !== 'account') {
            throw new StripeFeeConfigurationUnavailable('Ce compte Stripe ne garantit pas la facturation des frais à l’organisme. Un compte avec les frais à la charge du compte connecté est requis avant tout nouveau paiement.');
        }

        return [
            'version' => 1,
            'feePayer' => $payer,
            'standardProcessingFeesPaidBy' => 'connected_account',
            'clientSurchargeCents' => 0,
            'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
