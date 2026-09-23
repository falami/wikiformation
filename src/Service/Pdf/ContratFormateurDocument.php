<?php

namespace App\Service\Pdf;

use App\Entity\ContratFormateur;
use App\Enum\ContratFormateurStatus;
use App\Service\Session\SessionParticipantCount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Resolves the retained document without ever rewriting a signed contract. */
final class ContratFormateurDocument
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private SessionParticipantCount $participantCount,
    ) {}

    public function isFrozen(ContratFormateur $contrat): bool
    {
        return $contrat->getSignatureAt() !== null || $contrat->getSignatureDataUrl() !== null || $contrat->getSignatureOrganismeAt() !== null
            || in_array($contrat->getStatus(), [ContratFormateurStatus::SIGNE, ContratFormateurStatus::ARCHIVE, ContratFormateurStatus::RESILIE], true);
    }

    public function storedPath(ContratFormateur $contrat): ?string
    {
        return $this->publicUploadPath($contrat->getPdfPath());
    }

    private function publicUploadPath(?string $relative): ?string
    {
        if (!$relative) return null;
        $root = realpath($this->projectDir . '/public/uploads');
        $path = realpath($this->projectDir . '/public/' . ltrim($relative, '/'));
        return $root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }

    public function templateData(ContratFormateur $contrat): array
    {
        $session = $contrat->getSession();
        $formateurUser = $contrat->getFormateur()?->getUtilisateur();
        $stagiaires = $session ? $this->participantCount->participants($session, $formateurUser) : [];
        $orgSigDataUri = null;
        $signature = $this->publicUploadPath($contrat->getSignatureOrganismePath() ?: $contrat->getEntite()?->getPreferences()?->getSignatureOrganismePath());
        if ($signature) {
            $mime = mime_content_type($signature);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $orgSigDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($signature));
            }
        }
        return ['entite' => $contrat->getEntite(), 'contrat' => $contrat, 'session' => $session,
            'formation' => $session?->getFormation(), 'stagiaires' => $stagiaires, 'orgSigDataUri' => $orgSigDataUri,
            'effectifStage' => $session ? $this->participantCount->count($session, $formateurUser) : 0,
            'participantsLibres' => $session ? $this->participantCount->freeParticipantNames($session) : [],
        ];
    }
}
