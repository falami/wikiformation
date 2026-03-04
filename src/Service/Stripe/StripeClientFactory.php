<?php

namespace App\Service\Stripe;

use Stripe\StripeClient;

class StripeClientFactory
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $apiVersion = '2026-01-28', // recommandé par Stripe skill :contentReference[oaicite:2]{index=2}
    ) {}

    public function client(): StripeClient
    {
        return new StripeClient([
            'api_key' => $this->secretKey,
            'stripe_version' => $this->apiVersion,
        ]);
    }
}