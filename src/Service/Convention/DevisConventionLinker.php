<?php

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, Devis};
use App\Enum\DevisStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Récupération explicite d'une convention existante dont le devis source n'a pas été enregistré. */
final class DevisConventionLinker
{
    public function __construct(private EntityManagerInterface $em) {}

    public function link(Devis $devis, ConventionContrat $convention): void
    {
        $this->em->wrapInTransaction(function () use ($devis, $convention): void {
            // Recharge sous verrou pour refuser une signature ou un rattachement concurrent.
            $this->em->refresh($devis, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($convention, LockMode::PESSIMISTIC_WRITE);
            $entiteId = $devis->getEntite()?->getId();
            $session = $convention->getSession();
            if (!$entiteId || $convention->getEntite()?->getId() !== $entiteId
                || $session?->getEntite()?->getId() !== $entiteId
                || $session?->getFormation()?->getEntite()?->getId() !== $entiteId) {
                throw new \DomainException('La convention et sa session doivent appartenir au même organisme que le devis.');
            }
            if ($convention->getDevis()) {
                if ($convention->getDevis()->getId() === $devis->getId()) return;
                throw new \DomainException('Cette convention est déjà rattachée à un autre devis.');
            }
            if ($convention->isSigned()) {
                throw new \DomainException('Une convention signée ne peut pas être rattachée : ses documents doivent rester inchangés.');
            }
            if ($devis->getStatus() === DevisStatus::CANCELED || $devis->getProspect()
                || (($devis->getEntrepriseDestinataire() !== null) === ($devis->getDestinataire() !== null))) {
                throw new \DomainException('Choisissez un devis non annulé adressé à une entreprise ou à un stagiaire.');
            }
            if ($convention->getEntreprise()?->getId() !== $devis->getEntrepriseDestinataire()?->getId()
                || $convention->getStagiaire()?->getId() !== $devis->getDestinataire()?->getId()) {
                throw new \DomainException('Le destinataire de la convention doit être celui du devis.');
            }
            if ($devis->getFormation() && $session->getFormation()?->getId() !== $devis->getFormation()->getId()) {
                throw new \DomainException('La formation de la session doit correspondre à celle du devis.');
            }
            foreach ($convention->getInscriptions() as $inscription) {
                if ($inscription->getEntite()?->getId() !== $entiteId || $inscription->getSession()?->getId() !== $session->getId()) {
                    throw new \DomainException('Vérifiez les inscriptions de la convention avant de la rattacher.');
                }
                $devis->addInscription($inscription);
            }
            $convention->setDevis($devis);
            // Le prochain PDF doit refléter la référence et les montants désormais rattachés.
            // L'ancien fichier n'est pas supprimé.
            $convention->setPdfPath(null);
        });
    }
}
