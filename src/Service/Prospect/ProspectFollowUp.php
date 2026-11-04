<?php

namespace App\Service\Prospect;

use App\Entity\Prospect;
use App\Enum\ProspectStatus;

/** Shared rules for the daily work queue. Replies are explicitly recorded by the team. */
final class ProspectFollowUp
{
    public static function isOpen(Prospect $prospect): bool
    {
        return $prospect->isActive() && !in_array($prospect->getStatus(), [ProspectStatus::WON, ProspectStatus::LOST, ProspectStatus::CONVERTED], true);
    }

    public static function waiting(?\DateTimeInterface $sent, ?\DateTimeInterface $reply): bool
    {
        return $sent !== null && ($reply === null || $sent > $reply);
    }

    public static function date(string $value): ?\DateTimeImmutable
    {
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Choisissez une date valide.');
        }
        return $date->setTime(9, 0);
    }
}
