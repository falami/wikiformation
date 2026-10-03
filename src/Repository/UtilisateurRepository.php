<?php

namespace App\Repository;

use App\Entity\{Utilisateur, Entite};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use App\Entity\UtilisateurEntite;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /** UniqueEntity excludes the current entity itself after this normalized lookup. */
    public function findByCanonicalEmail(array $criteria): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(TRIM(u.email)) = :email')
            ->setParameter('email', Utilisateur::normalizeEmail((string) ($criteria['email'] ?? '')))
            ->getQuery()->getResult();
    }

    public function loadUserByIdentifier(string $identifier): ?Utilisateur
    {
        // Keep legacy mixed-case addresses usable; never pick an ambiguous identity.
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(TRIM(u.email)) = :email')
            ->setParameter('email', Utilisateur::normalizeEmail($identifier))
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }


    public function searchAll(?string $q = null, int $limit = 250): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults($limit);

        if ($q && trim($q) !== '') {
            $q = mb_strtolower(trim($q));
            $qb->andWhere('LOWER(u.email) LIKE :q OR LOWER(u.nom) LIKE :q OR LOWER(u.prenom) LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

}
