<?php

namespace App\Service\Planning;

use App\Entity\{Session, SessionJour};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityManagerInterface;

final class SessionPlanningValidator
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<string> Erreurs avant tout enregistrement des créneaux. */
    public function errors(Session $session): array
    {
        $errors = [];
        $slots = $session->getJours()->toArray();
        usort($slots, static fn(SessionJour $a, SessionJour $b) => $a->getDateDebut() <=> $b->getDateDebut());
        $previousEnd = null;
        foreach ($slots as $slot) {
            $start = $slot->getDateDebut();
            $end = $slot->getDateFin();
            if (!$start || !$end || $end <= $start) {
                $errors[] = 'Chaque créneau doit avoir une date de fin postérieure à sa date de début.';
                continue;
            }
            if ($slot->getPauseMinutes() !== null && ($slot->getPauseMinutes() < 0 || $slot->getPauseMinutes() >= $slot->getDureeBruteMinutes())) {
                $errors[] = 'La pause doit être positive ou nulle et plus courte que son créneau de formation.';
            }
            if ($previousEnd && $start < $previousEnd) $errors[] = 'Les créneaux de la session se chevauchent. Séparez la matinée et l’après-midi avec leurs horaires respectifs.';
            $previousEnd = max($previousEnd ?? $end, $end);
            $trainer = $slot->getFormateur() ?? $session->getFormateur();
            if (!$trainer) continue;
            if ($trainer->getEntite() !== $session->getEntite()
                && (!$trainer->getEntite()?->getId() || $trainer->getEntite()->getId() !== $session->getEntite()?->getId())) {
                $errors[] = 'Tous les formateurs doivent appartenir à l’organisme de la session.';
                continue;
            }
            if (!$trainer->getId() || $session->getStatus() === StatusSession::CANCELED) continue;
            $qb = $this->em->getRepository(SessionJour::class)->createQueryBuilder('j')
                ->join('j.session', 's')
                ->andWhere('s.entite = :e')->setParameter('e', $session->getEntite())
                ->andWhere('s.status != :canceled')->setParameter('canceled', StatusSession::CANCELED)
                ->andWhere('j.formateur = :trainer OR (j.formateur IS NULL AND s.formateur = :trainer)')->setParameter('trainer', $trainer)
                ->andWhere('j.dateDebut < :end AND j.dateFin > :start')->setParameter('end', $end)->setParameter('start', $start)
                ->setMaxResults(1);
            if ($session->getId()) $qb->andWhere('s.id != :current')->setParameter('current', $session->getId());
            if ($qb->getQuery()->getOneOrNullResult()) {
                $user = $trainer->getUtilisateur();
                $errors[] = sprintf('%s est déjà affecté à une autre session sur le créneau du %s au %s.',
                    trim(($user?->getPrenom() ?? '') . ' ' . ($user?->getNom() ?? '')) ?: 'Ce formateur',
                    $start->format('d/m/Y H:i'), $end->format('d/m/Y H:i'));
            }
        }
        return array_values(array_unique($errors));
    }
}
