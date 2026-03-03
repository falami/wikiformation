<?php

namespace App\Service\Slug;

use App\Repository\FormationRepository;
use App\Repository\Elearning\ElearningCourseRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class UniqueSlugger
{
  private AsciiSlugger $slugger;

  public function __construct(
    private FormationRepository $formationRepo,
    private ElearningCourseRepository $elearningCourseRepo,
  ) {
    $this->slugger = new AsciiSlugger('fr');
  }

  public function uniqueFormationSlug(int $entiteId, string $base, ?int $excludeId = null): string
  {
    return $this->uniqueSlug(
      $base,
      fn(string $slug) => $this->formationRepo->slugExistsForEntite($entiteId, $slug, $excludeId),
      'formation'
    );
  }

  public function uniqueElearningCourseSlug(int $entiteId, string $base, ?int $excludeId = null): string
  {
    return $this->uniqueSlug(
      $base,
      fn(string $slug) => $this->elearningCourseRepo->slugExistsForEntite($entiteId, $slug, $excludeId),
      'course'
    );
  }

  private function uniqueSlug(string $base, callable $existsFn, string $fallback): string
  {
    $base = trim($base);
    $slugBase = strtolower((string) $this->slugger->slug($base));
    $slugBase = preg_replace('/-+/', '-', $slugBase) ?: $fallback;

    $slug = $slugBase;
    $i = 2;

    while ($existsFn($slug)) {
      $slug = $slugBase . '-' . $i;
      $i++;
      if ($i > 5000) {
        $slug = $slugBase . '-' . uniqid();
        break;
      }
    }

    return $slug;
  }
}
