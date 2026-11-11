<?php
declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\{ConventionContrat, Facture, FactureBrouillon, LigneFacture, Utilisateur};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** One working document per convention. Never persists an invoice. */
final class ConventionInvoiceDraft
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceDraftForm $forms,
        private readonly InvoiceDraftManager $drafts,
    ) {}

    public function prepare(ConventionContrat $convention, Utilisateur $actor): FactureBrouillon
    {
        return $this->em->getConnection()->transactional(function () use ($convention, $actor): FactureBrouillon {
            $this->em->refresh($convention, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(FactureBrouillon::class)->findOneBy(['sourceConvention' => $convention]);
            if ($existing) return $existing;
            $quoteDraft = null;
            $quote = $convention->getDevis();
            if ($quote) {
                $this->em->refresh($quote, LockMode::PESSIMISTIC_WRITE);
                if ($quote->getEntite()?->getId() !== $convention->getEntite()?->getId()) throw new \DomainException('Le devis appartient à un autre organisme.');
                if ($quote->getFactureCreee()) throw new \DomainException('Le devis lié possède déjà une facture émise. Consultez cette facture avant de poursuivre.');
                $quoteDraft = $this->em->getRepository(FactureBrouillon::class)->findOneBy(['sourceDevis' => $quote]);
                if ($quoteDraft) {
                    $this->em->refresh($quoteDraft, LockMode::PESSIMISTIC_WRITE);
                    $quoteDraft->assertEditable();
                    if ($quoteDraft->getSourceConvention()) throw new \DomainException('Le brouillon du devis est déjà rattaché à une autre convention. Vérifiez la facturation existante.');
                }
            }
            $entity = $convention->getEntite();
            if (!$entity || !$convention->getSession() || $convention->getSession()->getEntite()?->getId() !== $entity->getId()) {
                throw new \DomainException('La session de cette convention doit appartenir au même organisme.');
            }
            if (($convention->getEntreprise() === null) === ($convention->getStagiaire() === null)) {
                throw new \DomainException('Renseignez un seul destinataire sur la convention avant de préparer sa facture.');
            }
            if ($convention->getEntreprise() && $convention->getEntreprise()->getEntite()?->getId() !== $entity->getId()) {
                throw new \DomainException('Le destinataire appartient à un autre organisme.');
            }
            $invoice = (new Facture())->setEntite($entity)->setCreateur($actor)
                ->setEntrepriseDestinataire($convention->getEntreprise())->setDestinataire($convention->getStagiaire())
                ->setFormation($convention->getSession()->getFormation())
                ->setNote('Convention '.$convention->getNumero())
                ->setMontantHtCents(0)->setMontantTvaCents(0)->setMontantTtcCents(0);
            foreach ($convention->getInscriptions() as $registration) {
                if ($registration->getEntite()?->getId() !== $entity->getId()
                    || $registration->getSession()?->getId() !== $convention->getSession()->getId()
                    || ($convention->getEntreprise() && $registration->getEntreprise()?->getId() !== $convention->getEntreprise()->getId())
                    || ($convention->getStagiaire() && $registration->getStagiaire()?->getId() !== $convention->getStagiaire()->getId())) {
                    throw new \DomainException('Vérifiez les inscriptions rattachées à cette convention.');
                }
                $invoice->addInscription($registration);
            }
            // The convention amount is a total, not a price per participant.
            $invoice->addLigne((new LigneFacture())->setEntite($entity)->setCreateur($actor)
                ->setLabel($convention->getIntituleFormationEffectif().' — '.$convention->getNumero().' — '.$convention->getSession()->getCode())
                ->setQte(1)->setPuHtCents($convention->getMontantHtCents() ?? 0)->setTva($convention->getTauxTva()));
            $payload = $this->forms->payloadFromForm($this->forms->build($entity, $actor, invoice: $invoice));
            // Unknown prices must be completed explicitly, never silently invoiced at zero.
            if ($convention->getMontantHtCents() === null) $payload['lignes'][0]['puHtCents'] = '';
            // Keep entered invoice terms, but replace pricing and recipient with the convention.
            if ($quoteDraft) $payload['invoiceTerms'] = $quoteDraft->getPayload()['invoiceTerms'] ?? $payload['invoiceTerms'] ?? [];
            $draft = $quoteDraft ?? new FactureBrouillon($entity, $actor);
            $this->drafts->save($draft, $payload, $draft->getVersion(), $actor, $this->forms->summary($this->forms->build($entity, $actor, $payload, false)));
            if ($quote && !$draft->getSourceDevis()) $draft->setSourceDevis($quote);
            $draft->setSourceConvention($convention);
            $this->em->flush();
            return $draft;
        });
    }
}
