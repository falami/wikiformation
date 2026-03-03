<?php

namespace App\Service\Elearning;

use App\Entity\Elearning\ElearningEnrollment;
use App\Entity\Elearning\ElearningNode;
use App\Entity\Elearning\ElearningNodeProgress;
use Doctrine\ORM\EntityManagerInterface;

final class ElearningProgressCalculator
{
  public function __construct(private EntityManagerInterface $em) {}

  /**
   * Recalcule et écrit $enroll->progressPct (0..100)
   * en se basant sur les nodes visibles (publiés + parent publié si sous-chapitre).
   */
  public function recomputeEnrollmentProgress(ElearningEnrollment $enroll): void
  {
    $course = $enroll->getCourse();
    if (!$course) {
      $enroll->setProgressPct(0);
      $enroll->setCompletedAt(null);
      return;
    }

    // total "visibleNodes" = node publié ET (parent null OU parent publié)
    $total = (int) $this->em->createQueryBuilder()
      ->select('COUNT(n.id)')
      ->from(ElearningNode::class, 'n')
      ->leftJoin('n.parent', 'p')
      ->andWhere('n.course = :c')
      ->andWhere('n.isPublished = true')
      ->andWhere('p.id IS NULL OR p.isPublished = true')
      ->setParameter('c', $course)
      ->getQuery()
      ->getSingleScalarResult();

    if ($total <= 0) {
      $enroll->setProgressPct(0);
      $enroll->setCompletedAt(null);
      return;
    }

    // nodes complétés pour cet enrollment
    $done = (int) $this->em->createQueryBuilder()
      ->select('COUNT(pr.id)')
      ->from(ElearningNodeProgress::class, 'pr')
      ->join('pr.node', 'n')
      ->leftJoin('n.parent', 'p')
      ->andWhere('pr.enrollment = :e')
      ->andWhere('pr.completedAt IS NOT NULL')
      ->andWhere('n.course = :c')
      ->andWhere('n.isPublished = true')
      ->andWhere('p.id IS NULL OR p.isPublished = true')
      ->setParameter('e', $enroll)
      ->setParameter('c', $course)
      ->getQuery()
      ->getSingleScalarResult();

    $pct = (int) floor(($done / $total) * 100);
    $pct = max(0, min(100, $pct));

    $enroll->setProgressPct($pct);
    $enroll->setCompletedAt($pct === 100 ? new \DateTimeImmutable() : null);
  }
}
