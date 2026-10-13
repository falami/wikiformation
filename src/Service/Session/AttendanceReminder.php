<?php
declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\{Session, Inscription, Emargement, SatisfactionAssignment};
use App\Enum\{StatusInscription, StatusSession};
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\{QrCode, Writer\PngWriter};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Environment;

final class AttendanceReminder
{
    public function __construct(private EntityManagerInterface $em, private ParticipantQrAccess $access,
        private UrlGeneratorInterface $router, private MailerManager $mailer, private Environment $twig) {}

    public function send(Session $session, string $date, ?int $inscriptionId = null, string $kind = 'attendance', ?string $period = null, ?array &$skipReasons = null, ?array $selectedIds = null): array
    {
        if ($selectedIds !== null) {
            $allowed=array_map(fn($i)=>$i->getId(),$this->em->getRepository(Inscription::class)->findBy(['session'=>$session,'entite'=>$session->getEntite()]));
            if (!$selectedIds || array_diff($selectedIds,$allowed)) throw new \DomainException('Sélection vide ou étrangère à cette session.');
        }
        if (!in_array($kind, ['attendance', 'satisfaction', 'both'], true)) throw new \DomainException('Type de relance invalide.');
        if ($inscriptionId !== null && !$this->em->getRepository(Inscription::class)->findOneBy(['id' => $inscriptionId, 'session' => $session, 'entite' => $session->getEntite()])) throw new \DomainException('Stagiaire étranger à cette session.');
        if ($period !== null && !in_array($period, ['AM', 'PM'], true)) throw new \DomainException('Demi-journée invalide.');
        $periods = [];
        foreach ($session->getJours() as $jour) {
            $start = $jour->getDateDebut(); $end = $jour->getDateFin();
            if (!$start || !$end || $start->format('Y-m-d') !== $date || $end <= $start) continue;
            if ($start->format('H:i') < '13:00') $periods['AM'] = 'Matin';
            if ($end->format('H:i') > '13:00') $periods['PM'] = 'Après-midi';
        }
        if ($period !== null) $periods = array_intersect_key($periods, [$period => true]);
        if ($kind !== 'satisfaction' && (!$periods || $date > (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'))) {
            throw new \DomainException('Choisissez une journée planifiée, passée ou aujourd’hui.');
        }
        if (($kind === 'attendance' && !$session->isEmargementEnAttenteRequis()) || $session->getStatus() === StatusSession::CANCELED) {
            throw new \DomainException('Les relances sont indisponibles pour cette session : vérifiez le suivi des émargements.');
        }
        $skipReasons = ['complete'=>0, 'email'=>0, 'access'=>0, 'inactive'=>0];
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $seen = [];
        foreach ($this->em->getRepository(Inscription::class)->findBy(['session' => $session, 'entite' => $session->getEntite()]) as $i) {
            if ($inscriptionId !== null && $i->getId() !== $inscriptionId) continue;
            if ($selectedIds !== null && !in_array($i->getId(),$selectedIds,true)) continue;
            $user = $i->getStagiaire();
            if (!$user || in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) { ++$result['skipped']; ++$skipReasons['inactive']; continue; }
            if (isset($seen[$user->getId()])) continue;
            $seen[$user->getId()] = true;
            $missing = $kind !== 'satisfaction' && $session->isEmargementEnAttenteRequis() ? $periods : [];
            foreach ($i->getPresencesManuelles() as $key => $record) {
                foreach (array_keys($missing) as $half) if ($key === $date.':'.$half && in_array($record['status'] ?? '', ['paper', 'absent'], true)) unset($missing[$half]);
            }
            foreach ($this->em->getRepository(Emargement::class)->findBy(['session' => $session, 'entite' => $session->getEntite(), 'utilisateur' => $user, 'role' => 'stagiaire']) as $e) {
                if ($e->getDateJour()?->format('Y-m-d') === $date && $e->getSignedAt() && ($e->getSignaturePath() || $e->getSignatureDataUrl())) unset($missing[$e->getPeriode()->value]);
            }
            $needsSatisfaction = $kind !== 'attendance';
            foreach ($this->em->getRepository(SatisfactionAssignment::class)->findBy(['session' => $session, 'entite' => $session->getEntite(), 'stagiaire' => $user]) as $assignment) {
                if ($assignment->getAttempt()?->isSubmitted()) $needsSatisfaction = false;
            }
            if (!$missing && !$needsSatisfaction) { ++$result['skipped']; ++$skipReasons['complete']; continue; }
            if (!filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) { ++$result['skipped']; ++$skipReasons['email']; continue; }
            $participant = $this->access->forInscription($i);
            $this->em->flush();
            $links = []; $images = [];
            foreach (['attendance' => (bool) $missing, 'satisfaction' => $needsSatisfaction] as $purpose => $needed) {
                if (!$needed) continue;
                $token = $purpose === 'satisfaction' ? $this->access->satisfactionReminderToken($participant) : $this->access->token($participant, $purpose);
                if (!$this->access->resolve($token, $purpose)) continue;
                $params = ['token' => $token];
                if ($purpose === 'attendance') $params['date'] = $date;
                $url = $this->router->generate('app_participant_qr_'.$purpose, $params, UrlGeneratorInterface::ABSOLUTE_URL);
                $cid = $purpose === 'attendance' ? 'attendance-qr' : 'satisfaction-qr';
                $label = $purpose === 'attendance' ? 'Émargement du '.(new \DateTimeImmutable($date))->format('d/m/Y').' · '.implode(', ', $missing) : 'Votre appréciation de la formation';
                $links[] = ['url' => $url, 'cid' => $cid, 'label' => $label];
                $images[$cid] = (new PngWriter())->write(new QrCode(data: $url, size: 360, margin: 20))->getString();
            }
            if (!$links) { ++$result['skipped']; ++$skipReasons['access']; continue; }
            $html = $this->twig->render('emails/attendance_reminder.html.twig', ['session' => $session, 'user' => $user, 'links' => $links]);
            $text = 'Bonjour '.$user->getPrenom().",\nMerci de compléter les éléments suivants pour ".$session->getCode().".\n";
            foreach ($links as $link) $text .= $link['label'].": ".$link['url']."\n";
            $text .= 'Ces liens sont personnels. Ne confirmez que vos présences effectives.';
            try {
                $this->mailer->sendParticipantReminder($session->getEntite(), $user->getEmail(), 'Votre formation · éléments à compléter · '.$session->getCode(), $html, $text, $images);
                ++$result['sent'];
            } catch (TransportExceptionInterface) { ++$result['failed']; }
        }
        return $result;
    }
}
