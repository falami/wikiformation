<?php

declare(strict_types=1);
namespace App\Service\TrainerReminder;

final class ReminderSettings
{
    public const DEFAULTS = [
        'contractNotice' => false, 'contractReminder' => false, 'contractDelay' => 3,
        'attendanceReminder' => false, 'attendanceDelay' => 2,
        'satisfactionReminder' => false, 'satisfactionDelay' => 3,
        'repeatDays' => 7, 'maxReminders' => 3,
    ];
}
