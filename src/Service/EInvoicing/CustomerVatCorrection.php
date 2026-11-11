<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\{Facture, EInvoiceTransmission};
use App\Service\Billing\InvoiceSnapshot;
use App\Service\Company\CompanyIdentifiers;

/** Explicit administrative repair, never an automatic refresh of an issued invoice. */
final class CustomerVatCorrection
{
    public function preview(Facture $invoice, EInvoiceTransmission $transmission): array
    {
        if ($transmission->getFacture() !== $invoice || $invoice->isCanceled()
            || $transmission->getAttemptedAt() !== null || $transmission->getProviderId() !== null
            || $transmission->getAcceptedAt() !== null || $transmission->getEvents() !== []
            || !in_array($transmission->getStatus(), ['blocked', 'queued'], true)) {
            throw new \DomainException('Correction interdite : facture annulée, transmission déjà tentée ou état incompatible.');
        }
        $snapshot = InvoiceSnapshot::read($invoice);
        $company = $invoice->getEntrepriseDestinataire();
        if (!$snapshot || !$company || $snapshot['customer']['type'] !== 'company'
            || (string) $snapshot['customer']['id'] !== (string) $company->getId()
            || $company->getEntite()?->getId() !== $invoice->getEntite()?->getId()) {
            throw new \DomainException('Instantané ou identité du destinataire incohérent.');
        }
        $siret = CompanyIdentifiers::normalize($company->getSiret());
        $new = CompanyIdentifiers::normalize($company->getNumeroTVA());
        if ($new === '' || !str_starts_with($new, 'FR') || $siret === ''
            || $siret !== CompanyIdentifiers::normalize($snapshot['customer']['siret'] ?? null)
            || CompanyIdentifiers::errors($siret, $new) !== []) {
            throw new \DomainException('Vérifiez le SIRET et le numéro de TVA français dans la fiche entreprise. Le SIRET doit être identique à celui de la facture.');
        }
        $old = (string) ($snapshot['customer']['numeroTva'] ?? '');
        if (CompanyIdentifiers::normalize($old) === $new) return ['old' => $old, 'new' => $new, 'changed' => false];
        if ($old === '' || !isset(CompanyIdentifiers::errors($siret, $old)['numeroTVA'])) {
            throw new \DomainException('Cette commande corrige uniquement un numéro de TVA existant et invalide, pas un changement d’identité fiscale.');
        }
        return ['old' => $old, 'new' => $new, 'changed' => true];
    }

    /** Caller must hold the connection lease and a transaction locking the invoice. */
    public function apply(Facture $invoice, EInvoiceTransmission $transmission, string $expectedOld, string $expectedNew): bool
    {
        $change = $this->preview($invoice, $transmission);
        if (!$change['changed']) return false;
        if ($change['old'] !== $expectedOld || $change['new'] !== $expectedNew) throw new \DomainException('Les données ont changé depuis l’aperçu. Relancez la simulation.');
        $snapshot = InvoiceSnapshot::read($invoice);
        $original = $snapshot;
        $snapshot['customer']['numeroTva'] = $change['new'];
        $snapshot['invoice']['entrepriseDestinataire']['numeroTva'] = $change['new'];
        unset($snapshot['sha256']);
        $snapshot['sha256'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $meta = $invoice->getMeta();
        $meta['invoice_snapshot_corrections'][] = [
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => 'app:einvoicing:correct-customer-vat',
            'reason' => 'Correction explicite du numéro de TVA client invalide avant toute tentative de dépôt.',
            'old' => $change['old'], 'new' => $change['new'],
            'originalSnapshot' => $original, 'newSha256' => $snapshot['sha256'],
        ];
        $meta['invoice_snapshot'] = $snapshot;
        $invoice->setMeta($meta);
        $transmission->resetAfterCustomerVatCorrection();
        return true;
    }
}
