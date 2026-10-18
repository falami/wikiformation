<?php

declare(strict_types=1);
namespace App\Tests\Service\Session;

use App\Entity\AuditLog;
use App\Service\Session\LearnerReminderHistory;
use PHPUnit\Framework\TestCase;

final class LearnerReminderHistoryTest extends TestCase
{
    private function log(array $payload): AuditLog
    {
        return (new AuditLog())->setPayload($payload)->setCreatedAt(new \DateTimeImmutable('2026-10-18T10:00:00+02:00'));
    }

    public function testSuccessfulDeliveriesAreScopedToRecipientPurposeAndHalfDay(): void
    {
        $first = ['inscription'=>1, 'date'=>'2026-10-15', 'periods'=>['AM'], 'satisfaction'=>false, 'at'=>'2026-10-17T10:00:00+02:00'];
        $second = ['inscription'=>1, 'date'=>'2026-10-15', 'periods'=>[], 'satisfaction'=>true, 'at'=>'2026-10-18T10:00:00+02:00'];
        $history = LearnerReminderHistory::fromLogs([
            $this->log(['deliveries'=>[$first]]), $this->log(['deliveries'=>[$second]]),
            $this->log(['inscription'=>2, 'sent'=>0, 'failed'=>1, 'deliveries'=>[]]),
        ]);
        self::assertSame($first['at'], $history[1]['attendance']['2026-10-15']['AM']);
        self::assertArrayNotHasKey('PM', $history[1]['attendance']['2026-10-15']);
        self::assertSame($second['at'], $history[1]['satisfaction']);
        self::assertArrayNotHasKey(2, $history);
    }

    public function testLegacySingleSuccessIsRecoveredButAmbiguousBatchesAreNot(): void
    {
        $history = LearnerReminderHistory::fromLogs([
            $this->log(['inscription'=>1, 'sent'=>1, 'failed'=>0, 'kind'=>'satisfaction']),
            $this->log(['inscriptions'=>[2,3], 'sent'=>1, 'failed'=>1, 'kind'=>'satisfaction']),
            $this->log(['inscription'=>4, 'sent'=>0, 'failed'=>1, 'kind'=>'satisfaction']),
        ]);
        self::assertSame([1], array_keys($history));
        self::assertSame('2026-10-18T10:00:00+02:00', $history[1]['satisfaction']);
    }
}
