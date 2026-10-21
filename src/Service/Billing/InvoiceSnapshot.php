<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Facture;

/** Versioned issuance data. Historical records are never reconstructed on read. */
final class InvoiceSnapshot
{
    public static function read(Facture $invoice): ?array
    {
        $snapshot = $invoice->getMeta()['invoice_snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) return null;
        $hash = $snapshot['sha256'] ?? '';
        unset($snapshot['sha256']);
        return is_string($hash) && hash_equals(self::hash($snapshot), $hash) ? $snapshot + ['sha256' => $hash] : null;
    }

    public static function capture(Facture $invoice): void
    {
        if ($invoice->getId() !== null || isset($invoice->getMeta()['invoice_snapshot'])) {
            throw new \LogicException('L’instantané d’une facture émise ne peut pas être remplacé.');
        }
        $snapshot = self::draft($invoice);
        $snapshot['issuedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $snapshot['sha256'] = self::hash($snapshot);
        $invoice->setMeta(array_replace($invoice->getMeta() ?? [], ['invoice_snapshot' => $snapshot]));
    }

    public static function forRendering(Facture $invoice): ?array
    {
        $snapshot = self::read($invoice);
        if (isset($invoice->getMeta()['invoice_snapshot']) && !$snapshot) throw new \DomainException('L’empreinte de la facture émise est invalide. Restaurez sa version d’origine avant de produire le document.');
        return $snapshot;
    }

    /** @internal A draft is only for readiness checks; it is not an issued document. */
    public static function draft(Facture $invoice): array
    {
        $issuer = self::fields($invoice->getEntite(), ['id', 'nom', 'adresse', 'complement', 'codePostal', 'ville', 'pays', 'email', 'telephone', 'siteInternet', 'siret', 'numeroTva', 'formeJuridique', 'couleurPrincipal', 'couleurSecondaire', 'banque', 'codeBanque', 'numeroCompte', 'iban', 'bic']);
        $issuer['siren'] = strlen((string) $issuer['siret']) === 14 ? substr($issuer['siret'], 0, 9) : null;
        $issuer['logo'] = null;
        $issuer['logoDataUri'] = self::logo($invoice->getEntite()?->getLogo());
        $company = $invoice->getEntrepriseDestinataire();
        $person = $invoice->getDestinataire();
        $customer = self::fields($company ?? $person, ['id', 'raisonSociale', 'civilite', 'prenom', 'nom', 'adresse', 'complement', 'codePostal', 'ville', 'pays', 'email', 'emailFacturation', 'siret', 'numeroTva']);
        $customer['type'] = $company ? 'company' : 'person';
        $customer['siren'] = strlen((string) $customer['siret']) === 14 ? substr($customer['siret'], 0, 9) : null;
        $totals = InvoiceTotals::calculate($invoice);
        $lines = [];
        foreach ($invoice->getLignes() as $index => $line) {
            $row = self::fields($line, ['label', 'qte', 'puHtCents', 'tva', 'remisePourcent', 'remiseMontantCents', 'remiseCents', 'totalHtBrutCents', 'totalHtNetCents', 'totalTvaCents']);
            $row['id'] = $index + 1;
            $row['debours'] = $line->isDebours();
            $lines[] = $row + ($totals['lines'][$index] ?? []);
        }
        $meta = $invoice->getMeta() ?? [];
        unset($meta['invoice_snapshot'], $meta['invoice_totals']);
        $terms = array_replace(array_fill_keys(['dueDate', 'serviceDate', 'paymentMeans', 'earlyPaymentDiscount', 'latePaymentPenalty', 'recoveryCompensationCents', 'vatCategory', 'vatExemptionReason', 'legalIdentity', 'purchaseOrderReference', 'vatOnDebits'], null), $meta['invoice_terms'] ?? []);
        $terms['serviceDate'] ??= $meta['datePrestation'] ?? null;
        $terms['paymentMeans'] ??= $meta['modalite'] ?? null;
        $data = ['numero' => $invoice->getNumero(), 'dateEmission' => $invoice->getDateEmission()->format('Y-m-d'), 'devise' => $invoice->getDevise(),
            'status' => $invoice->getStatus()->value, 'remiseGlobalePourcent' => $invoice->getRemiseGlobalePourcent(), 'remiseGlobaleMontantCents' => $invoice->getRemiseGlobaleMontantCents(),
            'meta' => $meta, 'inscriptions' => array_map(static fn($i) => ['id' => $i->getId()], $invoice->getInscriptions()->toArray()),
            'destinataire' => $company ? null : $customer, 'entrepriseDestinataire' => $company ? $customer : null];
        return ['version' => 1, 'issuer' => $issuer, 'customer' => $customer, 'invoice' => $data, 'lines' => $lines, 'totals' => $totals, 'terms' => $terms];
    }

    private static function fields(?object $object, array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $method = 'get' . ucfirst($name);
            $result[$name] = $object && method_exists($object, $method) ? $object->$method() : null;
        }
        return $result;
    }

    private static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function logo(?string $name): ?string
    {
        if (!$name || basename($name) !== $name) return null;
        $path = dirname(__DIR__, 3) . '/public/uploads/photos/entite/logo/' . $name;
        if (!is_file($path) || filesize($path) > 2_000_000) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) return null;
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
}
