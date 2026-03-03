<?php
// src/Service/Satisfaction/SatisfactionKpiExtractor.php
namespace App\Service\Satisfaction;

use App\Entity\SatisfactionAttempt;
use App\Entity\SatisfactionQuestion;
use App\Entity\SatisfactionTemplate;

final class SatisfactionKpiExtractor
{
  public function apply(SatisfactionAttempt $attempt, SatisfactionTemplate $template, array $answers): void
  {
    // Reset
    $attempt
      ->setNoteGlobale(null)
      ->setNoteFormateur(null)
      ->setNoteSite(null)
      ->setNoteContenu(null)
      ->setNoteOrganisme(null)
      ->setRecommendationScore(null);

    // ✅ Agrégation (moyenne) pour les KPI scale
    $acc = [
      'overall_rating'  => ['sum' => 0, 'cnt' => 0],
      'trainer_rating'  => ['sum' => 0, 'cnt' => 0],
      'site_rating'     => ['sum' => 0, 'cnt' => 0],
      'content_rating'  => ['sum' => 0, 'cnt' => 0],
      'organism_rating' => ['sum' => 0, 'cnt' => 0],
    ];

    foreach ($template->getChapters() as $chapter) {
      foreach ($chapter->getQuestions() as $q) {
        $qid = $q->getId();
        if (!$qid) continue;

        $key = $q->getMetricKey();
        if (!$key) continue;

        if (!array_key_exists($qid, $answers)) continue;

        $raw = $answers[$qid];
        if (is_array($raw)) continue;

        // numeric -> int, sinon null
        $val = is_numeric($raw) ? (int) $raw : null;

        // clamp selon la question
        $val = $this->clampByQuestion($val, $q);

        // ✅ ne jamais prendre en compte du null (évite d’écraser / fausser les moyennes)
        if ($val === null) {
          continue;
        }

        // ✅ KPI agrégées (moyenne)
        if (isset($acc[$key])) {
          $acc[$key]['sum'] += $val;
          $acc[$key]['cnt']++;
          continue;
        }

        // KPI spéciale (NPS)
        if ($key === 'recommendation') {
          $attempt->setRecommendationScore($val);
          continue;
        }
      }
    }

    // Applique les moyennes
    $avg = static function (array $a): ?int {
      return $a['cnt'] > 0 ? (int) round($a['sum'] / $a['cnt']) : null;
    };

    $attempt
      ->setNoteGlobale($avg($acc['overall_rating']))
      ->setNoteFormateur($avg($acc['trainer_rating']))
      ->setNoteSite($avg($acc['site_rating']))
      ->setNoteContenu($avg($acc['content_rating']))
      ->setNoteOrganisme($avg($acc['organism_rating']));

    // fallback globale inchangé (si aucune question n’a metric_key overall_rating)
    if ($attempt->getNoteGlobale() === null) {
      $sum = 0;
      $count = 0;

      foreach ($template->getChapters() as $chapter) {
        foreach ($chapter->getQuestions() as $q) {
          $type = $q->getType()->value;
          if ($type !== 'scale' && $type !== 'stars') continue;

          $qid = $q->getId();
          if (!$qid || !isset($answers[$qid]) || is_array($answers[$qid])) continue;
          if (!is_numeric($answers[$qid])) continue;

          $v = $this->clampByQuestion((int)$answers[$qid], $q);
          if ($v === null) continue;

          $sum += $v;
          $count++;
        }
      }

      $attempt->setNoteGlobale($count ? (int) round($sum / $count) : null);
    }
  }

  private function clampByQuestion(?int $val, SatisfactionQuestion $q): ?int
  {
    if ($val === null) return null;

    $type = $q->getType()->value;

    if ($type === 'yes_no') {
      return max(0, min(1, $val));
    }

    if ($type === 'scale' || $type === 'stars') {
      $max = $q->getMetricMax() ?? 10;
      $max = max(1, min(20, $max));
      return max(0, min($max, $val));
    }

    return $val;
  }
}
