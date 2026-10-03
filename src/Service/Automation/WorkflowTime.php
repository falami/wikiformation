<?php

declare(strict_types=1);

namespace App\Service\Automation;

/** Sessions use French wall-clock DATETIME values; workflow execution timestamps use UTC. */
final class WorkflowTime
{
    public static function local(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d H:i:s.u'), new \DateTimeZone('Europe/Paris'));
    }

    public static function utc(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris'));
    }
}
