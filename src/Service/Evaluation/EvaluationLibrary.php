<?php

declare(strict_types=1);
namespace App\Service\Evaluation;

use App\Entity\{Entite, EvaluationTemplate, EvaluationDossier, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class EvaluationLibrary
{
    public function __construct(private EntityManagerInterface $em) {}

    public function trainerIn(Utilisateur $user, Entite $tenant): bool
    {
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$user, 'entite'=>$tenant]);
        return $membership?->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR);
    }

    public function available(EvaluationTemplate $model, Entite $tenant): bool
    {
        if (!$model->isActive() || $model->getArchivedAt()) return false;
        return $model->getTrainerOwner()
            ? $model->authorizes($tenant) && $this->trainerIn($model->getTrainerOwner(), $tenant)
            : $model->getEntite()?->getId() === $tenant->getId();
    }

    /** Tenant models plus published partner models; private trainer drafts never leak. */
    public function catalog(Entite $tenant, bool $availableOnly = false): array
    {
        $owners = [];
        foreach ($this->em->getRepository(UtilisateurEntite::class)->findBy(['entite'=>$tenant]) as $membership) {
            if ($membership->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) $owners[] = $membership->getUtilisateur()->getId();
        }
        $qb = $this->em->createQueryBuilder()->select('m', 'owner', 'tenant')->from(EvaluationTemplate::class, 'm')
            ->leftJoin('m.trainerOwner', 'owner')->join('m.entite', 'tenant')
            ->where('(m.trainerOwner IS NULL AND m.entite = :tenant) OR (:tenant MEMBER OF m.authorizedEntites AND m.trainerOwner IN (:owners) AND m.active = true AND m.archivedAt IS NULL)')
            ->setParameter('tenant', $tenant)->setParameter('owners', $owners ?: [0])->orderBy('m.id', 'DESC');
        if ($availableOnly) $qb->andWhere('m.active = true AND m.archivedAt IS NULL');
        return $qb->getQuery()->getResult();
    }

    public function locked(EvaluationTemplate $model): bool
    {
        return $model->getAssignedAt() !== null || ($model->getId() && $this->em->getRepository(EvaluationDossier::class)->count(['template'=>$model]) > 0);
    }

    /** Caller must hold the template lock, as does assignment. */
    public function assertEditable(EvaluationTemplate $model): void
    {
        if ($model->getArchivedAt() || $this->locked($model)) throw new \InvalidArgumentException('Ce modèle est figé. Archivez-le ou dupliquez-le pour préparer une nouvelle version.');
    }

    public function save(EvaluationTemplate $model, int $version, string $title, array $schema, bool $active): void
    {
        $error = $this->em->wrapInTransaction(function () use ($model, $version, $title, $schema, $active) {
            if ($model->getId()) {
                $this->em->lock($model, LockMode::PESSIMISTIC_WRITE); $this->em->refresh($model);
                if ($this->locked($model) || $model->getArchivedAt()) return 'Ce modèle est figé : dupliquez-le pour le modifier.';
                if ($version !== $model->getVersion()) return 'Ce modèle a été modifié depuis l’ouverture. Rechargez la page avant de réessayer.';
                $model->setVersion($model->getVersion() + 1);
            }
            $model->setTitle($title)->setSchema($schema)->setActive($active); $this->em->persist($model);
            return null;
        });
        if ($error) throw new \InvalidArgumentException($error);
    }

    public function archive(EvaluationTemplate $model): void
    {
        $this->em->wrapInTransaction(function () use ($model) {
            $this->em->lock($model, LockMode::PESSIMISTIC_WRITE); $this->em->refresh($model);
            $model->archive();
        });
    }

    public function duplicate(EvaluationTemplate $source, Utilisateur $actor): EvaluationTemplate
    {
        $copy = (new EvaluationTemplate())->setEntite($source->getEntite())->setCreateur($actor)
            ->setTrainerOwner($source->getTrainerOwner())->setTitle(mb_substr($source->getTitle(), 0, 170).' — copie')
            ->setSchema($source->getSchema())->setActive(false)->setVersion($source->getVersion() + 1)->setSourceTemplate($source);
        $this->em->persist($copy); $this->em->flush(); return $copy;
    }
}
