<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\{AuditLog, Entite};
use Doctrine\ORM\EntityManagerInterface;

final class LearnerReminderHistory
{
    public function __construct(private EntityManagerInterface $em) {}

    public function forEntity(Entite $entite): array
    {
        $logs = $this->em->getRepository(AuditLog::class)->findBy(
            ['entite' => $entite, 'event' => 'ATTENDANCE_REMINDER_SENT'], ['createdAt' => 'ASC', 'id' => 'ASC']);
        return self::fromLogs($logs);
    }

    public static function fromLogs(iterable $logs): array
    {
        $history = [];
        foreach ($logs as $log) {
            $payload = $log->getPayload() ?? [];
            $deliveries = $payload['deliveries'] ?? null;
            // Old batch totals cannot establish which recipient actually received a reminder.
            if ($deliveries === null) {
                if (empty($payload['inscription']) || ($payload['sent'] ?? 0) !== 1 || ($payload['failed'] ?? 0) !== 0) continue;
                $kind = $payload['kind'] ?? 'attendance';
                $deliveries = [[
                    'inscription' => $payload['inscription'], 'date' => $payload['date'] ?? '',
                    'periods' => $kind === 'attendance' ? (($payload['period'] ?? null) ? [$payload['period']] : ['AM', 'PM']) : [],
                    'satisfaction' => $kind === 'satisfaction',
                    'at' => $log->getCreatedAt()->format(DATE_ATOM),
                ]];
            }
            foreach ($deliveries as $delivery) {
                $id = $delivery['inscription'];
                $at = $delivery['at'];
                if ($delivery['satisfaction']) $history[$id]['satisfaction'] = $at;
                foreach ($delivery['periods'] as $period) $history[$id]['attendance'][$delivery['date']][$period] = $at;
            }
        }
        return $history;
    }
}
