<?php

namespace App\Exception;

final class BillingQuotaExceededException extends \DomainException
{
    public function __construct(
        private readonly string $quotaKey,
        private readonly int $current,
        private readonly int $limit,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getQuotaKey(): string
    {
        return $this->quotaKey;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}