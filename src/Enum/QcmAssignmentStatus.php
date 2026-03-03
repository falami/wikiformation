<?php

namespace App\Enum;

enum QcmAssignmentStatus: string
{
  case ASSIGNED = 'assigned';
  case STARTED = 'started';
  case SUBMITTED = 'submitted';
  case REVIEW_REQUIRED = 'review_required';
  case VALIDATED = 'validated';

  public function label(): string
  {
    return match ($this) {
      self::ASSIGNED => 'Assigné',
      self::STARTED => 'En cours',
      self::SUBMITTED => 'Soumis',
      self::REVIEW_REQUIRED => 'Action requise',
      self::VALIDATED => 'Validé',
    };
  }
}
