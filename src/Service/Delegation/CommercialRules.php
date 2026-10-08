<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{Devis, Facture, ConventionContrat, ContratFormateur, UtilisateurEntite, Inscription, Session};
use App\Enum\DevisStatus;
use App\Service\Pdf\ContratFormateurDocument;
use Doctrine\ORM\EntityManagerInterface;
final class CommercialRules
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ContratFormateurDocument $contracts)
    {
    }
    public function editBlock(object $record): ?string
    {
        if ($record instanceof Facture) {
            return 'Une facture émise reste conservée. Les corrections passent par votre administrateur.';
        }
        if ($record instanceof Devis && ($record->getStatus() !== DevisStatus::DRAFT || $record->getFactureCreee())) {
            return 'Seuls les devis en brouillon peuvent être modifiés ou supprimés.';
        }
        if ($record instanceof Devis && $record->getProspect()) {
            return 'Ce devis est rattaché à un prospect. Rattachez-le à une entreprise cliente depuis l’administration avant de le modifier ici.';
        }
        if ($record instanceof ConventionContrat && ($record->isSigned() || $this->em->getRepository(\App\Entity\ConventionRevision::class)->count(['convention' => $record]))) {
            return 'Cette convention signée ou historisée est conservée. Demandez une nouvelle version à votre administrateur.';
        }
        if ($record instanceof ContratFormateur && $this->contracts->isFrozen($record)) {
            return 'Ce contrat signé ou clôturé est conservé. Demandez une nouvelle version à votre administrateur.';
        }
        if ($record instanceof Session && $record->getEmargementClotureAt()) {
            return 'Le suivi de cette session est clôturé.';
        }
        if ($record instanceof Inscription && $record->getStatus() === \App\Enum\StatusInscription::TERMINE) {
            return 'Cette inscription est terminée.';
        }
        if ($record instanceof UtilisateurEntite) {
            if (array_diff($record->getRoles(), [UtilisateurEntite::TENANT_STAGIAIRE]) || $this->em->getRepository(UtilisateurEntite::class)->count(['utilisateur' => $record->getUtilisateur()]) > 1) {
                return 'L’identité de ce compte partagé ou collaborateur est gérée par un administrateur.';
            }
        }
        return null;
    }
    public function deleteBlock(object $record): ?string
    {
        if ($reason = $this->editBlock($record)) {
            return $reason;
        }
        if ($record instanceof UtilisateurEntite) {
            return 'Le compte et son historique sont conservés : utilisez « Retirer de cette entreprise » dans la fiche client.';
        }
        if ($record instanceof Session) {
            foreach ($record->getJours() as $day) {
                if ($reason = $this->deleteBlock($day)) {
                    return $reason;
                }
            }
        }
        if ($record instanceof Inscription && $record->getDossier() && $reason = $this->deleteBlock($record->getDossier())) {
            return $reason;
        }
        // Inspect references before deletion, including associations outside this portal.
        $owned = match (true) {
            $record instanceof Devis => [\App\Entity\LigneDevis::class],
            $record instanceof Session => [\App\Entity\SessionJour::class],
            $record instanceof Inscription => [\App\Entity\DossierInscription::class],
            default => [],
        };
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (in_array($meta->getName(), $owned, true)) {
                continue;
            }
            foreach ($meta->associationMappings as $field => $mapping) {
                if ($mapping->targetEntity !== $this->em->getClassMetadata($record::class)->getName() || !$mapping->isOwningSide()) {
                    continue;
                }
                $query = $this->em->createQueryBuilder()->select('COUNT(r)')->from($meta->getName(), 'r');
                if ($meta->isSingleValuedAssociation($field)) {
                    $query->where('r.' . $field . ' = :target');
                } else {
                    $query->join('r.' . $field, 'target')->where('target.id = :target');
                }
                if ((int) $query->setParameter('target', $record->getId())->getQuery()->getSingleScalarResult() > 0) {
                    return 'Ce dossier est utilisé par d’autres éléments. Retirez ses liens non nécessaires avant de le supprimer ; les documents signés et l’historique restent protégés.';
                }
            }
        }
        return null;
    }
}
