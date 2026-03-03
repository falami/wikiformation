<?php
// src/Doctrine/AvoirListener.php
namespace App\Doctrine;

use App\Entity\Avoir;
use App\Service\Sequence\AvoirNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Avoir::class)]
class AvoirListener
{
    public function __construct(private AvoirNumberGenerator $generator) {}

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

        $a->setNumero($this->generator->nextForEntite($entite->getId()));
    }
}
