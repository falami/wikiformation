<?php
namespace App\Service\Session;
use App\Entity\{Session, SessionStartReminderDelivery};
use App\Enum\{StatusSession, StatusInscription};
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;
final class SessionStartReminder
{
 public function __construct(private EntityManagerInterface $em, private MailerManager $mailer, private Environment $twig) {}
 public function schedule(Session $session, \DateTimeImmutable $now): array {
  if (!$session->isStartReminderEnabled() || !in_array($session->getStatus(), [StatusSession::PUBLISHED, StatusSession::FULL], true)) return [];
  $zone = new \DateTimeZone($session->getSite()?->getTimezone() ?: 'Europe/Paris');
  $days = $session->getJours()->toArray();
  usort($days, fn($a,$b) => $a->getDateDebut() <=> $b->getDateDebut());
  if (!$days || !$days[0]->getDateDebut()) return [];
  foreach ($days as $day) if (!$day->getDateDebut() || !$day->getDateFin() || $day->getDateFin() <= $day->getDateDebut()) return [];
  $start = \DateTimeImmutable::createFromInterface($days[0]->getDateDebut())->setTimezone($zone);
  $local = $now->setTimezone($zone);
  if ($start->modify('-1 day')->format('Y-m-d') !== $local->format('Y-m-d') || (int)$local->format('H') < 9) return [];
  return $days;
 }
 public function run(\DateTimeImmutable $now, bool $execute): array {
  $result = ['eligible'=>0,'sent'=>0,'uncertain'=>0];
  foreach ($this->em->getRepository(Session::class)->findBy(['startReminderEnabled'=>true]) as $session) {
   $days = $this->schedule($session, $now); if (!$days) continue;
   foreach ($session->getInscriptions() as $inscription) {
    $user = $inscription->getStagiaire();
    if (!$user || $inscription->getEntite()->getId() !== $session->getEntite()->getId() || !in_array($inscription->getStatus(), [StatusInscription::CONFIRME, StatusInscription::EN_COURS], true) || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) continue;
    $key = $session->getId().':'.$user->getId().':'.$days[0]->getDateDebut()->format('Y-m-d');
    if ($this->em->find(SessionStartReminderDelivery::class, $key)) continue;
    ++$result['eligible']; if (!$execute) continue;
    $context = ['session'=>$session,'user'=>$user,'days'=>$days,'timezone'=>$session->getSite()?->getTimezone() ?: 'Europe/Paris'];
    $html = $this->twig->render('emails/session_start_reminder.html.twig', $context);
    $text = $this->twig->render('emails/session_start_reminder.txt.twig', $context);
    // Claim before transport: an ambiguous transport outcome must never cause an automatic duplicate.
    $delivery = new SessionStartReminderDelivery($key);
    $this->em->persist($delivery); $this->em->flush();
    try {
     $this->mailer->sendParticipantReminder($session->getEntite(), $user->getEmail(), 'Votre formation commence demain · '.$session->getFormationLabel(), $html, $text, []);
     $delivery->status = 'sent'; ++$result['sent'];
    } catch (\Throwable $e) { $delivery->status = 'uncertain'; ++$result['uncertain']; }
    $this->em->flush();
   }
  }
  return $result;
 }
}
