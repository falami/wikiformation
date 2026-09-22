<?php

namespace App\Service\Avatar;

use App\Entity\Formateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Résout uniquement une photo locale existante, sans appel à un service d’avatars. */
final class FormateurAvatarResolver
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function publicPath(Formateur $formateur): ?string
    {
        // La fiche formateur peut porter sa propre photo, distincte du compte utilisateur.
        foreach ([
            'formateur' => $formateur->getPhoto(),
            'utilisateur' => $formateur->getUtilisateur()?->getPhoto(),
        ] as $directory => $filename) {
            if (!$filename || basename($filename) !== $filename || str_contains($filename, '\\')) {
                continue;
            }
            $relativeDirectory = 'uploads/photos/' . $directory . '/';
            $base = realpath($this->projectDir . '/public/' . $relativeDirectory);
            $path = realpath($this->projectDir . '/public/' . $relativeDirectory . $filename);
            if ($base !== false && $path !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path)) {
                return $relativeDirectory . rawurlencode($filename);
            }
        }

        return null;
    }
}
