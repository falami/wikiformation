<?php

declare(strict_types=1);
namespace App\Service\TrainerReminder;

use App\Entity\{Entite, Formateur, Session, ContratFormateur, TrainerDiploma, UtilisateurEntite, TrainerCharter, TrainerCharterSignature, FormateurSatisfactionAssignment};
use App\Enum\{ContratFormateurStatus, StatusSession};
use App\Service\TrainerCharter\CharterManager;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/** Manual reminders deliberately independent of scheduled reminder preferences. */
final class TrainerCompliance
{
    public function __construct(private EntityManagerInterface $em, private CharterManager $charters, private UrlGeneratorInterface $urls, private MailerManager $mailer, private Environment $twig, private LoggerInterface $logger) {}

    public function pending(Entite $entite): array
    {
        $rows = []; $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $add = function(Formateur $trainer, string $key, string $kind, string $detail, string $route, array $params = []) use (&$rows, $entite) {
            $user = $trainer->getUtilisateur();
            $membership = $user ? $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['entite'=>$entite,'utilisateur'=>$user]) : null;
            $history = $this->em->getConnection()->fetchAssociative('SELECT * FROM trainer_reminder_delivery WHERE entite_id = ? AND scope_key = ? ORDER BY sequence_number DESC LIMIT 1', [$entite->getId(), 'manual:'.$key]);
            $blocked = $history && ($history['status'] !== 'sent' || new \DateTimeImmutable($history['created_at'], new \DateTimeZone('UTC')) > new \DateTimeImmutable('-24 hours'));
            $rows[$key] = ['key'=>$key,'trainer'=>$trainer,'kind'=>$kind,'detail'=>$detail,'history'=>$history,
                'available'=>!$blocked && $membership?->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR) && filter_var($user?->getEmail(), FILTER_VALIDATE_EMAIL),
                'url'=>$this->urls->generate($route, $params + ['entite'=>$entite->getId(),'_locale'=>'fr'], UrlGeneratorInterface::ABSOLUTE_URL)];
        };
        $publishedCharters = [];
        foreach ($this->em->getRepository(TrainerCharter::class)->findBy(['entite'=>$entite]) as $charter) {
            if ($version = $this->charters->latest($charter)) $publishedCharters[] = $version;
        }
        foreach ($this->em->getRepository(Formateur::class)->findBy(['entite'=>$entite]) as $trainer) {
            if (!$this->em->getRepository(TrainerDiploma::class)->count(['formateur'=>$trainer])) $add($trainer, 'diploma:'.$trainer->getId(), 'Diplômes', 'Déposez vos diplômes et justificatifs de qualification dans votre profil.', 'app_formateur_profile');
            foreach ($publishedCharters as $version) {
                $signature = $this->em->getRepository(TrainerCharterSignature::class)->findOneBy(['version'=>$version, 'trainer'=>$trainer]);
                if ($signature?->signedAt) continue;
                if ($signature) {
                    $add($trainer, 'charter:'.$signature->id, 'Charte qualité', $version->title.' · version '.$version->number, 'app_charter_sign', ['id'=>$signature->id]);
                } else {
                    $key = 'charter-request:'.$version->id.':'.$trainer->getId();
                    $add($trainer, $key, 'Charte qualité', $version->title.' · version '.$version->number.' · Signature à demander', 'app_charter_mine');
                    $rows[$key]['charterVersion'] = $version;
                }
            }
        }
        foreach ($this->em->getRepository(ContratFormateur::class)->findBy(['entite'=>$entite,'status'=>ContratFormateurStatus::ENVOYE]) as $contract) {
            $trainer = $contract->getFormateur(); $session = $contract->getSession();
            if (!$trainer || $trainer->getEntite()?->getId() !== $entite->getId() || !$session || $session->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateur($trainer) || $session->getStatus() === StatusSession::CANCELED || $contract->getSignatureAt() || $contract->getSignatureDataUrl()) continue;
            $add($trainer, 'contract:'.$contract->getId().':'.$contract->getVersionNumero(), 'Contrat', $contract->getNumero().' · '.$session->getCode(), 'app_formateur_contrat_sign', ['contrat'=>$contract->getId()]);
        }
        foreach ($this->em->getRepository(Session::class)->findBy(['entite'=>$entite]) as $session) {
            foreach ($session->getFormateursEffectifs() as $trainer) {
                if (PendingTrainerReport::isMissing($session, $trainer, $now)) {
                    $add($trainer, 'report:'.$session->getId().':'.$trainer->getId(), 'Compte rendu formateur',
                        $session->getCode().' · Compte rendu de fin de formation à compléter',
                        'app_formateur_rapport_new', ['session'=>$session->getId()]);
                }
            }

            if (in_array($session->getStatus(), [StatusSession::CANCELED, StatusSession::DRAFT], true) || !$session->isEmargementEnAttenteRequis()) continue;
            foreach ($session->getFormateursEffectifs() as $trainer) {
                $user = $trainer->getUtilisateur();
                if (!$user || $trainer->getEntite()?->getId() !== $entite->getId()) continue;
                $missing = [];
                foreach ($session->getJours() as $slot) {
                    $start=$slot->getDateDebut(); $end=$slot->getDateFin();
                    if (!$start || !$end || $end <= $start || $end > $now) continue;
                    foreach (['AM','PM'] as $half) {
                        if (($half === 'AM' && $start->format('H:i') >= '13:00') || ($half === 'PM' && $end->format('H:i') <= '13:00') || !$session->isFormateurUtilisateurSurPeriode($user,$start,$half)) continue;
                        $missing[$start->format('Y-m-d').':'.$half] = true;
                    }
                }
                foreach ($session->getEmargements() as $sign) if ($sign->getEntite()?->getId() === $entite->getId() && $sign->getUtilisateur()?->getId() === $user->getId() && in_array($sign->getRole(), ['trainer','formateur'], true) && ($sign->getSignaturePath() || $sign->getSignatureDataUrl())) unset($missing[$sign->getDateJour()->format('Y-m-d').':'.$sign->getPeriode()->value]);
                if ($missing) $add($trainer, 'attendance:'.$session->getId().':'.$trainer->getId(), 'Émargements', $session->getCode().' · '.count($missing).' demi-journée(s) à signer', 'app_formateur_session_show', ['id'=>$session->getId()]);
            }
        }
        foreach ($this->em->getRepository(FormateurSatisfactionAssignment::class)->findBy(['entite'=>$entite]) as $assignment) {
            $session = $assignment->getSession();
            $trainer = $assignment->getFormateur()?->getFormateur($entite);
            if (!$session || !$trainer || $session->getEntite()?->getId() !== $entite->getId()
                || $trainer->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateur($trainer)
                || !$assignment->isRequired() || $assignment->getAttempt()?->isSubmitted()
                || in_array($session->getStatus(), [StatusSession::CANCELED, StatusSession::DRAFT], true)
                || !$session->getDateFin() || $session->getDateFin() > $now) continue;
            $key = 'trainer-questionnaire:'.$assignment->getId().':'.$assignment->getFormateur()->getId();
            $closed = !(new \App\Service\Satisfaction\SatisfactionAccess())->canFillTrainer($assignment);
            $add($trainer, $key, 'Appréciation formateur', $session->getCode().' · Questionnaire à compléter'
                .($closed ? ' · La relance rouvrira la saisie pour 7 jours.' : ''),
                'app_formateur_satisfaction_fill', ['session'=>$session->getId()]);
            $rows[$key]['questionnaire'] = $assignment;
            $rows[$key]['reopens'] = $closed;
        }
        return $rows;
    }

    public function send(Entite $entite, string $key, ?string &$sentAt = null): string
    {
        $sentAt = null;
        $row = $this->pending($entite)[$key] ?? null;
        if (!$row || !$row['available']) return 'skipped';
        if (isset($row['charterVersion'])) {
            $signature = new TrainerCharterSignature();
            $signature->version = $row['charterVersion'];
            $signature->trainer = $row['trainer'];
            $signature->requestedBy = 'Tableau de bord — demande de signature';
            $this->em->persist($signature);
            try { $this->em->flush(); } catch (UniqueConstraintViolationException) { return 'skipped'; }
            // Use the same history key as requests created from the charter tracking page.
            $key = 'charter:'.$signature->id;
            $row['url'] = $this->urls->generate('app_charter_sign', ['entite'=>$entite->getId(), '_locale'=>'fr', 'id'=>$signature->id], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $db=$this->em->getConnection();
        if ($db->isTransactionActive()) throw new \LogicException('Envoi hors transaction requis.');
        $sequence = $row['history'] ? (int)$row['history']['sequence_number']+1 : 1;
        $at = gmdate('Y-m-d H:i:s'); $subject = $row['kind'].' à compléter · '.$entite->getNom();
        try {
            $db->insert('trainer_reminder_delivery', ['entite_id'=>$entite->getId(),'scope_key'=>'manual:'.$key,'sequence_number'=>$sequence,'kind'=>'manual','recipient'=>$row['trainer']->getUtilisateur()->getEmail(),'subject'=>mb_substr($subject,0,255),'status'=>'processing','created_at'=>$at,'sent_at'=>null]);
        } catch (UniqueConstraintViolationException) { return 'skipped'; }
        $id=$db->lastInsertId();
        try {
            if (isset($row['questionnaire'])) {
                $assignment = $row['questionnaire'];
                $this->em->refresh($assignment);
                if ($assignment->getAttempt()?->isSubmitted()
                    || $assignment->getFormateur()?->getId() !== $row['trainer']->getUtilisateur()?->getId()) {
                    $db->update('trainer_reminder_delivery', ['status'=>'skipped'], ['id'=>$id]);
                    return 'skipped';
                }
                $assignment->reopenUntil(new \DateTimeImmutable('+7 days', new \DateTimeZone('Europe/Paris')));
                $this->em->flush();
                $row['detail'] .= ' Réponse possible jusqu’au '.$assignment->getReopenedUntil()->format('d/m/Y à H:i').'.';
            }
            $qr = (new \Endroid\QrCode\Writer\PngWriter())->write(new \Endroid\QrCode\QrCode(data: $row['url'], size: 300, margin: 16))->getString();
            $html=$this->twig->render('emails/trainer_compliance.html.twig', $row+['entite'=>$entite,'subject'=>$subject,'qrCid'=>'trainer-compliance-qr']);
            $this->mailer->sendParticipantReminder($entite,$row['trainer']->getUtilisateur()->getEmail(),$subject,$html,$subject."\n".$row['detail']."\nConnectez-vous à votre espace formateur : ".$row['url'], ['trainer-compliance-qr'=>$qr]);
            $db->update('trainer_reminder_delivery',['status'=>'sent','sent_at'=>$at],['id'=>$id]);
            $sentAt = (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(DATE_ATOM);
            return 'sent';
        } catch (\Throwable $e) {
            $db->update('trainer_reminder_delivery',['status'=>'unknown'],['id'=>$id]);
            $this->logger->error('Relance formateur à vérifier avant tout nouvel envoi.', ['deliveryId'=>$id,'exception'=>$e]);
            return 'unknown';
        }
    }
}
