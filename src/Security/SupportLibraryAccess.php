<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\{Entite, Inscription, Session, SupportAsset, SupportAssignSession, SupportAssignUser, SupportDocument, Utilisateur};
use App\Enum\StatusInscription;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** The listing and the file itself use the same live assignments and tenant boundary. */
final class SupportLibraryAccess
{
    public function __construct(private EntityManagerInterface $em, private AuthorizationCheckerInterface $authorization) {}

    public function canRead(SupportAsset $asset, Utilisateur $user): bool
    {
        $entite = $asset->getEntite();
        if (!$entite?->getId()) return false;
        if ($this->authorization->isGranted(TenantPermission::ADMIN, $entite)) return true;
        if ($this->authorization->isGranted(TenantPermission::FORMATEUR, $entite)) {
            if ($asset->getUploadedBy()?->getId() === $user->getId()) return true;
            foreach ($this->em->getRepository(SupportAssignSession::class)->findBy(['asset' => $asset, 'entite' => $entite, 'isActive' => true]) as $link) {
                if ($link->getSession()?->getEntite()?->getId() === $entite->getId() && $link->getSession()->hasFormateurUtilisateur($user)) return true;
            }
        }
        if (!$this->authorization->isGranted(TenantPermission::STAGIAIRE, $entite)) return false;
        return $this->forLearner($entite, $user, asset: $asset) !== [];
    }

    public function canReadLegacy(SupportDocument $document, Utilisateur $user): bool
    {
        $entite = $document->getEntite();
        $session = $document->getSession();
        $formation = $document->getFormation();
        if (!$entite?->getId() || ($session && $session->getEntite()?->getId() !== $entite->getId())
            || ($formation && $formation->getEntite()?->getId() !== $entite->getId())) return false;
        if ($this->authorization->isGranted(TenantPermission::ADMIN, $entite)) return true;
        if ($this->authorization->isGranted(TenantPermission::FORMATEUR, $entite)
            && ($document->getUploadedBy()?->getId() === $user->getId() || $session?->hasFormateurUtilisateur($user))) return true;
        if (!$this->authorization->isGranted(TenantPermission::STAGIAIRE, $entite) || (!$session && !$formation)) return false;
        $query = $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Inscription::class, 'i')->join('i.session', 's')
            ->andWhere('i.entite = :entite AND s.entite = :entite AND i.stagiaire = :user AND i.status != :cancelled')
            ->setParameter('entite', $entite)->setParameter('user', $user)->setParameter('cancelled', StatusInscription::ANNULE->value);
        // A session-specific document never becomes visible to every session of its formation.
        if ($session) $query->andWhere('s = :session')->setParameter('session', $session);
        else $query->andWhere('s.formation = :formation')->setParameter('formation', $formation);
        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<SupportAsset> */
    public function forLearner(Entite $entite, Utilisateur $user, ?Session $session = null, ?SupportAsset $asset = null): array
    {
        $admin = $this->authorization->isGranted(TenantPermission::ADMIN, $entite);
        if (!$admin && !$this->authorization->isGranted(TenantPermission::STAGIAIRE, $entite)) return [];
        if ($session && $session->getEntite()?->getId() !== $entite->getId()) return [];
        $now = new \DateTimeImmutable();
        $assets = [];
        foreach ([SupportAssignSession::class, SupportAssignUser::class] as $type) {
            $query = $this->em->createQueryBuilder()->select('link', 'asset')->from($type, 'link')->join('link.asset', 'asset')
                ->andWhere('link.entite = :entite AND asset.entite = :entite')->setParameter('entite', $entite)
                ->andWhere('link.isActive = true AND link.isVisibleToTrainee = true')
                ->andWhere('(link.visibleFrom IS NULL OR link.visibleFrom <= :now) AND (link.visibleUntil IS NULL OR link.visibleUntil >= :now)')
                ->setParameter('now', $now);
            if ($asset) $query->andWhere('asset = :asset')->setParameter('asset', $asset);
            if ($type === SupportAssignSession::class) {
                $query->join('link.session', 'session')->andWhere('session.entite = :entite');
                if ($session) $query->andWhere('session = :session')->setParameter('session', $session);
                if (!$admin || !$session) {
                    $query->join(Inscription::class, 'enrolment', 'WITH', 'enrolment.session = session AND enrolment.entite = :entite AND enrolment.stagiaire = :user')
                        ->andWhere('enrolment.status != :cancelled')->setParameter('cancelled', StatusInscription::ANNULE->value)->setParameter('user', $user);
                }
            } else {
                $query->andWhere('link.user = :user')->setParameter('user', $user);
            }
            foreach ($query->getQuery()->getResult() as $link) $assets[$link->getAsset()->getId()] = $link->getAsset();
        }
        usort($assets, static fn(SupportAsset $a, SupportAsset $b): int => $b->getUploadedAt() <=> $a->getUploadedAt());
        return array_values($assets);
    }
}
