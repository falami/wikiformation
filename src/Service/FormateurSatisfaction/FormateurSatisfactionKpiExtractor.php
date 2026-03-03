<?php

namespace App\Service\FormateurSatisfaction;

use App\Entity\FormateurSatisfactionAttempt;
use App\Entity\FormateurSatisfactionQuestion;
use App\Entity\FormateurSatisfactionTemplate;

final class FormateurSatisfactionKpiExtractor
{
  public function apply(FormateurSatisfactionAttempt $attempt, FormateurSatisfactionTemplate $template, array $answers): void
  {
    $attempt->setNoteGlobale(null)->setRecommendationScore(null);

    $accOverall = ['sum' => 0, 'cnt' => 0];

    foreach ($template->getChapters() as $chapter) {
      foreach ($chapter->getQuestions() as $q) {
        $qid = $q->getId();
        if (!$qid) continue;

        $key = $q->getMetricKey();
        if (!$key) continue;

        if (!array_key_exists($qid, $answers)) continue;
        if (is_array($answers[$qid])) continue;

        $val = is_numeric($answers[$qid]) ? (int)$answers[$qid] : null;
        $val = $this->clampByQuestion($val, $q);
        if ($val === null) continue;

        if ($key === 'overall_rating') {
          $accOverall['sum'] += $val;
          $accOverall['cnt']++;
        }
        if ($key === 'recommendation') {
          $attempt->setRecommendationScore($val);
        }
      }
    }

    if ($accOverall['cnt'] > 0) {
      $attempt->setNoteGlobale((int) round($accOverall['sum'] / $accOverall['cnt']));
    }
  }

  private function clampByQuestion(?int $val, FormateurSatisfactionQuestion $q): ?int
  {
    if ($val === null) return null;

    $t = $q->getType()->value;
    if ($t === 'yes_no') return max(0, min(1, $val));

    if ($t === 'scale' || $t === 'stars') {
      $max = $q->getMetricMax() ?? 10;
      $max = max(1, min(20, $max));
      return max(0, min($max, $val));
    }

    return $val;
  }
}
