<?php

namespace App\Enum;

enum OrderStatus: string
{
  case DRAFT = 'DRAFT';
  case PENDING = 'PENDING';
  case PAID = 'PAID';
  case CANCELED = 'CANCELED';
}
