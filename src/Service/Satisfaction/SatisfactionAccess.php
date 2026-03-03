<?php
// src/Service/Satisfaction/SatisfactionAccess.php
namespace App\Service\Satisfaction;

use App\Entity\Session;

final class SatisfactionAccess
{
  /**
   * Autorise le questionnaire si on est le jour de la dernière séance (ou après),
   * et pas déjà trop tard (fenêtre configurable).
   */
  public function canFill(Session $session, int $graceDaysAfterLastDay = 7): bool
  {
    $jours = $session->getJours();
    if ($jours->isEmpty()) return false;

    $last = null;
    foreach ($jours as $j) $last = $j; // grâce à OrderBy ASC, last = dernier
    if (!$last || !$last->getDateDebut()) return false;

    $now = new \DateTimeImmutable('now');
    $start = $last->getDateDebut()->setTime(0, 0);
    $end = $last->getDateDebut()->modify('+' . $graceDaysAfterLastDay . ' days')->setTime(23, 59);

    return $now >= $start && $now <= $end;
  }
}
