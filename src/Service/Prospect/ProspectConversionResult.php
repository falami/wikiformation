<?php
// src/Service/Prospect/ProspectConversionResult.php
namespace App\Service\Prospect;

use App\Entity\Utilisateur;
use App\Entity\Entreprise;

final class ProspectConversionResult
{
  public function __construct(
    public readonly ?Utilisateur $user,
    public readonly ?Entreprise $entreprise,
  ) {}
}
