<?php
declare(strict_types=1);
namespace App\Service\Billing;
use App\Entity\{Entite, Facture};

final class InvoicePreferences
{
    public static function defaults(?Entite $entity): array
    {
        $settings = $entity?->getPreferences()?->getInvoicePreferences() ?? [];
        if (($settings['confirmed'] ?? false) !== true) return [];
        unset($settings['confirmed'], $settings['confirmedAt'], $settings['confirmedBy'], $settings['dueDays']);
        return $settings;
    }

    public static function apply(Facture $invoice, bool $prefill = false): void
    {
        if ($invoice->getId() !== null || isset($invoice->getMeta()['invoice_snapshot'])) return;
        $meta = $invoice->getMeta() ?? [];
        $terms = array_replace(self::defaults($invoice->getEntite()), array_filter($meta['invoice_terms'] ?? [], static fn($value) => $value !== null && $value !== ''));
        $settings = $invoice->getEntite()?->getPreferences()?->getInvoicePreferences() ?? [];
        if (empty($terms['dueDate']) && ($prefill || (($settings['confirmed'] ?? false) === true && isset($settings['dueDays'])))) {
            $days = ($settings['confirmed'] ?? false) === true ? ($settings['dueDays'] ?? 30) : 30;
            $terms['dueDate'] = $invoice->getDateEmission()->modify('+'.(int)$days.' days')->format('Y-m-d');
        }
        if ($prefill) {
            $terms['paymentMeans'] = $terms['paymentMeans'] ?? \App\Enum\ModePaiement::VIREMENT->invoiceLabel();
            if (empty($terms['serviceDate'])) $terms['serviceDate'] = self::serviceDate($invoice);
        }
        $invoice->setMeta(array_replace($meta, ['invoice_terms' => $terms]));
    }
    public static function serviceDate(Facture $invoice): string
    {
        $last = null;
        foreach ($invoice->getInscriptions() as $registration) {
            if ($registration->getSession()?->getEntite() !== $invoice->getEntite()) continue;
            foreach ($registration->getSession()->getJours() as $day) {
                $end = $day->getDateFin();
                if ($end && (!$last || $end > $last)) $last = $end;
            }
        }
        return ($last ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

}
