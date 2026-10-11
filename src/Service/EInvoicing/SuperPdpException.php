<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

/** Safe to display/log: never contains credentials, provider payloads or a previous HTTP exception. */
final class SuperPdpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct($message);
    }

    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
