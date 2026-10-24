<?php
namespace App\Tests\Unit;
use App\Entity\{Session,SessionJour,Site};
use App\Enum\StatusSession;
use App\Service\Session\SessionStartReminder;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\{Environment,Loader\ArrayLoader};
final class SessionStartReminderTest extends TestCase {
 public function testOnlyOptedInPublishedSessionsOnPreviousLocalDayAfterNine(): void {
  $twig=new Environment(new ArrayLoader());
  $runner=new SessionStartReminder($this->createMock(EntityManagerInterface::class),new MailerManager($this->createMock(MailerInterface::class),$twig,$this->createMock(UrlGeneratorInterface::class)),$twig);
  $session=(new Session())->setSite((new Site())->setTimezone('Europe/Paris'))->setStatus(StatusSession::PUBLISHED);
  $day=(new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-26 09:00 Europe/Paris'))->setDateFin(new \DateTimeImmutable('2026-10-26 17:00 Europe/Paris'));
  $session->addJour($day);
  $now=new \DateTimeImmutable('2026-10-25 09:00 Europe/Paris');
  self::assertSame([],$runner->schedule($session,$now));
  $session->setStartReminderEnabled(true);
  self::assertSame([$day],$runner->schedule($session,$now));
  self::assertSame([],$runner->schedule($session,$now->modify('-1 minute')));
  self::assertSame([],$runner->schedule($session,$now->modify('+1 day')));
  self::assertSame([],$runner->schedule($session,$now->modify('-1 day')));
  foreach ([StatusSession::DRAFT,StatusSession::CANCELED,StatusSession::DONE,StatusSession::ON_HOLD] as $status) {
   $session->setStatus($status); self::assertSame([],$runner->schedule($session,$now));
  }
  $session->setStatus(StatusSession::FULL); self::assertSame([$day],$runner->schedule($session,$now));
 }
}
