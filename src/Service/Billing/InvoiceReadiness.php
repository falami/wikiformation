<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Facture;
use App\Enum\InvoiceVatCategory;

/** Missing facts are explicit; this check never infers an organisation's tax exemption. */
final class InvoiceReadiness
{
    /** @return list<string> */
    public static function issues(Facture $invoice): array
    {
        $snapshot = InvoiceSnapshot::read($invoice);
        if (isset($invoice->getMeta()['invoice_snapshot']) && !$snapshot) return ['L’empreinte de la facture émise est invalide.'];
        $data = $snapshot ?? InvoiceSnapshot::draft($invoice);
        $issues = [];
        foreach (['issuer' => 'organisme émetteur', 'customer' => 'destinataire'] as $key => $label) {
            foreach (['adresse' => 'Adresse', 'codePostal' => 'Code postal', 'ville' => 'Ville', 'pays' => 'Pays'] as $field => $name) {
                if (!trim((string) ($data[$key][$field] ?? ''))) $issues[] = "$name du $label à renseigner.";
            }
        }
        if (!trim((string) $data['issuer']['nom'])) $issues[] = 'Dénomination de l’organisme émetteur à renseigner.';
        if (!preg_match('/^\d{14}$/', (string) $data['issuer']['siret'])) $issues[] = 'SIRET de l’organisme émetteur à vérifier (14 chiffres).';
        if ($data['customer']['type'] === 'company' && !trim((string) $data['customer']['raisonSociale'])) $issues[] = 'Raison sociale du client à renseigner.';
        if ($data['customer']['type'] === 'company' && !preg_match('/^\d{14}$/', (string) $data['customer']['siret'])) $issues[] = 'SIRET du client professionnel à vérifier.';
        if ($data['customer']['type'] === 'person' && (!trim((string) $data['customer']['nom']) || !trim((string) $data['customer']['prenom']))) $issues[] = 'Nom et prénom du client à renseigner.';
        $terms = $data['terms'];
        foreach (['dueDate' => 'Date d’échéance', 'serviceDate' => 'Date de prestation', 'paymentMeans' => 'Mode de règlement', 'earlyPaymentDiscount' => 'Conditions d’escompte', 'legalIdentity' => 'Identité juridique et mentions d’immatriculation de l’émetteur'] as $field => $label) {
            if (!trim((string) $terms[$field])) $issues[] = "$label à renseigner.";
        }
        foreach (['dueDate', 'serviceDate'] as $field) {
            if ($terms[$field] && !self::date((string) $terms[$field])) $issues[] = 'Une date de facturation est invalide.';
        }
        if (self::date((string) $terms['dueDate']) && $terms['dueDate'] < $data['invoice']['dateEmission']) $issues[] = 'L’échéance ne peut pas précéder l’émission.';
        if ($data['customer']['type'] === 'company') {
            if (!trim((string) $terms['latePaymentPenalty'])) $issues[] = 'Conditions et taux de pénalités de retard à renseigner.';
            if ((int) $terms['recoveryCompensationCents'] !== 4000) $issues[] = 'Indemnité forfaitaire de recouvrement B2B à confirmer (40 €).';
        }
        $hasTax = $hasZeroRate = false;
        foreach ($data['lines'] as $line) {
            $hasTax = $hasTax || $line['tva'] > 0;
            $hasZeroRate = $hasZeroRate || ($line['tva'] == 0 && !$line['debours']);
        }
        if ($hasTax && !trim((string) $data['issuer']['numeroTva'])) $issues[] = 'Numéro de TVA de l’émetteur à renseigner pour les lignes taxées.';
        if ($hasTax && !is_bool($terms['vatOnDebits'])) $issues[] = 'Exigibilité de la TVA à confirmer : encaissements ou débits.';
        if ($hasZeroRate && (!in_array(InvoiceVatCategory::tryFrom((string) $terms['vatCategory']), [InvoiceVatCategory::EXEMPT, InvoiceVatCategory::ZERO, InvoiceVatCategory::REVERSE_CHARGE, InvoiceVatCategory::OUTSIDE_SCOPE], true) || !trim((string) $terms['vatExemptionReason']))) $issues[] = 'Catégorie et motif fiscal explicite requis pour une ligne sans TVA ; aucune exonération n’est présumée.';
        if ($hasTax && !$hasZeroRate && $terms['vatCategory'] !== InvoiceVatCategory::STANDARD->value) $issues[] = 'Catégorie TVA normale à confirmer pour les lignes taxées.';
        if (!$data['lines']) $issues[] = 'Les prestations facturées doivent être détaillées.';
        return array_values(array_unique($issues));
    }

    private static function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
