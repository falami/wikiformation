<?php

namespace App\Enum;

enum InteractionChannel: string
{
  case EMAIL = 'email';
  case PHONE = 'phone';
  case WEBSITE = 'website';
  case LINKEDIN = 'linkedin';
  case EVENT = 'event';
  case REFERRAL = 'referral';
  case OTHER = 'other';
  case QUOTE = 'quote';
  case INVOICE = 'invoice';

  public function label(): string
  {
    return match ($this) {
      self::EMAIL    => 'Email',
      self::PHONE    => 'Téléphone',
      self::WEBSITE  => 'Site internet',
      self::LINKEDIN => 'LinkedIn',
      self::EVENT    => 'Évènement',
      self::REFERRAL => 'Recommandation',
      self::OTHER    => 'Autre',
      self::QUOTE => 'Devis',
      self::INVOICE => 'Facture',
    };
  }

  public function icon(): string
  {
    return match ($this) {
      self::EMAIL    => 'bi-envelope',
      self::PHONE    => 'bi-telephone',
      self::WEBSITE  => 'bi-globe',
      self::LINKEDIN => 'bi-linkedin',
      self::EVENT    => 'bi-calendar-event',
      self::REFERRAL => 'bi-people',
      self::OTHER    => 'bi-three-dots',
      self::QUOTE => 'bi-file-earmark-text',
      self::INVOICE => 'bi-receipt',
    };
  }

  public function badgeClass(): string
  {
    return match ($this) {
      self::WEBSITE  => 'bg-primary-subtle text-primary',
      self::REFERRAL => 'bg-success-subtle text-success',
      self::LINKEDIN => 'bg-info-subtle text-info',
      self::EMAIL    => 'bg-secondary-subtle text-secondary',
      self::PHONE    => 'bg-warning-subtle text-warning',
      self::EVENT    => 'bg-danger-subtle text-danger',
      self::OTHER    => 'bg-light text-muted',
      self::QUOTE => 'bg-primary-subtle text-primary',
      self::INVOICE => 'bg-success-subtle text-success',
    };
  }
}
