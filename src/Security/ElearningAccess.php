<?php
declare(strict_types=1);

namespace App\Security;

use App\Entity\Elearning\{ElearningEnrollment, ElearningNode};
use App\Enum\EnrollmentStatus;

final class ElearningAccess
{
    public static function enrollmentOpen(ElearningEnrollment $enrollment, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return in_array($enrollment->getStatus(), [EnrollmentStatus::ACTIVE, EnrollmentStatus::COMPLETED], true)
            && (!$enrollment->getStartsAt() || $enrollment->getStartsAt() <= $now)
            && (!$enrollment->getEndsAt() || $enrollment->getEndsAt() >= $now);
    }

    public static function nodePublished(ElearningNode $node): bool
    {
        $courseId = $node->getCourse()?->getId();
        $seen = [];
        do {
            $key = spl_object_id($node);
            if (isset($seen[$key]) || !$node->isPublished() || $node->getCourse()?->getId() !== $courseId) return false;
            $seen[$key] = true;
            $node = $node->getParent();
        } while ($node);
        return true;
    }

    /** Validate the whole projected tree before changing any managed entity. */
    public static function validParents(array $parents): bool
    {
        foreach ($parents as $id => $parent) {
            $seen = [$id => true];
            while ($parent !== null) {
                if (isset($seen[$parent]) || !array_key_exists($parent, $parents)) return false;
                $seen[$parent] = true;
                $parent = $parents[$parent];
            }
        }
        return true;
    }
}
