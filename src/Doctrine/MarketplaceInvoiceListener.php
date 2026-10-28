<?php
declare(strict_types=1);
namespace App\Doctrine;

use App\Entity\Marketplace\Invoice;
use App\Service\Marketplace\MarketplaceInvoiceReadiness;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Invoice::class)]
final class MarketplaceInvoiceListener
{
    public function __construct(private readonly MarketplaceInvoiceReadiness $readiness) {}

    public function prePersist(Invoice $invoice): void
    {
        // Existing documents remain readable; new inserts never bypass the capability gate.
        $this->readiness->assertAvailable();
    }
}
