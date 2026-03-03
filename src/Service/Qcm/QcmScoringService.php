<?php

namespace App\Service\Qcm;

use App\Entity\QcmAttempt;
use App\Entity\QcmQuestion;
use App\Enum\QcmQuestionType;

final class QcmScoringService
{
  /**
   * Règle:
   * - SINGLE : full points si la seule option choisie est correcte
   * - MULTIPLE : score partiel robuste :
   *      pointsMax * max(0, (correctChosen - wrongChosen) / totalCorrect)
   *   (capé à pointsMax)
   */
  public function computeAttemptScore(QcmAttempt $attempt): void
  {
    $qcm = $attempt->getAssignment()?->getQcm();
    if (!$qcm) return;

    $max = $qcm->getMaxPoints();
    $points = 0.0;

    // map answers by questionId
    $answersByQ = [];
    foreach ($attempt->getAnswers() as $a) {
      if ($a->getQuestion()?->getId()) $answersByQ[$a->getQuestion()->getId()] = $a;
    }

    foreach ($qcm->getQuestions() as $question) {
      /** @var QcmQuestion $question */
      $qid = $question->getId();
      if (!$qid) continue;

      $answer = $answersByQ[$qid] ?? null;
      $selected = $answer ? $answer->getSelectedOptionIds() : [];
      $correct = $question->getCorrectOptionIds();
      $pm = $question->getPointsMax();

      if ($pm <= 0) continue;

      if ($question->getType() === QcmQuestionType::SINGLE) {
        $ok = (\count($selected) === 1 && \count($correct) === 1 && $selected[0] === $correct[0]);
        $points += $ok ? $pm : 0;
        continue;
      }

      // MULTIPLE
      $totalCorrect = max(1, \count($correct));
      $correctChosen = \count(array_intersect($selected, $correct));
      $wrongChosen = \count(array_diff($selected, $correct));

      $ratio = ($correctChosen - $wrongChosen) / $totalCorrect;
      $ratio = max(0.0, min(1.0, $ratio));

      $points += $pm * $ratio;
    }

    $pointsInt = (int) round($points);
    $attempt->setMaxPoints($max);
    $attempt->setScorePoints($pointsInt);
    $attempt->setScorePercent($max > 0 ? round(($pointsInt / $max) * 100, 2) : 0.0);
  }
}
