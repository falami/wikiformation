<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\{DossierInscription, Entite, Inscription, PieceDossier};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Checks the complete ownership chain, including inconsistent legacy associations. */
final class DossierDocumentAccess
{
    public static function inscription(Entite $entite, Inscription $inscription): void
    {
        if ($inscription->getEntite()?->getId() !== $entite->getId()
            || $inscription->getSession()?->getEntite()?->getId() !== $entite->getId()) {
            throw new NotFoundHttpException();
        }
    }

    public static function dossier(Entite $entite, DossierInscription $dossier, Inscription $inscription): void
    {
        self::inscription($entite, $inscription);
        if ($dossier->getEntite()?->getId() !== $entite->getId()
            || $dossier->getInscription()?->getId() !== $inscription->getId()) {
            throw new NotFoundHttpException();
        }
    }

    public static function piece(Entite $entite, PieceDossier $piece): Inscription
    {
        $dossier = $piece->getDossier();
        $inscription = $dossier?->getInscription();
        if (!$dossier || !$inscription || $piece->getEntite()?->getId() !== $entite->getId()
            || ($piece->getInscription() && $piece->getInscription()->getId() !== $inscription->getId())) {
            throw new NotFoundHttpException();
        }
        self::dossier($entite, $dossier, $inscription);
        return $inscription;
    }

    public static function filePath(string $directory, string $filename): string
    {
        if ($filename === '' || $filename !== basename($filename) || str_contains($filename, '\\') || str_contains($filename, "\0")) {
            throw new NotFoundHttpException();
        }
        return rtrim($directory, '/\\') . '/' . $filename;
    }
}
