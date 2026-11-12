<?php

// src/Service/Stripe/StripeClientFactory.php
namespace App\Service\Stripe;

use Stripe\StripeClient;

final class StripeClientFactory
{
    public function __construct(private readonly string $secretKey) {}

    public function mode(): string
    {
        if (preg_match('/^(sk|rk)_live_/', $this->secretKey)) {
            return 'live';
        }
        if (preg_match('/^(sk|rk)_test_/', $this->secretKey)) {
            return 'test';
        }

        return 'unknown';
    }

    public function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->secretKey]);
    }
}