<?php
declare(strict_types=1);
namespace App\Service\Session;
use App\Entity\{Entite, Inscription, SessionJour, SatisfactionAssignment};
use App\Enum\{StatusSession, StatusInscription, TypeFinancement};
use Doctrine\ORM\EntityManagerInterface;

final class PendingLearnerAppreciations
{
    public function __construct(private EntityManagerInterface $em) {}
    public function find(Entite $entite): array
    {
        return $this->em->createQueryBuilder()->select('i', 's', 'u', 'f')
            ->from(Inscription::class, 'i')->join('i.session', 's')->join('i.stagiaire', 'u')->leftJoin('s.formation', 'f')
            ->where('i.entite = :entite')->andWhere('s.entite = :entite')
            ->andWhere('s.status != :cancelled')->andWhere('s.typeFinancement != :subcontract')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('EXISTS (SELECT j.id FROM '.SessionJour::class.' j WHERE j.session = s)')
            ->andWhere('NOT EXISTS (SELECT future.id FROM '.SessionJour::class.' future WHERE future.session = s AND future.dateFin > :now)')
            ->andWhere('NOT EXISTS (SELECT a.id FROM '.SatisfactionAssignment::class.' a JOIN a.attempt attempt WHERE a.session = s AND a.entite = :entite AND a.stagiaire = u AND attempt.submittedAt IS NOT NULL)')
            ->setParameter('entite', $entite)->setParameter('cancelled', StatusSession::CANCELED)
            ->setParameter('subcontract', TypeFinancement::OUI)->setParameter('excluded', [StatusInscription::ANNULE, StatusInscription::ABSENT])
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))
            ->orderBy('s.id', 'DESC')->addOrderBy('i.id', 'ASC')->setMaxResults(301)->getQuery()->getResult();
    }
}
