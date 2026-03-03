<?php

// src/Service/Tax/TaxRuleMatcher.php
namespace App\Service\Tax;

use App\Entity\FiscalProfile;
use App\Entity\TaxRule;

final class TaxRuleMatcher
{
  public function matches(TaxRule $rule, FiscalProfile $profile): bool
  {
    $c = $rule->getConditions() ?? [];

    // country
    if (isset($c['country']) && $c['country'] !== $profile->getCountry()) return false;

    // regime
    if (isset($c['regime']) && $c['regime'] !== $profile->getRegime()) return false;

    // activity can be string or array
    if (isset($c['activity'])) {
      $allowed = is_array($c['activity']) ? $c['activity'] : [$c['activity']];
      if (!$profile->getActivity() || !in_array($profile->getActivity(), $allowed, true)) return false;
    }

    // options subset
    if (isset($c['options']) && is_array($c['options'])) {
      $opts = $profile->getOptions() ?? [];
      foreach ($c['options'] as $k => $v) {
        if (!array_key_exists($k, $opts)) return false;
        if ((bool)$opts[$k] !== (bool)$v) return false;
      }
    }

    return true;
  }
}
