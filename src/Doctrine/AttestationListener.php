<?php

namespace App\Doctrine;

use App\Entity\Attestation;
use App\Service\Sequence\AttestationNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Attestation::class)]
class AttestationListener
{
    public function __construct(private AttestationNumberGenerator $generator) {}

    public function prePersist(Attestation $a): void
    {
        if (!$a->hasNumero()) {
            $entite = $a->getEntite();
            if (!$entite?->getId()) {
                throw new \LogicException('Impossible de générer le numéro : entité manquante.');
            }
            $a->setNumero($this->generator->nextForEntite($entite->getId()));
        }
    }
}
