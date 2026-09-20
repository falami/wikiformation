<?php
// src/Repository/ConventionContratRepository.php
namespace App\Repository;

use App\Entity\ConventionContrat;
use App\Entity\Devis;
use App\Entity\Inscription;
use App\Enum\DevisStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ConventionContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConventionContrat::class);
    }

    /** @return list<ConventionContrat> */
    public function findForDevis(Devis $devis): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.session', 's')->addSelect('s')
            ->andWhere('c.devis = :devis AND c.entite = :entite')
            ->setParameter('devis', $devis)->setParameter('entite', $devis->getEntite())
            ->orderBy('c.id', 'DESC')->getQuery()->getResult();
    }

    /** Conventions proposées à la sélection explicite, jamais rattachées automatiquement.
     * @return list<ConventionContrat>
     */
    public function findAttachableToDevis(Devis $devis): array
    {
        if ($devis->getStatus() === DevisStatus::CANCELED || $devis->getProspect()
            || (($devis->getEntrepriseDestinataire() !== null) === ($devis->getDestinataire() !== null))) {
            return [];
        }
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.session', 's')->addSelect('s')
            ->innerJoin('s.formation', 'f')->addSelect('f')
            ->andWhere('c.devis IS NULL AND c.entite = :entite AND s.entite = :entite AND f.entite = :entite')
            ->andWhere('c.dateSignatureStagiaire IS NULL AND c.dateSignatureEntreprise IS NULL AND c.dateSignatureOf IS NULL')
            ->andWhere("(c.signatureDataUrlStagiaire IS NULL OR c.signatureDataUrlStagiaire = '') AND (c.signatureDataUrlEntreprise IS NULL OR c.signatureDataUrlEntreprise = '')")
            ->setParameter('entite', $devis->getEntite());
        if ($devis->getEntrepriseDestinataire()) {
            $qb->andWhere('c.entreprise = :destinataire AND c.stagiaire IS NULL')->setParameter('destinataire', $devis->getEntrepriseDestinataire());
        } else {
            $qb->andWhere('c.stagiaire = :destinataire AND c.entreprise IS NULL')->setParameter('destinataire', $devis->getDestinataire());
        }
        if ($devis->getFormation()) {
            $qb->andWhere('s.formation = :formation')->setParameter('formation', $devis->getFormation());
        }
        return $qb->orderBy('c.id', 'DESC')->getQuery()->getResult();
    }

    /** @return list<ConventionContrat> */
    public function findForInscription(Inscription $inscription): array
    {
        if (!$inscription->getSession() || !$inscription->getEntite()) return [];
        return $this->createQueryBuilder('c')
            ->innerJoin('c.inscriptions', 'i')
            ->andWhere('i = :inscription AND c.session = :session AND c.entite = :entite')
            ->setParameter('inscription', $inscription)
            ->setParameter('session', $inscription->getSession())
            ->setParameter('entite', $inscription->getEntite())
            ->orderBy('c.id', 'DESC')->getQuery()->getResult();
    }

    public function findOneForInscription(Inscription $inscription, ?int $conventionId = null): ?ConventionContrat
    {
        $conventions = $this->findForInscription($inscription);
        if ($conventionId !== null) {
            foreach ($conventions as $convention) {
                if ($convention->getId() === $conventionId) return $convention;
            }
            return null;
        }
        // Les anciens liens restent utilisables uniquement sans ambiguïté.
        return count($conventions) === 1 ? $conventions[0] : null;
    }
}
