<?php

namespace App\Enum;

enum ProspectSource: string
{
  case WEBSITE = 'website';
  case REFERRAL = 'referral';
  case LINKEDIN = 'linkedin';
  case EMAIL = 'email';
  case PHONE = 'phone';
  case EVENT = 'event';
  case PARTNER = 'partner';
  case OTHER = 'other';

  public function label(): string
  {
    return match ($this) {
      self::WEBSITE   => 'Site internet',
      self::REFERRAL  => 'Recommandation',
      self::LINKEDIN  => 'LinkedIn',
      self::EMAIL    => 'Email',
      self::PHONE    => 'Téléphone',
      self::EVENT    => 'Évènement',
      self::PARTNER  => 'Partenaire',
      self::OTHER    => 'Autre',
    };
  }

  public function icon(): string
  {
    return match ($this) {
      self::WEBSITE   => 'bi-globe',
      self::REFERRAL  => 'bi-people',
      self::LINKEDIN  => 'bi-linkedin',
      self::EMAIL    => 'bi-envelope',
      self::PHONE    => 'bi-telephone',
      self::EVENT    => 'bi-calendar-event',
      self::PARTNER  => 'bi-diagram-3',
      self::OTHER    => 'bi-three-dots',
    };
  }
}
