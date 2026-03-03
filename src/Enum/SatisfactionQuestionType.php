<?php
// src/Enum/SatisfactionQuestionType.php
namespace App\Enum;

enum SatisfactionQuestionType: string
{
  case STARS = 'stars';               // note "étoiles" 1..N (souvent 5)
  case SCALE = 'scale';               // note "échelle" min..max (ex: NPS 0..10)
  case YES_NO = 'yes_no';             // oui/non
  case TEXT = 'text';                 // texte court
  case TEXTAREA = 'textarea';         // texte long (commentaires)
  case CHOICE = 'choice';             // choix unique
  case MULTICHOICE = 'multichoice';   // choix multiple
  case MULTI_FORMATIONS = 'multi_formations'; // ton besoin futur

  public function label(): string
  {
    return match ($this) {
      self::STARS             => 'Étoiles',
      self::SCALE             => 'Échelle (min/max)',
      self::YES_NO            => 'Oui / Non',
      self::TEXT              => 'Texte (court)',
      self::TEXTAREA          => 'Texte (long)',
      self::CHOICE            => 'Choix (unique)',
      self::MULTICHOICE       => 'Choix (multiple)',
      self::MULTI_FORMATIONS  => 'Multi-Formations',
    };
  }

  public function isNumeric(): bool
  {
    return match ($this) {
      self::STARS, self::SCALE => true,
      default => false,
    };
  }

  public function isText(): bool
  {
    return match ($this) {
      self::TEXT, self::TEXTAREA => true,
      default => false,
    };
  }

  public function isChoice(): bool
  {
    return match ($this) {
      self::CHOICE, self::MULTICHOICE => true,
      default => false,
    };
  }
}
