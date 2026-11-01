<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

use App\Entity\EvaluationDossier;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/** Publication is already committed: a mail failure must never discard an evaluation. */
final class EvaluationResultNotifier
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerManager $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {}

    public function notify(EvaluationDossier $dossier): bool
    {
        if (!$dossier->getId() || !$dossier->getPublishedAt()) return false;
        $lock = $this->locks->createLock('evaluation-result-mail-'.$dossier->getId(), 120);
        if (!$lock->acquire()) return false;
        try {
            $this->em->refresh($dossier);
            if ($dossier->getNotificationSentAt()) return true;
            if (!$dossier->getPublishedAt()) return false;
            $recipient = trim((string) $dossier->getStagiaire()?->getEmail());
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;
            $record = $dossier->getPublicationRecord();
            $url = $this->urls->generate('app_stagiaire_evaluation_show', [
                'entite' => $dossier->getEntite()->getId(), 'id' => $dossier->getId(), '_locale' => 'fr',
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $html = $this->twig->render('emails/evaluation_results.html.twig', [
                'record' => $record, 'url' => $url, 'successful' => $dossier->isSuccessful(),
            ]);
            $text = sprintf("Bonjour %s,\n\nVos résultats pour %s sont disponibles.\nThéorie : %s / 20 — %s.\nPratique : %s / 20 — %s.\n\nConsultez votre bilan : %s",
                $record['learnerName'], $record['title'],
                $record['scores']['theory']['on20'], $record['theoryVerdict'],
                $record['scores']['practice']['on20'], $record['practiceVerdict'], $url);
            $this->mailer->sendHtml($dossier->getEntite(), $recipient, 'Vos résultats d’évaluation · '.$record['entityName'], $html, $text);
            $dossier->setNotificationSentAt(new \DateTimeImmutable());
            $this->em->flush();
            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Evaluation result notification failed.', ['dossier' => $dossier->getId(), 'exception' => $exception]);
            return false;
        } finally {
            $lock->release();
        }
    }
}
