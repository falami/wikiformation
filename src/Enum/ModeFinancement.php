<?php
// src/Enum/ModeFinancement.php
namespace App\Enum;

enum ModeFinancement: string
{
  case INDIVIDUEL = 'INDIVIDUEL'; // autofinancé
  case CPF        = 'CPF';
  case ENTREPRISE = 'ENTREPRISE'; // payé par l’entreprise
  case OPCO       = 'OPCO';        // subrogation / financement OPCO

  public function label(): string
  {
    return match ($this) {
      self::INDIVIDUEL => 'Individuel',
      self::CPF        => 'CPF',
      self::ENTREPRISE => 'Entreprise',
      self::OPCO       => 'OPCO',
    };
  }

  /** Le document FINANCEUR requis */
  public function requiresConvention(): bool
  {
    return match ($this) {
      self::ENTREPRISE, self::OPCO => true,
      default => false,
    };
  }

  public function requiresContratStagiaire(): bool
  {
    return match ($this) {
      self::INDIVIDUEL, self::CPF => true,
      default => false,
    };
  }
}
