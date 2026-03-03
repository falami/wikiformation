<?php


// src/Service/Tax/Dto/TaxResult.php
namespace App\Service\Tax\Dto;

final class TaxResult
{
  /** @param TaxLine[] $lines */
  public function __construct(
    public int $baseCents,
    public int $totalCents,
    public array $lines,
  ) {}
}
