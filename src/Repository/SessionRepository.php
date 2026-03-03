<?php
// src/Repository/SessionRepository.php

namespace App\Repository;

use App\Entity\Session;
use App\Entity\Entite;
use App\Filter\FormationsFilter;
use App\Enum\StatusSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    /**
     * @return Session[]
     */
    public function searchCalendar(FormationsFilter $filter, int $limit = 300): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('DISTINCT s, formation, engin, site, jour')
            ->leftJoin('s.formation', 'formation')
            ->leftJoin('s.engin', 'engin')
            ->leftJoin('s.site', 'site')
            ->innerJoin('s.jours', 'jour')
            ->orderBy('jour.dateDebut', 'ASC')
            // 🔴 ICI : grand public = uniquement PUBLISHED
            ->andWhere('s.status = :statusPublic')
            ->setParameter('statusPublic', StatusSession::PUBLISHED);
        // ->setParameter('statusPublic', StatusSession::PUBLISHED->value); // ⬅️ si ta colonne est un string simple

        // Destination (Site)
        if ($filter->destinationId) {
            $qb->andWhere('site.id = :destId')
                ->setParameter('destId', $filter->destinationId);
        }


        // Types d'engin
        if ($filter->hasEnginTypes()) {
            $qb->andWhere('engin.type IN (:bt)')
                ->setParameter('bt', $filter->getEnginTypeEnums());
        }

        // Dates (inchangé)
        if ($filter->from && $filter->to) {
            $from = (clone $filter->from)->setTime(0, 0, 0);
            $to   = (clone $filter->to)->setTime(23, 59, 59);

            $qb->andWhere('jour.dateDebut <= :to')
                ->andWhere('jour.dateFin >= :from')
                ->setParameter('from', $from)
                ->setParameter('to', $to);
        } elseif ($filter->from) {
            $from = (clone $filter->from)->setTime(0, 0, 0);

            $qb->andWhere('jour.dateFin >= :from')
                ->setParameter('from', $from);
        } elseif ($filter->to) {
            $to = (clone $filter->to)->setTime(23, 59, 59);

            $qb->andWhere('jour.dateDebut <= :to')
                ->setParameter('to', $to);
        }

        return $qb
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }


    public function findNextPublishedSessionsForFormations(array $formations): array
    {
        if (!$formations) {
            return [];
        }

        $today = new \DateTimeImmutable('today');

        // On part de SessionJour car c'est lui qui possède dateDebut
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.formation', 'f')
            ->innerJoin('s.jours', 'j')
            ->andWhere('f IN (:formations)')
            ->andWhere('s.status = :published')
            ->andWhere('j.dateDebut >= :today')

            // IMPORTANT : on veut uniquement les sessions dont le "premier jour" est minimal,
            // et en plus on veut la plus proche >= today (par formation).
            ->andWhere('j.dateDebut = (
            SELECT MIN(j2.dateDebut)
            FROM App\Entity\SessionJour j2
            WHERE j2.session = s
        )')
            ->andWhere('j.dateDebut = (
            SELECT MIN(j3.dateDebut)
            FROM App\Entity\SessionJour j3
            JOIN j3.session s3
            WHERE s3.formation = f
              AND s3.status = :published
              AND j3.dateDebut >= :today
              AND j3.dateDebut = (
                  SELECT MIN(j4.dateDebut)
                  FROM App\Entity\SessionJour j4
                  WHERE j4.session = s3
              )
        )')

            ->setParameter('formations', $formations)
            ->setParameter('published', StatusSession::PUBLISHED)
            ->setParameter('today', $today)

            ->addSelect('j'); // optionnel, utile si tu veux éviter du lazy-load
        ;

        return $qb->getQuery()->getResult();
    }


    /** @return Session[] */
    public function findByEntiteAndIds(Entite $entite, array $ids): array
    {
        if (!$ids) return [];

        return $this->createQueryBuilder('s')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->andWhere('s.id IN (:ids)')->setParameter('ids', $ids)
            ->leftJoin('s.jours', 'j')->addSelect('j')
            ->leftJoin('s.inscriptions', 'i')->addSelect('i')
            ->leftJoin('i.stagiaire', 'u')->addSelect('u')
            ->leftJoin('i.entreprise', 'em')->addSelect('em')
            ->leftJoin('s.formation', 'f')->addSelect('f')
            ->getQuery()->getResult();
    }

    /** @return Session[] */
    public function findByEntiteAndPeriodViaJours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Session dont au moins un jour intersecte la période
        return $this->createQueryBuilder('s')
            ->leftJoin('s.jours', 'j')->addSelect('j')
            ->leftJoin('s.inscriptions', 'i')->addSelect('i')
            ->leftJoin('i.stagiaire', 'u')->addSelect('u')
            ->leftJoin('i.entreprise', 'em')->addSelect('em')
            ->leftJoin('s.formation', 'f')->addSelect('f')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->andWhere('j.dateDebut <= :to AND j.dateFin >= :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.dateDebut', 'ASC')
            ->getQuery()->getResult();
    }
}
