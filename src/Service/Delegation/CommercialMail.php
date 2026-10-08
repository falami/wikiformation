<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{Entreprise, Prospect, Facture, Devis, EmailLog, Utilisateur, CommercialActivity};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\{Email, Address};
use App\Service\Pdf\PdfManager;
use Twig\Environment;
final class CommercialMail
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly MailerInterface $mailer, private readonly PdfManager $pdf, private readonly Environment $twig)
    {
    }
    public function recipient(object $record): ?string
    {
        return match (true) {
            $record instanceof Entreprise => $record->getEmail() ?: $record->getEmailFacturation(),
            $record instanceof Prospect => $record->getEmail(),
            $record instanceof Facture, $record instanceof Devis => ($record->getEntrepriseDestinataire()?->getEmailFacturation() ?: $record->getEntrepriseDestinataire()?->getEmail()) ?: $record->getDestinataire()?->getEmail(),
            default => null,
        };
    }
    public function send(object $record, string $module, Utilisateur $actor, string $subject, string $body, string $key): bool
    {
        $recipient = $this->recipient($record);
        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Renseignez une adresse e-mail valide dans le dossier avant l’envoi.');
        }
        if ($record instanceof Facture && $record->getStatus() === \App\Enum\FactureStatus::CANCELED) {
            throw new \DomainException('Une facture annulée ne peut pas être envoyée.');
        }
        $idem = 'commercial:' . hash('sha256', $actor->getId() . '|' . $module . '|' . $record->getId() . '|' . $key);
        $previous = $this->em->getRepository(EmailLog::class)->findOneBy(['idemKey' => $idem]);
        if ($previous && $previous->getStatus() !== 'FAILED') {
            return false;
        }
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $log = ($previous ?? new EmailLog())->setEntite($record->getEntite())->setCreateur($actor)->setActor($actor)->setToEmail($recipient)->setSubject($subject)->setBodyHtmlSnapshot($html)->setStatus('PENDING')->setSentAt(new \DateTimeImmutable())->setIdemKey($idem);
        if ($record instanceof Facture) {
            $log->setFacture($record);
        }
        if ($record instanceof Devis) {
            $log->setDevis($record);
        }
        if ($record instanceof Prospect) {
            $log->setProspect($record);
        }
        $email = (new Email())->from(new Address('contact@wikiformation.fr', $record->getEntite()->getNom()))->to($recipient)->replyTo($actor->getEmail())->subject($subject)->text($body)->html($html);
        if ($record instanceof Facture || $record instanceof Devis) {
            $type = $record instanceof Facture ? 'facture' : 'devis';
            $pdf = $this->pdf->createPortraitBytes($this->twig->render('pdf/' . $type . '.html.twig', ['entite' => $record->getEntite(), $type => $record]));
            $email->attach($pdf, $type . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $record->getNumero()) . '.pdf', 'application/pdf');
        }
        $this->em->persist($log);
        $this->em->flush();
        try {
            $this->mailer->send($email);
            $log->setStatus('SENT');
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $error) {
            $log->setStatus('FAILED')->setErrorMessage(mb_substr($error->getMessage(), 0, 1000));
            $this->em->flush();
            throw new \DomainException('L’envoi a échoué. Vérifiez la configuration e-mail de l’organisme puis réessayez.');
        }
        if ($record instanceof Devis && $record->getStatus() === \App\Enum\DevisStatus::DRAFT) {
            $record->setStatus(\App\Enum\DevisStatus::SENT);
        }
        $this->em->persist((new CommercialActivity())->setEntite($record->getEntite())->setAuthor($actor)->setModule($module)->setRecordId($record->getId())->setTitle($subject)->setContent('À : ' . $recipient . "\n\n" . $body)->setKind('email'));
        $this->em->flush();
        return true;
    }
}
