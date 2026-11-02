<?php

namespace App\Doctrine;

use App\Entity\Attestation;
use App\Service\Sequence\AttestationNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;
use App\Service\Sequence\DeferredDocumentNumber;

#[AsEntityListener(event: Events::prePersist, entity: Attestation::class)]
#[AsEntityListener(event: Events::postPersist, entity: Attestation::class)]
class AttestationListener
{
    public function __construct(private AttestationNumberGenerator $generator, private DeferredDocumentNumber $deferred) {}

    public function prePersist(Attestation $a): void
    {
        if (!$a->hasNumero()) {
            $entite = $a->getEntite();
            if (!$entite?->getId()) {
                throw new \LogicException('Impossible de générer le numéro : entité manquante.');
            }
            $a->setNumero($this->deferred->prepare($a));
        }
    }

    public function postPersist(Attestation $a, PostPersistEventArgs $event): void
    {
        $this->deferred->complete($a, $event->getObjectManager(),
            fn(): string => $this->generator->nextForEntite($a->getEntite()->getId()));
    }
}
