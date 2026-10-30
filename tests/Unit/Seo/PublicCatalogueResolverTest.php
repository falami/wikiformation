<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Entity\{Categorie, Entite, Formation, PublicHost};
use App\Repository\FormationRepository;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use App\Service\Public\{PublicCatalogueResolver, PublicContext};
use PHPUnit\Framework\TestCase;

final class PublicCatalogueResolverTest extends TestCase
{
    public function testCollidingFormationSlugResolvesWithinCurrentHost(): void
    {
        $own = $this->withId(new Entite(), 1);
        $other = $this->withId(new Entite(), 2);
        $host = $this->withId((new PublicHost())->setHost('training.example')->setEntite($own)->setRestrictToAssignedFormations(true), 10);
        $context = new PublicContext(); $context->setPublicHost($host);
        $foreign = (new Formation())->setSlug('excel')->setIsPublic(true)->setEntite($other);
        $unassigned = (new Formation())->setSlug('excel')->setIsPublic(true)->setEntite($own);
        $private = (new Formation())->setSlug('excel')->setIsPublic(false)->setEntite($own)->addPublicHost($host);
        $visible = (new Formation())->setSlug('excel')->setIsPublic(true)->setEntite($own)->addPublicHost($host);
        $formations = $this->createMock(FormationRepository::class);
        $formations->expects(self::once())->method('findBy')->with(['slug' => 'excel', 'isPublic' => true], ['id' => 'ASC'])->willReturn([$foreign, $unassigned, $private, $visible]);
        $resolver = new PublicCatalogueResolver($context, $formations, $this->createMock(EntityManagerInterface::class));
        self::assertSame($visible, $resolver->formationBySlug('excel'));
    }

    public function testGlobalLookupSkipsHiddenCoursesButRetainsDeterministicPublicFallback(): void
    {
        $hidden = (new Formation())->setSlug('excel')->setIsPublic(true)->setExcludeFromGlobalCatalogue(true);
        $first = (new Formation())->setSlug('excel')->setIsPublic(true);
        $second = (new Formation())->setSlug('excel')->setIsPublic(true);
        $formations = $this->createMock(FormationRepository::class);
        $formations->method('findBy')->willReturn([$hidden, $first, $second]);
        $resolver = new PublicCatalogueResolver(new PublicContext(), $formations, $this->createMock(EntityManagerInterface::class));
        self::assertSame($first, $resolver->formationBySlug('excel'));
        $first->setIsPublic(false); $second->setIsPublic(false);
        self::assertNull($resolver->formationBySlug('excel'));
    }

    public function testCollidingCategorySlugAndChildrenCannotSelectAnotherTenant(): void
    {
        $own = $this->withId(new Entite(), 1);
        $other = $this->withId(new Entite(), 2);
        $host = (new PublicHost())->setHost('training.example')->setEntite($own);
        $context = new PublicContext(); $context->setPublicHost($host);
        $foreign = (new Categorie())->setSlug('bureautique')->setEntite($other);
        $visible = (new Categorie())->setSlug('bureautique')->setEntite($own);
        $categories = $this->createMock(EntityRepository::class);
        $categories->method('findBy')->with(['slug' => 'bureautique'], ['id' => 'ASC'])->willReturn([$foreign, $visible]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Categorie::class)->willReturn($categories);
        $resolver = new PublicCatalogueResolver($context, $this->createMock(FormationRepository::class), $em);
        self::assertSame($visible, $resolver->categoryBySlug('bureautique'));
        self::assertFalse($resolver->allowsCategory($foreign));
        $host->setIsActive(false);
        self::assertNull($resolver->categoryBySlug('bureautique'));
    }

    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }
}
