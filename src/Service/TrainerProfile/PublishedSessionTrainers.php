<?php

declare(strict_types=1);

namespace App\Service\TrainerProfile;

use App\Entity\Session;
use App\Entity\TrainerCv;
use Doctrine\ORM\EntityManagerInterface;

final class PublishedSessionTrainers
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<array{trainer: \App\Entity\Formateur, cv: TrainerCv}> */
    public function forSession(Session $session): array
    {
        $trainers = array_values(array_filter(
            $session->getFormateursEffectifs(),
            static fn ($trainer) => $trainer->getEntite()?->getId() === $session->getEntite()?->getId(),
        ));
        if (!$trainers) {
            return [];
        }

        $rows = [];
        foreach ($this->em->getRepository(TrainerCv::class)->findBy(['formateur' => $trainers]) as $cv) {
            if ($cv->published) {
                $rows[] = ['trainer' => $cv->formateur, 'cv' => $cv];
            }
        }

        return $rows;
    }
}
