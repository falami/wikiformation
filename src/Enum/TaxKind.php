<?php

// src/Enum/TaxKind.php
namespace App\Enum;

enum TaxKind: string
{
  case CONTRIBUTION = 'CONTRIBUTION'; // URSSAF, CFP, CCI/CMA...
  case TAX          = 'TAX';          // autres taxes
  case SOCIAL       = 'SOCIAL';
  case VAT          = 'VAT';
}
