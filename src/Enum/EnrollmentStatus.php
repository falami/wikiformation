<?php

namespace App\Enum;

enum EnrollmentStatus: string
{
  case ACTIVE = 'ACTIVE';
  case COMPLETED = 'COMPLETED';
  case EXPIRED = 'EXPIRED';
  case SUSPENDED = 'SUSPENDED';
  case UPCOMING = 'UPCOMING';
}
