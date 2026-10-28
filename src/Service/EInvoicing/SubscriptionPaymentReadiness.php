<?php
declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\Billing\EntiteSubscription;

interface SubscriptionPaymentReadiness
{
    /** Read-only verification. Throws a safe DomainException if payment cannot be verified. */
    public function assertReady(EntiteSubscription $subscription): void;
}
