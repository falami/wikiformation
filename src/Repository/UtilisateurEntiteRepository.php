<?php

namespace App\Repository;

use App\Entity\UtilisateurEntite;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Entite;
use App\Service\Tenant\TenantContext;

/**
 * @extends ServiceEntityRepository<UtilisateurEntite>
 */
class UtilisateurEntiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UtilisateurEntite::class);
    }

    public function findForEntite(Entite $entite, ?string $q = null): array
    {
        $qb = $this->createQueryBuilder('ue')
            ->innerJoin('ue.utilisateur', 'u')->addSelect('u')
            ->andWhere('ue.entite = :e')->setParameter('e', $entite);

        // Admin d'abord (champ calculé + tri)
        $qb->addSelect("CASE WHEN JSON_CONTAINS(ue.roles, :admin) = 1 THEN 1 ELSE 0 END AS HIDDEN isAdmin")
            ->setParameter('admin', json_encode(UtilisateurEntite::TENANT_ADMIN))
            ->addOrderBy('isAdmin', 'DESC')
            ->addOrderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if ($q !== null && trim($q) !== '') {
            $q = mb_strtolower(trim($q));
            $qb->andWhere('LOWER(u.email) LIKE :q OR LOWER(u.nom) LIKE :q OR LOWER(u.prenom) LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countForEntite(Entite $entite): int
    {
        return (int) $this->createQueryBuilder('ue')
            ->select('COUNT(ue.id)')
            ->andWhere('ue.entite = :e')->setParameter('e', $entite)
            ->getQuery()->getSingleScalarResult();
    }


    public function userHasEntite(Utilisateur $user, Entite $entite): bool
    {
        if (null === $entite->getId()) {
            return false;
        }

        return (bool) $this->createQueryBuilder('ue')
            ->select('COUNT(ue.id)')
            ->andWhere('ue.utilisateur = :u')
            ->andWhere('ue.entite = :e')
            ->setParameter('u', $user)
            ->setParameter('e', $entite)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findFirstEntiteForUser(Utilisateur $user): ?Entite
    {
        $qb = $this->createQueryBuilder('ue')
            ->leftJoin('ue.entite', 'e')->addSelect('e')
            ->andWhere('ue.utilisateur = :u')
            ->setParameter('u', $user);

        $qb->addSelect("CASE WHEN JSON_CONTAINS(ue.roles, :admin) = 1 THEN 1 ELSE 0 END AS HIDDEN isAdmin")
            ->setParameter('admin', json_encode(UtilisateurEntite::TENANT_ADMIN))
            ->addOrderBy('isAdmin', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults(1);

        $ue = $qb->getQuery()->getOneOrNullResult();

        return $ue?->getEntite();
    }

    /** @return UtilisateurEntite[] */
    public function findAllForUser(Utilisateur $user): array
    {
        return $this->createQueryBuilder('ue')
            ->leftJoin('ue.entite', 'e')->addSelect('e')
            ->andWhere('ue.utilisateur = :u')
            ->setParameter('u', $user)
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }


    public function countActiveByRole(Entite $entite, string $role): int
    {
        return (int) $this->createQueryBuilder('ue')
            ->select('COUNT(ue.id)')
            ->andWhere('ue.entite = :e')->setParameter('e', $entite)
            ->andWhere('ue.status = :st')->setParameter('st', UtilisateurEntite::STATUS_ACTIVE)
            ->andWhere('JSON_CONTAINS(ue.roles, :r) = 1')
            ->setParameter('r', json_encode($role))
            ->getQuery()
            ->getSingleScalarResult();
    }


    public function isTenantDirigeant(Utilisateur $user): bool
    {
        // Dirigeant de la plateforme = possède TENANT_DIRIGEANT sur l'entité plateforme
        // (tu peux élargir à TENANT_ADMIN si tu veux que les admins plateforme comptent aussi)
        $qb = $this->createQueryBuilder('ue')
            ->select('1')
            ->innerJoin('ue.entite', 'e')
            ->andWhere('ue.utilisateur = :u')
            ->andWhere('e.id = :pid')
            ->andWhere('ue.status = :st')
            ->andWhere('JSON_CONTAINS(ue.roles, :dirigeant) = 1')
            ->setParameter('u', $user)
            ->setParameter('pid', TenantContext::PLATFORM_ENTITE_ID)
            ->setParameter('st', UtilisateurEntite::STATUS_ACTIVE)
            ->setParameter('dirigeant', json_encode(UtilisateurEntite::TENANT_DIRIGEANT))
            ->setMaxResults(1);

        return (bool) $qb->getQuery()->getOneOrNullResult();
    }

    // src/Repository/UtilisateurEntiteRepository.php

    public function findMembership(Utilisateur $user, Entite $entite): ?UtilisateurEntite
    {
        return $this->createQueryBuilder('ue')
            ->andWhere('ue.utilisateur = :u')
            ->andWhere('ue.entite = :e')
            ->setParameter('u', $user)
            ->setParameter('e', $entite)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // src/Repository/UtilisateurEntiteRepository.php

    public function hasRoleInEntite(Utilisateur $user, Entite $entite, string $role): bool
    {
        $ue = $this->findMembership($user, $entite);
        if (!$ue) return false;

        $roles = $ue->getRoles() ?? [];
        return in_array($role, $roles, true);
    }

    public function canSetHighRoles(Utilisateur $actor, Entite $entite): bool
    {
        // seul le DIRIGEANT de l'entité peut définir ADMIN/DIRIGEANT
        return $this->hasRoleInEntite($actor, $entite, UtilisateurEntite::TENANT_DIRIGEANT);
    }
}
