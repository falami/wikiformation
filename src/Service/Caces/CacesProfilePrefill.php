<?php

declare(strict_types=1);

namespace App\Service\Caces;

use App\Entity\CacesAttempt;
use Doctrine\ORM\EntityManagerInterface;

/** Suggestions only: each dossier keeps its own explicitly validated snapshot. */
final class CacesProfilePrefill
{
    private const FIELDS = ['birthDate', 'birthPlace', 'address', 'phone', 'company', 'drivingExperience', 'trainingExperience'];

    public function __construct(private EntityManagerInterface $em) {}

    public function profileFor(CacesAttempt $attempt): array
    {
        $saved = $attempt->getProfile();
        if ($saved !== []) {
            return $saved;
        }
        $profile = [];
        $latest = null;
        $candidates = $this->em->getRepository(CacesAttempt::class)->findBy([
            'entite' => $attempt->getEntite(),
            'learner' => $attempt->getLearner(),
            'theorySource' => null,
        ], ['id' => 'DESC']);
        foreach ($candidates as $candidate) {
            if ($candidate === $attempt->getTheoryAttempt()) continue;
            $signature = $candidate->getSignatures()['learner'] ?? null;
            if (!is_array($signature) || empty($signature['at']) || $candidate->getProfile() === []) continue;
            $signedAt = strtotime($signature['at']);
            if ($signedAt === false || ($latest !== null && $signedAt <= $latest)) continue;
            $latest = $signedAt;
            $profile = array_intersect_key($candidate->getProfile(), array_flip(self::FIELDS));
        }
        $learner = $attempt->getLearner();
        return $profile + array_filter([
            'birthDate' => $learner?->getDateNaissance()?->format('Y-m-d'),
            'address' => $learner?->getAdresseComplete(),
            'phone' => $learner?->getTelephone(),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
