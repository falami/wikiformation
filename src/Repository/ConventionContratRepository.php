<?php
// src/Repository/ConventionContratRepository.php
namespace App\Repository;

use App\Entity\ConventionContrat;
use App\Entity\Inscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ConventionContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConventionContrat::class);
    }

    public function findOneForInscription(Inscription $inscription): ?ConventionContrat
    {
        $session = $inscription->getSession();
        $entite  = $session?->getEntite();

        if (!$session || !$entite) {
            return null;
        }

        // ✅ CAS ENTREPRISE : 1 convention partagée
        if ($inscription->getEntreprise()) {
            return $this->findOneBy([
                'session'    => $session,
                'entite'     => $entite,
                'entreprise' => $inscription->getEntreprise(),
            ]);
        }

        // ✅ CAS INDIVIDUEL : 1 convention par stagiaire
        if ($inscription->getStagiaire()) {
            return $this->findOneBy([
                'session'   => $session,
                'entite'    => $entite,
                'stagiaire' => $inscription->getStagiaire(),
            ]);
        }

        return null;
    }
}
