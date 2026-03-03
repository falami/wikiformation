<?php

// src/Enum/TaxBase.php
namespace App\Enum;

enum TaxBase: string
{
  case CA_ENCAISSE_TTC = 'CA_ENCAISSE_TTC';
  case CA_ENCAISSE_HT  = 'CA_ENCAISSE_HT';
  case CA_FACTURE_TTC  = 'CA_FACTURE_TTC';
  case CA_FACTURE_HT   = 'CA_FACTURE_HT';
  case TVA_COLLECTEE   = 'TVA_COLLECTEE';
  case TVA_DEDUCTIBLE  = 'TVA_DEDUCTIBLE';
}
