<?php

declare(strict_types=1);

namespace App\Service\Public;

use App\Entity\{Categorie, Formation};
use App\Repository\FormationRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Slugs are unique per organisation, so public lookup must consider the current host. */
final class PublicCatalogueResolver
{
    public function __construct(
        private readonly PublicContext $context,
        private readonly FormationRepository $formations,
        private readonly EntityManagerInterface $em,
    ) {}

    public function formationBySlug(string $slug): ?Formation
    {
        foreach ($this->formations->findBy(['slug' => $slug, 'isPublic' => true], ['id' => 'ASC']) as $formation) {
            if ($this->context->allowsFormation($formation)) return $formation;
        }
        return null;
    }

    public function categoryBySlug(string $slug): ?Categorie
    {
        foreach ($this->em->getRepository(Categorie::class)->findBy(['slug' => $slug], ['id' => 'ASC']) as $category) {
            if ($this->allowsCategory($category)) return $category;
        }
        return null;
    }

    public function allowsCategory(Categorie $category): bool
    {
        $host = $this->context->getPublicHost();
        if (!$host) return true;
        if (!$host->isActive()) return false;
        if ($host->isShowAllPublicFormations()) return true;

        $entiteId = $host->getEntite()?->getId();
        return $entiteId !== null && $category->getEntite()?->getId() === $entiteId;
    }
}
