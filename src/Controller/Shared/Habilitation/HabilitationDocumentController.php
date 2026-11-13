<?php

declare(strict_types=1);

namespace App\Controller\Shared\Habilitation;

use App\Entity\{Entite, HabilitationDossier, HabilitationRevision};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

use App\Controller\Shared\Habilitation\AbstractHabilitationController;

#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationDocumentController extends AbstractHabilitationController
{

    #[Route('/attestations-selection', name: 'attestations_export', methods: ['POST'])]
    public function exportAttestations(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Habilitation\HabilitationDocument $renderer): Response
    {
        $this->member($entite);
        $this->csrf($request, 'habilitation_export');
        $ids = array_values(array_unique(array_map('intval', $request->request->all('ids'))));
        if (!$ids || count($ids) > 50 || min($ids) < 1) return new Response('Sélectionnez entre 1 et 50 attestations.', 422);
        $documents = [];
        foreach ($ids as $id) {
            $d = $this->em->find(HabilitationDossier::class, $id);
            if (!$d) throw $this->createNotFoundException();
            $rights = $this->access($entite, $d);
            $revision = $d->getAvisToken() ? $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier'=>$d, 'token'=>$d->getAvisToken(), 'kind'=>'avis']) : null;
            if (!$revision || !$this->visibleRevision($revision, $d, $rights)) return new Response('Une attestation sélectionnée n’est plus disponible. Rechargez la liste.', 422);
            $documents[] = $d;
        }
        $path = tempnam(sys_get_temp_dir(), 'hab-export-');
        if ($path === false) throw new \RuntimeException('Impossible de préparer le téléchargement.');
        $zip = new \ZipArchive();
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Impossible de créer l’archive.');
            foreach ($documents as $d) {
                $pdf = $this->renderPdf($entite, $d, $d->getAvisToken(), $renderer, true);
                $name = $d->getInscription()->getStagiaire()->getPrenom().'-'.$d->getInscription()->getStagiaire()->getNom();
                $name = preg_replace('/[^\pL\pN_-]+/u', '-', $name);
                if (!$zip->addFromString('attestation-'.$name.'-'.$d->getId().'.pdf', $pdf->getContent())) throw new \RuntimeException('Impossible d’ajouter le document.');
            }
            if (!$zip->close()) throw new \RuntimeException('Impossible de finaliser l’archive.');
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new \RuntimeException('Impossible de lire l’archive.');
            return new Response($bytes, 200, ['Content-Type'=>'application/zip', 'Content-Disposition'=>'attachment; filename="attestations-electriques.zip"', 'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    #[Route('/{id}/document/{token}', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+', 'token' => '[a-f0-9]{32}'])]
    #[Route('/{id}/attestation/{token}', name: 'attestation', methods: ['GET'], defaults: ['attestation' => true], requirements: ['id' => '\d+', 'token' => '[a-f0-9]{32}'])]
    public function pdf(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, string $token, \App\Service\Habilitation\HabilitationDocument $documents, bool $attestation = false): Response
    {
        return $this->renderPdf($entite, $dossier, $token, $documents, $attestation);
    }
}
