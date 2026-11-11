<?php
declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\{Facture, FactureBrouillon, Utilisateur};
use App\Enum\DevisStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Draft writes have no numbering, payment, accounting or transmission side effects. */
final class InvoiceDraftManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceIssuer $issuer,
        private readonly InscriptionBillingSync $inscriptions,
    ) {}

    public function save(FactureBrouillon $draft, array $payload, int $expectedVersion, Utilisateur $actor, array $summary = []): void
    {
        $payload = $this->cleanPayload($payload);
        $this->assertJsonSize($payload);
        $this->assertJsonSize($summary);
        $this->em->getConnection()->transactional(function () use ($draft, $payload, $expectedVersion, $actor, $summary): void {
            $this->refreshLocked($draft);
            $this->assertVersion($draft, $expectedVersion);
            $draft->savePayload($payload, $summary, $actor);
            $this->em->persist($draft);
            $this->em->flush();
        });
    }

    public function delete(FactureBrouillon $draft, int $expectedVersion): void
    {
        $this->em->getConnection()->transactional(function () use ($draft, $expectedVersion): void {
            $this->refreshLocked($draft);
            $this->assertVersion($draft, $expectedVersion);
            if ($draft->getFactureEmise()) throw new \DomainException('Une facture déjà émise ne peut pas être supprimée depuis les brouillons.');
            $this->em->remove($draft);
            $this->em->flush();
        });
    }

    public function archive(FactureBrouillon $draft, int $expectedVersion, Utilisateur $actor): void
    {
        $this->em->getConnection()->transactional(function () use ($draft, $expectedVersion, $actor): void {
            $this->refreshLocked($draft);
            $this->assertVersion($draft, $expectedVersion);
            $draft->archive($actor);
            $this->em->flush();
        });
    }

    public function restore(FactureBrouillon $draft, int $expectedVersion, Utilisateur $actor): void
    {
        $this->em->getConnection()->transactional(function () use ($draft, $expectedVersion, $actor): void {
            $this->refreshLocked($draft);
            $this->assertVersion($draft, $expectedVersion);
            $draft->restore($actor);
            $this->em->flush();
        });
    }

    /**
     * The factory rehydrates tenant-scoped form values and checks the reviewed
     * fingerprint from the freshly locked document. It must return a NEW Facture.
     * Duplicate confirmations return the already-issued invoice without numbering.
     *
     * @param callable(FactureBrouillon): Facture $factory
     */
    public function issue(FactureBrouillon $draft, int $expectedVersion, Utilisateur $actor, callable $factory): Facture
    {
        if ($draft->getId() === null) throw new \DomainException('Enregistrez le brouillon avant de l’émettre.');

        return $this->em->getConnection()->transactional(function () use ($draft, $expectedVersion, $actor, $factory): Facture {
            $this->refreshLocked($draft);
            if ($draft->getFactureEmise()) return $draft->getFactureEmise();
            $this->assertVersion($draft, $expectedVersion);
            $draft->assertEditable();
            if ($convention = $draft->getSourceConvention()) {
                $this->em->refresh($convention, LockMode::PESSIMISTIC_WRITE);
                if ($convention->getEntite()?->getId() !== $draft->getEntite()->getId()) throw new \DomainException('La convention appartient à un autre organisme.');
                if ($convention->getDevis() !== $draft->getSourceDevis()) throw new \DomainException('Le devis lié à la convention a changé. Vérifiez le rattachement du brouillon avant émission.');
            }
            $devis = $draft->getSourceDevis();
            if ($devis) {
                $this->em->refresh($devis, LockMode::PESSIMISTIC_WRITE);
                if ($devis->getEntite()?->getId() !== $draft->getEntite()->getId()) throw new \DomainException('Le devis appartient à un autre organisme.');
                if ($devis->getFactureCreee()) {
                    $draft->markIssued($devis->getFactureCreee(), $actor);
                    $this->em->flush();
                    return $devis->getFactureCreee();
                }
                if (!$devis->getStatus()->canBeInvoiced()) throw new \DomainException('Le devis ne peut plus être facturé.');
            }
            $invoice = $factory($draft);
            if (!$invoice instanceof Facture || $invoice->getId() !== null) throw new \LogicException('La validation doit produire une nouvelle facture.');
            if ($invoice->getEntite()?->getId() !== $draft->getEntite()->getId()) throw new \DomainException('La facture appartient à un autre organisme.');
            $invoice->setCreateur($actor);
            $issued = $this->issuer->persist($invoice, function () use ($draft, $invoice, $actor, $devis): void {
                $draft->markIssued($invoice, $actor);
                if ($devis) {
                    $meta = $devis->getMeta() ?? [];
                    unset($meta['invoice_draft']);
                    $devis->setMeta($meta)->setFactureCreee($invoice)->setStatus(DevisStatus::INVOICED);
                }
            });
            // InvoiceIssuer has flushed inside this still-open outer transaction.
            // Keep initialized inverse collections current before projecting balances.
            try {
                foreach ($issued->getInscriptions() as $inscription) $inscription->addFacture($issued);
                $this->inscriptions->syncMany($issued->getInscriptions()->toArray());
            } catch (\Throwable $error) {
                // The database transaction below will roll back the invoice and its
                // number; do not let a caller reuse entities representing that issue.
                $this->em->close();
                throw $error;
            }
            return $issued;
        });
    }

    private function refreshLocked(FactureBrouillon $draft): void
    {
        if ($draft->getId() !== null) $this->em->refresh($draft, LockMode::PESSIMISTIC_WRITE);
    }

    private function assertVersion(FactureBrouillon $draft, int $expectedVersion): void
    {
        if ($expectedVersion !== $draft->getVersion()) {
            throw new \DomainException('Ce brouillon a été modifié dans un autre onglet ou par un autre utilisateur. Rechargez-le et vérifiez la dernière version avant de continuer.');
        }
    }

    private function cleanPayload(array $payload, int $depth = 0): array
    {
        if ($depth > 8) throw new \DomainException('Le brouillon contient des données trop imbriquées.');
        foreach ($payload as $key => $value) {
            if ($key === '_token') { unset($payload[$key]); continue; }
            if (is_array($value)) $payload[$key] = $this->cleanPayload($value, $depth + 1);
            elseif (!is_scalar($value) && $value !== null) throw new \DomainException('Le brouillon contient une valeur non prise en charge.');
        }
        return $payload;
    }

    private function assertJsonSize(array $value): void
    {
        try { $json = json_encode($value, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \DomainException('Le brouillon contient des caractères ou des valeurs invalides.'); }
        if (strlen($json) > 1_000_000) throw new \DomainException('Le brouillon est trop volumineux. Réduisez le contenu des lignes.');
    }
}
