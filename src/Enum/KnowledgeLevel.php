<?php
// src/Enum/KnowledgeLevel.php
namespace App\Enum;


enum KnowledgeLevel: int
{
  case NONE = 0;
  case BASIC = 1;
  case GOOD = 2;
  case ADVANCED = 3;
}
