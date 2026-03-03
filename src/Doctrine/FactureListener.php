<?php
// src/Doctrine/FactureListener.php
namespace App\Doctrine;

use App\Entity\Facture;
use App\Service\Sequence\FactureNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Facture::class)]
class FactureListener
{
    public function __construct(private FactureNumberGenerator $generator) {}

    public function prePersist(Facture $f): void
    {
        // Si déjà fixé (import, migration…), on ne touche pas :
        if ($f->getNumero()) {
            return;
        }

        $entite = $f->getEntite();
        if (!$entite?->getId()) {
            throw new \LogicException('Impossible de générer le numéro de facture : émetteur manquant.');
        }

        $f->setNumero($this->generator->nextForEntite($entite->getId()));
    }
}
