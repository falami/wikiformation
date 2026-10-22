<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\Facture;
use App\Service\Billing\{InvoiceReadiness, InvoiceSnapshot};

/** EN16931 JSON accepted by SUPER PDP's documented converter. */
final class InvoicePayload
{
    public function issues(Facture $invoice): array
    {
        $issues = InvoiceReadiness::issues($invoice);
        $snapshot = InvoiceSnapshot::read($invoice);
        if (!$snapshot) return array_merge($issues, ['Cette pièce historique ne possède pas d’instantané d’émission vérifiable.']);
        if ($invoice->isCanceled()) $issues[] = 'La facture est annulée.';
        if ($snapshot['invoice']['devise'] !== 'EUR') $issues[] = 'Seules les factures en euros sont prises en charge par ce raccordement.';
        if ($snapshot['customer']['type'] !== 'company') $issues[] = 'Le e-reporting des particuliers doit être traité avec la plateforme ; l’envoi B2B ne couvre pas cette facture.';
        foreach (['issuer', 'customer'] as $party) {
            if (!in_array(mb_strtoupper(trim((string) $snapshot[$party]['pays'])), ['FR', 'FRANCE'], true)) $issues[] = 'Les opérations internationales nécessitent un traitement spécifique sur la plateforme agréée.';
            if (!preg_match('/^\d{9}$/', (string) $snapshot[$party]['siren'])) $issues[] = 'Le SIREN de chaque partie est nécessaire à l’adressage.';
        }
        if ($snapshot['terms']['vatCategory'] !== 'S') $issues[] = 'Régime fiscal à traiter sur la plateforme : aucune obligation d’émission n’est présumée pour une opération exonérée.';
        foreach ($snapshot['lines'] as $line) {
            if ($line['debours'] || $line['tva'] <= 0) $issues[] = 'Les débours et lignes sans TVA nécessitent un traitement fiscal spécifique.';
            if ((float) $line['qte'] <= 0 || (int) $line['puHtCents'] < 0 || trim((string) $line['label']) === '') $issues[] = 'Une prestation comporte une quantité, un prix ou une désignation invalide.';
        }
        foreach ($snapshot['totals']['rates'] as $rate => $amounts) {
            if ((int) round($amounts['htCents'] * (float) $rate / 100) !== $amounts['tvaCents']) $issues[] = 'L’arrondi TVA par taux doit être rapproché avant transmission électronique.';
        }
        return array_values(array_unique($issues));
    }

    public function build(Facture $invoice, string $buyerEndpoint): array
    {
        if ($issues = $this->issues($invoice)) throw new \DomainException(implode(' ', $issues));
        $s = InvoiceSnapshot::read($invoice); $t = $s['totals']; $terms = $s['terms'];
        if (!preg_match('/^0225:'.preg_quote($s['customer']['siren'], '/').'(?:_[A-Za-z0-9_.-]+)?$/D', $buyerEndpoint)) throw new \DomainException('Adresse électronique du destinataire incohérente.');
        $lines = [];
        foreach ($s['lines'] as $i => $line) {
            $gross = (int) round((float) $line['qte'] * $line['puHtCents']);
            $row = ['identifier' => (string) ($i + 1), 'invoiced_quantity' => (string) $line['qte'], 'invoiced_quantity_code' => 'C62',
                'net_amount' => self::money($line['htCents']), 'price_details' => ['item_net_price' => self::money($line['puHtCents']), 'item_price_base_quantity' => '1'],
                'item_information' => ['name' => $line['label']], 'vat_information' => ['invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => (string) $line['tva']],
                'real_delivery_date' => $terms['serviceDate']];
            if ($gross > $line['htCents']) $row['allowances'] = [['amount' => self::money($gross - $line['htCents']), 'reason' => 'Remise commerciale']];
            $lines[] = $row;
        }
        $data = ['number' => $s['invoice']['numero'], 'issue_date' => $s['invoice']['dateEmission'], 'type_code' => 380, 'currency_code' => 'EUR',
            'process_control' => ['specification_identifier' => 'urn:cen.eu:en16931:2017'],
            'seller' => $this->party($s['issuer'], $s['issuer']['nom'], '0225:'.$s['issuer']['siren']) + ['additional_legal_information' => (string) $terms['legalIdentity']],
            'buyer' => $this->party($s['customer'], $s['customer']['raisonSociale'], $buyerEndpoint),
            'payment_due_date' => $terms['dueDate'],
            'payment_terms' => sprintf("Règlement : %s. Escompte : %s. Retard : %s. Indemnité forfaitaire de recouvrement : %s EUR.", $terms['paymentMeans'], $terms['earlyPaymentDiscount'], $terms['latePaymentPenalty'], self::money((int) $terms['recoveryCompensationCents'])),
            'totals' => ['sum_invoice_lines_amount' => self::money($t['htCents']), 'total_without_vat' => self::money($t['htCents']), 'total_with_vat' => self::money($t['ttcCents']), 'total_vat_amount' => ['value' => self::money($t['tvaCents']), 'currency_code' => 'EUR'], 'amount_due_for_payment' => self::money($t['ttcCents'])],
            'vat_break_down' => [], 'lines' => $lines];
        foreach ($t['rates'] as $rate => $values) $data['vat_break_down'][] = ['vat_category_code' => 'S', 'vat_category_rate' => (string) $rate, 'vat_category_taxable_amount' => self::money($values['htCents']), 'vat_category_tax_amount' => self::money($values['tvaCents'])];
        if ($terms['purchaseOrderReference']) $data['purchase_order_reference'] = $terms['purchaseOrderReference'];
        if ($terms['vatOnDebits'] === true) $data['vat_point_date_code'] = '3';
        elseif ($terms['vatOnDebits'] === false) $data['vat_point_date_code'] = '432';
        return $data;
    }
    private function party(array $party, string $name, string $endpoint): array
    {
        [$scheme, $value] = explode(':', $endpoint, 2);
        $data = ['name' => $name, 'electronic_address' => ['scheme' => $scheme, 'value' => $value],
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => $party['siren']],
            'postal_address' => ['address_line1' => $party['adresse'], 'post_code' => $party['codePostal'], 'city' => $party['ville'], 'country_code' => 'FR']];
        if (trim((string) ($party['complement'] ?? '')) !== '') $data['postal_address']['address_line2'] = $party['complement'];
        if ($party['numeroTva']) $data['vat_identifier'] = $party['numeroTva'];
        return $data;
    }
    private static function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
}
