<?php
// src/Enum/LabelledEnum.php
namespace App\Enum;

interface LabelledEnum
{
  public function label(): string;
}
