<?php
namespace App\Tests\Service\Document;
use App\Entity\{Entite,Inscription,Session,SessionJour,Utilisateur};
use App\Enum\{StatusSession,StatusInscription,TypeFinancement};
use App\Service\AssiduiteCalculator;
use App\Service\Document\{RealisationCertificate,SessionCertificates};
use PHPUnit\Framework\TestCase;
final class SessionCertificatesTest extends TestCase {
 public function testActualHoursEligibilityIdempotenceAndReopening(): void {
  $attendance=$this->createMock(AssiduiteCalculator::class); $attendance->method('online')->willReturn([]);
  $proof=new RealisationCertificate($attendance); $service=new SessionCertificates($proof);
  $tenant=new Entite(); $user=new Utilisateur();
  $session=(new Session())->setEntite($tenant)->setCreateur($user)->setStatus(StatusSession::PUBLISHED)->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-01-01 09:00'))->setDateFin(new \DateTimeImmutable('2025-01-01 12:00'))->setPauseMinutes(0));
  $i=(new Inscription())->setEntite($tenant)->setSession($session)->setStatus(StatusInscription::CONFIRME);
  self::assertNull($service->prepare($i));
  $session->setStatus(StatusSession::DONE); self::assertNull($service->prepare($i));
  $i->enregistrerPresenceManuelle('2025-01-01:AM','paper','Feuille signée',$user);
  $session->setTypeFinancement(TypeFinancement::OUI); self::assertNull($service->prepare($i));
  $session->setTypeFinancement(TypeFinancement::NON);
  $i->setStatus(StatusInscription::ANNULE); self::assertNull($service->prepare($i));
  $i->setStatus(StatusInscription::CONFIRME);
  $att=$service->prepare($i); self::assertNotNull($att); self::assertSame(3.0,$att->getDureeHeures());
  self::assertNull($service->prepare($i)); self::assertTrue($proof->available($i));
  $session->setStatus(StatusSession::PUBLISHED); self::assertFalse($proof->available($i));
  $session->setStatus(StatusSession::DONE); $i->enregistrerPresenceManuelle('2025-01-01:AM','absent','',$user); self::assertFalse($proof->available($i));
 }
}
