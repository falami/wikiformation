<?php
namespace App\Tests\Service;
use App\Service\TrainerProfile\CalendarSync;
use PHPUnit\Framework\TestCase;
final class TrainerCalendarTest extends TestCase {
    public function testRecurrenceExceptionAndPrivacy(): void {
        $service=new CalendarSync('test');
        $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTART;TZID=Europe/Paris:20261012T090000\r\nDTEND;TZID=Europe/Paris:20261012T120000\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEXDATE;TZID=Europe/Paris:20261013T090000\r\nSUMMARY:Private appointment\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $busy=$service->parse($ics,new \DateTimeImmutable('2026-10-01'),new \DateTimeImmutable('2026-11-01'));
        self::assertCount(2,$busy);self::assertStringContainsString('2026-10-14',$busy[1]['start']);self::assertSame(['start','end'],array_keys($busy[0]));
    }
    public function testAllDayDurationAndTransparent():void {
        $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261012\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:b\r\nDTSTART:20261013T090000Z\r\nDURATION:PT2H\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:c\r\nDTSTART:20261014T090000Z\r\nDTEND:20261014T120000Z\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $busy=(new CalendarSync('test'))->parse($ics,new \DateTimeImmutable('2026-10-01'),new \DateTimeImmutable('2026-11-01'));
        self::assertCount(2,$busy); self::assertSame(86400,strtotime($busy[0]['end'])-strtotime($busy[0]['start']));self::assertSame(7200,strtotime($busy[1]['end'])-strtotime($busy[1]['start']));
    }
    public function testSecretEncryptedAndUnsafeHostRejected():void {
        $service=new CalendarSync('test');$url='https://calendar.google.com/calendar/ical/secret/basic.ics';
        self::assertStringNotContainsString('secret',$service->encrypt($url));
        $this->expectException(\InvalidArgumentException::class);$service->encrypt('https://calendar.google.com.evil.test/basic.ics');
    }
}
