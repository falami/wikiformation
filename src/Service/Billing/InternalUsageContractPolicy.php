<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Entite;
use App\Entity\Billing\InternalUsageSetup;
use App\Service\EInvoicing\EInvoiceTariff;
use Doctrine\ORM\EntityManagerInterface;

final class InternalUsageContractPolicy
{
    public function __construct(private EntityManagerInterface $em) {}

    public function isProtected(Entite $entite): bool
    {
        // A later identity edit must not turn an existing zero-base contract into a public paid plan.
        return EInvoiceTariff::isInternal($entite)
            || ($entite->getId() && $this->em->getRepository(InternalUsageSetup::class)->findOneBy(['entite' => $entite]) !== null);
    }
}
