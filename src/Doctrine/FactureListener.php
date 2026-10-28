<?php
// src/Doctrine/FactureListener.php
namespace App\Doctrine;

use App\Entity\Facture;
use App\Service\Sequence\FactureNumberGenerator;
use App\Service\EInvoicing\EInvoiceReadiness;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Facture::class)]
class FactureListener
{
    public function __construct(private FactureNumberGenerator $generator, private EInvoiceReadiness $readiness) {}

    public function prePersist(Facture $f): void
    {
        $entite = $f->getEntite();
        if (!$entite?->getId()) {
            throw new \LogicException('Impossible de générer le numéro de facture : émetteur manquant.');
        }

        // Also guard direct Doctrine writes, including an explicitly supplied number.
        // Existing invoices are never checked here: prePersist only concerns new records.
        $this->readiness->assertCanIssue($entite);
        if ($f->getNumero()) {
            return;
        }

        $f->setNumero($this->generator->nextForEntite($entite->getId(), (int) $f->getDateEmission()->format('Y')));
    }
}
