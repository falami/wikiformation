<?php
// src/Doctrine/AvoirListener.php
namespace App\Doctrine;

use App\Entity\Avoir;
use App\Service\Sequence\AvoirNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;
use App\Service\Sequence\DeferredDocumentNumber;

#[AsEntityListener(event: Events::prePersist, entity: Avoir::class)]
#[AsEntityListener(event: Events::postPersist, entity: Avoir::class)]
class AvoirListener
{
    public function __construct(private AvoirNumberGenerator $generator, private DeferredDocumentNumber $deferred) {}

    public function prePersist(Avoir $a): void
    {
        // ✅ surtout pas getNumero() ici
        if ($a->hasNumero()) {
            return; // import manuel, etc.
        }

        // 1) entité directement posée sur l’avoir (ton contrôleur le fait)
        $entite = $a->getEntite();

        // 2) sinon, on tente via la facture d’origine -> émetteur
        if (!$entite) {
            $entite = $a->getFactureOrigine()?->getEntite(); // <- nom réel sur Facture
        }

        if (!$entite?->getId()) {
            throw new \LogicException("Impossible de générer le numéro d’avoir : entité/émetteur manquant.");
        }

        $a->setNumero($this->deferred->prepare($a));
    }

    public function postPersist(Avoir $a, PostPersistEventArgs $event): void
    {
        $this->deferred->complete($a, $event->getObjectManager(),
            fn(): string => $this->generator->nextForEntite(($a->getEntite() ?? $a->getFactureOrigine()?->getEntite())->getId(), (int) $a->getDateEmission()->format('Y')));
    }
}
