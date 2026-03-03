<?php

// src/Service/Tax/Dto/TaxLine.php
namespace App\Service\Tax\Dto;

final class TaxLine
{
  public function __construct(
    public string $code,
    public string $label,
    public float $rate,
    public int $baseCents,
    public int $amountCents,
    public string $kind,
  ) {}
}
