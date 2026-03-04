<?php

// src/Service/Stripe/StripeClientFactory.php
namespace App\Service\Stripe;

use Stripe\StripeClient;

final class StripeClientFactory
{
    public function __construct(private readonly string $secretKey) {}

    public function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->secretKey]);
    }
}