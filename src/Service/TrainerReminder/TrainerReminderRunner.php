<?php

declare(strict_types=1);
namespace App\Service\TrainerReminder;

use App\Entity\{ContratFormateur, ConventionContrat, Entite, Formateur, Session, UtilisateurEntite};
use App\Enum\{ContratFormateurStatus, SessionPieceType, StatusSession};
use App\Service\Automation\WorkflowTime;
use App\Service\Email\MailerManager;
use App\Service\Session\TrainerSessionFollowUp;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class TrainerReminderRunner
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TrainerSessionFollowUp $followUp,
        private readonly MailerManager $mailer, private readonly Environment $twig, private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger) {}

    /** Candidates are computed from current signatures, assignments and preferences on every run. */
    public function candidates(Entite $entite, \DateTimeImmutable $now, ?Session $onlySession = null): iterable
    {
        $settings = $entite->getPreferences()?->getTrainerReminders() ?? ReminderSettings::DEFAULTS;
        if ($settings['contractNotice']) foreach ($this->em->getRepository(ContratFormateur::class)->findBy(['entite' => $entite, 'status' => ContratFormateurStatus::ENVOYE] + ($onlySession ? ['session' => $onlySession] : [])) as $contract) {
            if ($onlySession) $this->em->refresh($contract);
            if ($contract->getStatus() !== ContratFormateurStatus::ENVOYE) continue;
            $session = $contract->getSession(); $trainer = $contract->getFormateur();
            if (!$session || !$trainer || !$this->eligible($entite, $session, $trainer) || $contract->getSignatureAt() || $contract->getSignatureDataUrl()) continue;
            $scope = 'contract:'.$contract->getId().':v'.$contract->getVersionNumero().':trainer:'.$trainer->getId();
            $history = $this->history($entite, $scope);
            if ($history && $history[0]['status'] !== 'sent') continue;
            $sequence = $history ? (int) $history[0]['sequence_number'] + 1 : 0;
            if ($sequence > 0) {
                if (!$settings['contractReminder'] || $sequence > $settings['maxReminders']) continue;
                $due = new \DateTimeImmutable($history[0]['sent_at'], new \DateTimeZone('UTC'));
                $due = $due->modify('+'.($sequence === 1 ? $settings['contractDelay'] : $settings['repeatDays']).' days');
                if ($due > $now) continue;
            }
            yield $this->message($entite, $trainer, $session, $scope, $sequence, $sequence === 0 ? 'contract_notice' : 'contract_reminder',
                ($sequence === 0 ? 'Votre contrat est prêt à signer : ' : 'Rappel de signature : ').$contract->getNumero(),
                'Le contrat '.$contract->getNumero().' attend votre signature. Consultez-le dans votre espace formateur.',
                $this->url('app_formateur_contrat_sign', ['entite' => $entite->getId(), 'contrat' => $contract->getId()]), 'Consulter et signer mon contrat');
        }
        if (!$settings['attendanceReminder'] && !$settings['satisfactionReminder']) return;
        foreach ($onlySession ? [$onlySession] : $this->em->getRepository(Session::class)->findBy(['entite' => $entite]) as $session) {
            $end = $session->getDateFin();
            if (!$end || WorkflowTime::local($end) >= $now || $this->closed($session) || $session->getStatus() === StatusSession::DRAFT) continue;
            $conventions = $this->em->getRepository(ConventionContrat::class)->findBy(['entite' => $entite, 'session' => $session]);
            foreach ($session->getFormateursEffectifs() as $trainer) {
                if (!$this->eligible($entite, $session, $trainer)) continue;
                $pending = $this->followUp->summarize($session, $trainer, $now->setTimezone(new \DateTimeZone('Europe/Paris')), $conventions);
                if (!$pending) continue;
                foreach ($session->getPieces() as $piece) {
                    if ($piece->getEntite()?->getId() === $entite->getId() && $piece->isValide()
                        && in_array($piece->getType(), [SessionPieceType::COMPTE_RENDU_STAGIAIRE, SessionPieceType::SATISFACTION_STAGIAIRE], true)) $pending['satisfaction'] = 0;
                }
                foreach (['attendance', 'satisfaction'] as $kind) {
                    if (!$settings[$kind.'Reminder'] || !$pending[$kind]) continue;
                    $scope = 'session:'.$session->getId().':trainer:'.$trainer->getId().':'.$kind;
                    $history = $this->history($entite, $scope);
                    if ($history && $history[0]['status'] !== 'sent') continue;
                    $sequence = $history ? (int) $history[0]['sequence_number'] + 1 : 1;
                    if ($sequence > $settings['maxReminders']) continue;
                    $due = WorkflowTime::local($end)->modify('+'.$settings[$kind.'Delay'].' days');
                    if ($history) $due = max($due, (new \DateTimeImmutable($history[0]['sent_at'], new \DateTimeZone('UTC')))->modify('+'.$settings['repeatDays'].' days'));
                    if ($due > $now) continue;
                    $label = $kind === 'attendance' ? 'Émargements à compléter' : 'Appréciations stagiaires à compléter';
                    $description = $kind === 'attendance'
                        ? $pending[$kind].' émargement(s) restent à renseigner. Vous pouvez également déposer les feuilles papier et renseigner les présences par demi-journée.'
                        : $pending[$kind].' appréciation(s) stagiaire(s) restent attendues. Retrouvez les accès des participants et les documents de la session dans votre espace.';
                    yield $this->message($entite, $trainer, $session, $scope, $sequence, $kind, $label.' · '.$session->getCode(), $description,
                        $this->url('app_formateur_session_show', ['entite' => $entite->getId(), 'id' => $session->getId()]), 'Compléter le suivi de la session');
                }
            }
        }
    }

    private function closed(Session $session): bool
    {
        // FULL means no seats left, not completion of the administrative follow-up.
        return in_array($session->getStatus(), [StatusSession::DONE, StatusSession::CANCELED], true);
    }

    private function eligible(Entite $entite, Session $session, Formateur $trainer): bool
    {
        if ($this->closed($session) || $session->getEntite()?->getId() !== $entite->getId()
            || $trainer->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateur($trainer)) return false;
        $user = $trainer->getUtilisateur();
        if (!$user || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) return false;
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['entite' => $entite, 'utilisateur' => $user]);
        if ($membership) $this->em->refresh($membership);
        return $membership && $membership->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR);
    }

    private function history(Entite $entite, string $scope): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT sequence_number, status, sent_at FROM trainer_reminder_delivery WHERE entite_id = ? AND scope_key = ? ORDER BY sequence_number DESC LIMIT 1', [$entite->getId(), $scope]);
    }

    private function url(string $route, array $parameters): string
    {
        return $this->urls->generate($route, $parameters + ['_locale' => 'fr'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function message(Entite $entite, Formateur $trainer, Session $session, string $scope, int $sequence, string $kind, string $subject, string $description, string $url, string $button): array
    {
        return compact('entite', 'trainer', 'session', 'scope', 'sequence', 'kind', 'subject', 'description', 'url', 'button') + ['recipient' => $trainer->getUtilisateur()->getEmail()];
    }

    /** Claim committed before transport; an ambiguous result is visible for review, never silently retried. */
    public function send(array $message, \DateTimeImmutable $now): string
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) throw new \LogicException('Les relances doivent être exécutées hors transaction.');
        $entite = $message['entite'];
        // A candidate can become stale while a worker is waiting: re-read business data before claiming.
        $this->em->refresh($entite);
        if ($entite->getPreferences()) $this->em->refresh($entite->getPreferences());
        $this->em->refresh($message['session']);
        $current = null;
        foreach ($this->candidates($entite, $now, $message['session']) as $candidate) {
            if ($candidate['scope'] === $message['scope'] && $candidate['sequence'] === $message['sequence']) { $current = $candidate; break; }
        }
        if (!$current) return 'skipped';
        $message = $current;
        $at = WorkflowTime::utc($now)->format('Y-m-d H:i:s');
        try {
            $db->insert('trainer_reminder_delivery', ['entite_id' => $entite->getId(), 'scope_key' => $message['scope'], 'sequence_number' => $message['sequence'],
                'kind' => $message['kind'], 'recipient' => $message['recipient'], 'subject' => mb_substr($message['subject'], 0, 255), 'status' => 'processing', 'created_at' => $at, 'sent_at' => null]);
        } catch (UniqueConstraintViolationException) { return 'skipped'; }
        $id = (int) $db->lastInsertId();
        try {
            $html = $this->twig->render('emails/trainer_reminder.html.twig', $message);
            $text = $entite->getNom()."\n".$message['subject']."\n".$message['description']."\n".$message['url'];
            $this->mailer->sendHtml($entite, $message['recipient'], $message['subject'], $html, $text);
            $db->update('trainer_reminder_delivery', ['status' => 'sent', 'sent_at' => $at], ['id' => $id]);
            return 'sent';
        } catch (\Throwable $error) {
            $db->update('trainer_reminder_delivery', ['status' => 'unknown'], ['id' => $id]);
            $this->logger->error('Trainer reminder outcome requires verification.', ['deliveryId' => $id, 'exception' => $error]);
            return 'unknown';
        }
    }
}
