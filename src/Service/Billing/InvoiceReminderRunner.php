<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Facture;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class InvoiceReminderRunner
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly MailerManager $mailer, private readonly \Twig\Environment $twig) {}

    public static function recipient(Facture $invoice): ?string
    {
        $email = $invoice->getEntrepriseDestinataire()?->getEmailFacturation() ?: $invoice->getDestinataire()?->getEmail();
        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function canSendManually(Facture $invoice, \DateTimeImmutable $now): bool
    {
        if (!self::recipient($invoice) || InvoiceTotals::outstanding($invoice) <= 0) return false;
        foreach ([$invoice->getMeta()['follow_up_delivery'] ?? [], $invoice->getMeta()['workflow_deliveries']['reminder'] ?? []] as $delivery) {
            if (in_array($delivery['status'] ?? '', ['processing', 'unknown', 'retry_authorized'], true)) return false;
            if (!empty($delivery['sentAt']) && new \DateTimeImmutable($delivery['sentAt']) > $now->modify('-24 hours')) return false;
        }
        return true;
    }

    public function send(Facture $invoice, \DateTimeImmutable $now, bool $manual = false): string
    {
        $claim = bin2hex(random_bytes(16));
        $message = $this->em->wrapInTransaction(function () use ($invoice, $now, $claim, $manual) {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $date = InvoiceFollowUp::reminderDate($invoice);
            $to = self::recipient($invoice);
            if (!self::canSendManually($invoice, $now) || (!$manual && (!$date || $date > $now))) return null;
            $remaining = InvoiceTotals::outstanding($invoice);
            $meta = $invoice->getMeta() ?? [];
            $meta['follow_up_delivery'] = ['status' => 'processing', 'claim' => $claim, 'claimedAt' => $now->format(DATE_ATOM), 'to' => $to, 'remainingCents' => $remaining, 'sentAt' => $meta['follow_up_delivery']['sentAt'] ?? null];
            $invoice->setMeta($meta);
            return ['to' => $to, 'remaining' => $remaining];
        });
        if (!$message) return 'ignored';
        $text = sprintf("Bonjour,\n\nSauf erreur de notre part, la facture %s émise le %s présente un solde restant dû de %s %s.\nMerci de procéder au règlement selon les modalités de votre facture. Si votre paiement est déjà en cours, merci de nous le signaler.\n\nCordialement,\n%s", $invoice->getNumero(), $invoice->getDateEmission()->format('d/m/Y'), number_format($message['remaining'] / 100, 2, ',', ' '), $invoice->getDevise(), $invoice->getEntite()->getNom());
        try {
            $this->mailer->sendHtml($invoice->getEntite(), $message['to'], 'Rappel de règlement — '.$invoice->getNumero(), $this->twig->render('emails/invoice_reminder.html.twig', ['invoice' => $invoice, 'remaining' => $message['remaining'], 'entite' => $invoice->getEntite()]), $text);
            $status = 'sent';
        } catch (\Throwable) {
            // A transport failure may occur after delivery: never blindly retry.
            $status = 'unknown';
        }
        $this->em->wrapInTransaction(function () use ($invoice, $claim, $status, $now) {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $meta = $invoice->getMeta() ?? [];
            if (($meta['follow_up_delivery']['claim'] ?? '') !== $claim) throw new \RuntimeException('La réservation de relance a changé.');
            $meta['follow_up_delivery']['status'] = $status;
            if ($status === 'sent') $meta['follow_up_delivery']['sentAt'] = $now->format(DATE_ATOM);
            $invoice->setMeta($meta);
        });
        return $status;
    }
}
