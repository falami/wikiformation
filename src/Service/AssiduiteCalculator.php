<?php
namespace App\Service;

use App\Entity\Inscription;
use App\Entity\Emargement;
use Doctrine\ORM\EntityManagerInterface;

class AssiduiteCalculator
{
    public function __construct(private EntityManagerInterface $em){}

    /** Retourne un float entre 0 et 100, et met à jour Inscription.tauxAssiduite */
    public function computeForInscription(Inscription $inscription): float
    {
        $session = $inscription->getSession();
        $jours = $session->getJours(); // Collection<SessionJour>
        $demiJourneesPrevues = 0;
        foreach ($jours as $j) {
            // Si tu supportes des sessions 1/2 journée spéciales, adapte ici
            $demiJourneesPrevues += 2;
        }

        if ($demiJourneesPrevues === 0) {
            $inscription->setTauxAssiduite(0);
            return 0.0;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Emargement::class, 'e')
            ->where('e.session = :session')
            ->andWhere('e.utilisateur = :user')
            ->andWhere('e.signedAt IS NOT NULL')
            ->setParameter('session',  $session)
            ->setParameter('user', $inscription->getStagiaire());

        $demiJourneesSignees = (int)$qb->getQuery()->getSingleScalarResult();
        $assiduite = round(($demiJourneesSignees / $demiJourneesPrevues) * 100, 1);
        $inscription->setTauxAssiduite($assiduite);

        return $assiduite;
    }
}
