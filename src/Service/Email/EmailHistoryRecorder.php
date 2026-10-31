<?php

namespace App\Service\Email;

use App\Entity\{EmailLog, Entite, Utilisateur};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mime\Email;

/** Keeps the rendered message used for delivery, except account security messages. */
final class EmailHistoryRecorder
{
    public function __construct(private EntityManagerInterface $em, private Security $security) {}

    public function prepare(Entite $entite, Email $email, bool $archiveBody = true): ?EmailLog
    {
        $recipient = $email->getTo()[0]->getAddress() ?? null;
        if (!$recipient) return null;
        $subject = mb_substr($email->getSubject() ?? '', 0, 200);
        // Some business flows already prepare a richer log before calling the mailer.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof EmailLog && $pending->getEntite() === $entite
                && $pending->getToEmail() === $recipient && $pending->getSubject() === $subject
                && $pending->getStatus() === 'PENDING') {
                $this->snapshot($pending, $email, $archiveBody);
                return $pending;
            }
        }
        $actor = $this->security->getUser();
        $creator = $actor instanceof Utilisateur ? $actor : $entite->getCreateur();
        if (!$creator) throw new \LogicException('Un créateur est nécessaire pour journaliser le mail.');
        $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $recipient]);
        $log = (new EmailLog())->setEntite($entite)->setCreateur($creator)
            ->setActor($actor instanceof Utilisateur ? $actor : null)
            ->setToUser($user)->setToEmail($recipient)->setSubject($subject)->setStatus('PENDING');
        $this->snapshot($log, $email, $archiveBody);
        $this->em->persist($log);
        return $log;
    }

    private function snapshot(EmailLog $log, Email $email, bool $archiveBody): void
    {
        if (!$archiveBody) {
            $log->setBodyHtmlSnapshot(null);
            return;
        }
        $html = $email->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $log->setBodyHtmlSnapshot($html);
        } elseif (is_string($text = $email->getTextBody()) && $text !== '') {
            $log->setBodyHtmlSnapshot('<pre style="white-space:pre-wrap">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>');
        }
    }

    public function finish(?EmailLog $log, string $status): void
    {
        if (!$log) return;
        $log->setStatus($status)->setSentAt(new \DateTimeImmutable());
        if ($status === 'FAILED') $log->setErrorMessage('Le transport de messagerie a refusé l’envoi.');
        $this->em->flush();
    }
}
