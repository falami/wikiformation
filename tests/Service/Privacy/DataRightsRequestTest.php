<?php

declare(strict_types=1);

namespace App\Tests\Service\Privacy;

use App\Entity\Privacy\DataRightsRequest;
use App\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;

final class DataRightsRequestTest extends TestCase
{
    public function testDeadlineUsesCalendarMonthAndClampsMonthEnd(): void
    {
        foreach (['2027-01-31 14:20:00' => '2027-02-28 14:20:00', '2028-01-31 14:20:00' => '2028-02-29 14:20:00', '2026-10-27 10:30:00' => '2026-11-27 10:30:00'] as $start => $expected) {
            $request = new DataRightsRequest(new Utilisateur(), 'access', '', new \DateTimeImmutable($start, new \DateTimeZone('Europe/Paris')));
            self::assertSame($expected, $request->getDueAt()->format('Y-m-d H:i:s'));
        }
    }

    public function testAnswerRequiresOperatorAndTextAndCannotBeOverwritten(): void
    {
        $request = new DataRightsRequest(new Utilisateur(), 'erasure', 'My request');
        foreach ([['answered', 'operator', ''], ['processing', '', null], ['deleted', 'operator', null]] as $args) {
            try { $request->updateStatus(...$args); self::fail('Invalid transition accepted'); } catch (\InvalidArgumentException) { self::assertSame('pending', $request->getStatus()); }
        }
        $request->updateStatus('processing', 'privacy-team-1');
        $request->updateStatus('answered', 'privacy-team-1', 'Your request was reviewed.');
        self::assertCount(2, $request->getHistory());
        self::assertSame('Your request was reviewed.', $request->getResponse());
        self::assertNotNull($request->getAnsweredAt());
        $this->expectException(\DomainException::class);
        $request->updateStatus('answered', 'privacy-team-1', 'overwrite');
    }
}
