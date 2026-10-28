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
        // Express/Custom legacy accounts pay standard processing fees themselves. Ancillary
        // products and Interchange Plus can still be billed to the platform: do not promise otherwise.
        if (!in_array($payer, ['account', 'application_express', 'application_custom'], true)) {
            throw new StripeFeeConfigurationUnavailable('La prise en charge des frais d’encaissement par l’organisme doit être confirmée dans Stripe avant un nouveau paiement.');
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
