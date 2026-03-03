<?php // src/Repository/Billing/EntiteUsageYearRepository.php
namespace App\Repository\Billing;

use App\Entity\Billing\EntiteUsageYear;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EntiteUsageYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntiteUsageYear::class);
    }

    public function getOrCreate(Entite $entite, int $year): EntiteUsageYear
    {
        $obj = $this->findOneBy(['entite' => $entite, 'year' => $year]);
        return $obj ?? new EntiteUsageYear($entite, $year);
    }
}
