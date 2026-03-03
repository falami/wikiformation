<?php
// src/Enum/FormateurSatisfactionMetricKey.php
namespace App\Enum;

enum FormateurSatisfactionMetricKey: string
{
  case OVERALL_RATING   = 'overall_rating';
  case TRAINER_RATING   = 'trainer_rating';
  case INTERN_RATING    = 'intern_rating';
  case SITE_RATING      = 'site_rating';
  case CONTENT_RATING   = 'content_rating';
  case ORGANISM_RATING  = 'organism_rating'; // ✅ NEW
  case RECOMMENDATION   = 'recommendation';

  public function label(): string
  {
    return match ($this) {
      self::OVERALL_RATING  => 'Note globale',
      self::TRAINER_RATING  => 'Note formateur',
      self::INTERN_RATING   => 'Note stagiaire',
      self::SITE_RATING     => 'Note lieu',
      self::CONTENT_RATING  => 'Note contenu',
      self::ORGANISM_RATING => 'Note organisme', // ✅ NEW
      self::RECOMMENDATION  => 'Recommandation',
    };
  }

  public function help(): string
  {
    return match ($this) {
      self::OVERALL_RATING  => 'Alimente la KPI “note globale” (dashboard / stats).',
      self::TRAINER_RATING  => 'Alimente la KPI “note formateur”.',
      self::INTERN_RATING  => 'Alimente la KPI “note stagiaire.',
      self::SITE_RATING     => 'Alimente la KPI “note du lieu”.',
      self::CONTENT_RATING  => 'Alimente la KPI “note du contenu”.',
      self::ORGANISM_RATING => 'Alimente la KPI “note organisme (centre de formation)”.', // ✅ NEW
      self::RECOMMENDATION  => 'Alimente la KPI “recommandation”.',
    };
  }

  public function defaultMax(): int
  {
    return match ($this) {
      self::RECOMMENDATION => 10,
      default => 10,
    };
  }
}
