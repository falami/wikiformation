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

    public static function apply(Facture $invoice): void
    {
        $meta = $invoice->getMeta() ?? [];
        $terms = array_replace(self::defaults($invoice->getEntite()), array_filter($meta['invoice_terms'] ?? [], static fn($value) => $value !== null && $value !== ''));
        $settings = $invoice->getEntite()?->getPreferences()?->getInvoicePreferences() ?? [];
        if (empty($terms['dueDate']) && ($settings['confirmed'] ?? false) === true && isset($settings['dueDays'])) {
            $terms['dueDate'] = $invoice->getDateEmission()->modify('+' . (int) $settings['dueDays'] . ' days')->format('Y-m-d');
        }
        // A service date is specific to the invoice, never a guessed global default.
        $invoice->setMeta(array_replace($meta, ['invoice_terms' => $terms]));
    }
}
