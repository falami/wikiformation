<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\{Facture, Entite};
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceFollowUp
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function settings(Facture $invoice): array
    {
        return array_replace(['renewalMonths' => 0, 'reminderMonths' => 0], $invoice->getMeta()['follow_up'] ?? []);
    }

    // Clamp to the last day of the target month, always using the original issue date.
    public static function anniversary(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $target = $date->modify('first day of this month')->modify('+'.$months.' months');
        return $target->setDate((int)$target->format('Y'), (int)$target->format('m'), min((int)$date->format('d'), (int)$target->format('t')))->setTime(0, 0);
    }

    public static function renewalDate(Facture $invoice): ?\DateTimeImmutable
    {
        $settings = self::settings($invoice);
        if ($invoice->isCanceled() || (int)$settings['renewalMonths'] === 0) return null;
        return self::anniversary($invoice->getDateEmission(), (int)$settings['renewalMonths'] * (1 + (int)($settings['renewalsHandled'] ?? 0)));
    }

    public static function reminderDate(Facture $invoice): ?\DateTimeImmutable
    {
        $settings = self::settings($invoice);
        if (!(int)$settings['reminderMonths'] || InvoiceTotals::outstanding($invoice) === 0) return null;
        $delivery = $invoice->getMeta()['follow_up_delivery'] ?? [];
        if (in_array($delivery['status'] ?? '', ['processing', 'unknown'], true)) return null;
        $workflow = $invoice->getMeta()['workflow_deliveries']['reminder'] ?? [];
        if (in_array($workflow['status'] ?? '', ['processing', 'unknown', 'retry_authorized'], true)) return null;
        $date = self::anniversary($invoice->getDateEmission(), (int)$settings['reminderMonths']);
        foreach ([$delivery['sentAt'] ?? null, $workflow['sentAt'] ?? null] as $sent) {
            if ($sent) $date = max($date, self::anniversary(new \DateTimeImmutable($sent), (int)$settings['reminderMonths']));
        }
        $due = $invoice->getMeta()['invoice_terms']['dueDate'] ?? null;
        $dueDate = $due ? new \DateTimeImmutable($due) : $invoice->getDateEmission()->modify('+30 days');
        return max($date, $dueDate->modify('+1 day')->setTime(0, 0));
    }

    public function rows(Entite $entite, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $rows = ['unpaid' => [], 'renewals' => []];
        foreach ($this->em->getRepository(Facture::class)->findBy(['entite' => $entite], ['dateEmission' => 'ASC']) as $invoice) {
            $remaining = InvoiceTotals::outstanding($invoice);
            if ($remaining > 0) $rows['unpaid'][] = ['invoice' => $invoice, 'remaining' => $remaining, 'reminderDate' => self::reminderDate($invoice), 'canRemind' => InvoiceReminderRunner::canSendManually($invoice, new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))];
            $date = self::renewalDate($invoice);
            if ($date && $date <= $today) $rows['renewals'][] = ['invoice' => $invoice, 'date' => $date];
        }
        return $rows;
    }
}
