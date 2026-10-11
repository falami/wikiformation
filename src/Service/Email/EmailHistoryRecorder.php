<?php

namespace App\Service\Email;

use App\Entity\{EmailLog, Entite, Utilisateur};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mime\Email;

/** Records metadata only: passwords, signature tokens and QR codes never enter this journal. */
final class EmailHistoryRecorder
{
    public function __construct(private EntityManagerInterface $em, private Security $security) {}

    public function prepare(Entite $entite, Email $email): ?EmailLog
    {
        $recipient = $email->getTo()[0]->getAddress() ?? null;
        if (!$recipient) return null;
        $subject = mb_substr($email->getSubject() ?? '', 0, 200);
        // Some business flows already prepare a richer log before calling the mailer.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof EmailLog && $pending->getEntite() === $entite
                && $pending->getToEmail() === $recipient && $pending->getSubject() === $subject
                && $pending->getStatus() === 'PENDING') return $pending;
        }
        $actor = $this->security->getUser();
        $creator = $actor instanceof Utilisateur ? $actor : $entite->getCreateur();
        if (!$creator) throw new \LogicException('Un créateur est nécessaire pour journaliser le mail.');
        $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $recipient]);
        $log = (new EmailLog())->setEntite($entite)->setCreateur($creator)
            ->setActor($actor instanceof Utilisateur ? $actor : null)
            ->setToUser($user)->setToEmail($recipient)->setSubject($subject)->setStatus('PENDING');
        $this->em->persist($log);
        return $log;
    }

    public function finish(?EmailLog $log, string $status): void
    {
        if (!$log) return;
        $log->setStatus($status)->setSentAt(new \DateTimeImmutable());
        if ($status === 'FAILED') $log->setErrorMessage('Le transport de messagerie a refusé l’envoi.');
        $this->em->flush();
    }
}
