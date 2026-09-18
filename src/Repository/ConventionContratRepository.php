<?php
// src/Repository/ConventionContratRepository.php
namespace App\Repository;

use App\Entity\ConventionContrat;
use App\Entity\Inscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ConventionContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConventionContrat::class);
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
