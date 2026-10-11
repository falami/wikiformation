<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Facture;
use App\Service\Sequence\FactureNumberGenerator;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

/** One transaction for numbering, immutable issuance data and persistence. */
final class InvoiceIssuer
{
    public static function persist(EntityManagerInterface $em, FactureNumberGenerator $numbers, Facture $invoice, ?callable $relatedChanges = null): Facture
    {
        if ($invoice->getId() !== null) throw new \LogicException('Cette facture est déjà émise.');
        if (!$invoice->getEntite()?->getId()) throw new \DomainException('L’organisme émetteur est manquant.');
        if (($invoice->getDestinataire() !== null) === ($invoice->getEntrepriseDestinataire() !== null)) throw new \DomainException('Choisissez un seul destinataire : entreprise ou personne.');
        if ($invoice->getEntrepriseDestinataire() && $invoice->getEntrepriseDestinataire()->getEntite()?->getId() !== $invoice->getEntite()->getId()) throw new \DomainException('Le client appartient à un autre organisme.');
        if ($invoice->getDevise() !== 'EUR' || $invoice->getLignes()->isEmpty()) throw new \DomainException('Renseignez les prestations de la facture en euros.');
        foreach ($invoice->getLignes() as $line) {
            if (!trim((string) $line->getLabel()) || $line->getQte() <= 0 || $line->getPuHtCents() < 0) throw new \DomainException('Chaque prestation doit avoir une désignation, une quantité positive et un prix non négatif.');
        }
        InvoicePreferences::apply($invoice);
        if ($issues = InvoiceReadiness::issues($invoice)) {
            throw new \DomainException('Complétez les informations avant d’émettre la facture : ' . implode(' ', $issues));
        }
        return $em->wrapInTransaction(function () use ($em, $numbers, $invoice, $relatedChanges) {
            if ($relatedChanges) $relatedChanges($invoice);
            $connection = $em->getConnection();
            $lock = $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
            $connection->fetchOne('SELECT id FROM entite WHERE id = ?' . $lock, [$invoice->getEntite()->getId()]);
            $latest = $connection->fetchOne('SELECT MAX(date_emission) FROM facture WHERE entite_id = ?', [$invoice->getEntite()->getId()]);
            if ($latest && substr($latest, 0, 10) > $invoice->getDateEmission()->format('Y-m-d')) throw new \DomainException('La date d’émission ne peut pas précéder celle de la dernière facture de cet organisme.');
            $invoice->setNumero($numbers->nextForEntite($invoice->getEntite()->getId(), (int) $invoice->getDateEmission()->format('Y')));
            InvoiceTotals::finalizeNewInvoice($invoice);
            InvoiceSnapshot::capture($invoice);
            $em->persist($invoice);
            return $invoice;
        });
    }
}
