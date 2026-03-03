<?php
// src/Service/Tax/TaxResult.php

namespace App\Service\Tax;

final class TaxResult
{
  public function __construct(
    public readonly \DateTimeImmutable $from,
    public readonly \DateTimeImmutable $to,
    public readonly string $currency,
    public readonly int $globalBaseCents,
    public readonly int $globalTotalCents,
    /** @var array<int, array<string,mixed>> */
    public readonly array $items,
    /** @var array<string,mixed> */
    public readonly array $meta = [],
  ) {}
}
