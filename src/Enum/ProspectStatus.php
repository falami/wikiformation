<?php

namespace App\Enum;

enum ProspectStatus: string
{
  case NEW = 'new';
  case CONTACTED = 'contacted';
  case QUALIFIED = 'qualified';
  case PROPOSAL_SENT = 'proposal_sent';
  case NEGOTIATION = 'negotiation';
  case WON = 'won';
  case LOST = 'lost';
  case NURTURING = 'nurturing';
  case CONVERTED = 'converted';

  public function label(): string
  {
    return match ($this) {
      self::NEW            => 'Nouveau',
      self::CONTACTED      => 'Contacté',
      self::QUALIFIED      => 'Qualifié',
      self::PROPOSAL_SENT  => 'Devis envoyé',
      self::NEGOTIATION    => 'Négociation',
      self::WON            => 'Gagné',
      self::LOST           => 'Perdu',
      self::NURTURING      => 'À relancer',
      self::CONVERTED      => 'Converti',
    };
  }

  /**
   * Optionnel mais TRÈS utile pour ton UI (badges Bootstrap)
   */
  public function badgeClass(): string
  {
    return match ($this) {
      self::NEW           => 'bg-light text-dark',
      self::CONTACTED     => 'bg-info text-dark',
      self::QUALIFIED     => 'bg-primary',
      self::PROPOSAL_SENT => 'bg-warning text-dark',
      self::NEGOTIATION   => 'bg-warning text-dark',
      self::WON           => 'bg-success',
      self::LOST          => 'bg-danger',
      self::NURTURING     => 'bg-secondary',
      self::CONVERTED     => 'bg-secondary',
    };
  }

  /**
   * Optionnel : icône cohérente avec ton design
   */
  public function icon(): string
  {
    return match ($this) {
      self::NEW           => 'bi-stars',
      self::CONTACTED     => 'bi-chat-dots',
      self::QUALIFIED     => 'bi-check2-circle',
      self::PROPOSAL_SENT => 'bi-file-earmark-text',
      self::NEGOTIATION   => 'bi-hand-thumbs-up',
      self::WON           => 'bi-trophy',
      self::LOST          => 'bi-x-circle',
      self::NURTURING     => 'bi-arrow-repeat',
      self::CONVERTED     => 'bi-arrow-repeat',
    };
  }
}
