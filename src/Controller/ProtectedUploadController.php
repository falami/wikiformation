<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{Attestation, ContratFormateur, ContratStagiaire, ConventionContrat, Depense, Devis, Emargement, Entite, EntitePreferences, Entreprise, EntrepriseDocument, Paiement, Utilisateur, UtilisateurEntite};
use App\Security\DossierDocumentAccess;
use App\Security\Permission\TenantPermission;
use App\Service\Delegation\DossierAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, ResponseHeaderBag};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reads legacy uploads through the same tenant and dossier boundaries as the portals. */
final class ProtectedUploadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DossierAccess $dossiers,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    // Configured without a locale prefix: existing asset URLs keep working.
    public function __invoke(string $directory, string $file): BinaryFileResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur || !$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw $this->createNotFoundException();
        }
        if (!in_array($directory, ['emargements', 'proofs', 'depense', 'signatures/organisme', 'tampons/organisme', 'entreprise/docs', 'pdf', 'devis', 'conventions'], true)
            || str_contains($file, '\\') || str_contains($file, "\0")
            || preg_match('~(^|/)\.{1,2}(/|$)~', $file)) {
            throw $this->createNotFoundException();
        }
        $relative = 'uploads/'.$directory.'/'.$file;
        $records = $this->records($directory, $file, $relative);
        // No guessing from the file name: an orphan or ambiguous archive is never public.
        if (count($records) !== 1 || !$this->mayRead($records[0], $user)) {
            throw $this->createNotFoundException();
        }
        $root = realpath($this->projectDir.'/public/uploads/'.$directory);
        $path = realpath($this->projectDir.'/public/'.$relative);
        if (!$root || !$path || !is_file($path) || !str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw $this->createNotFoundException();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $inline = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");
        return $response;
    }

    private function records(string $directory, string $file, string $relative): array
    {
        $paths = [$relative, '/'.$relative];
        if ($directory === 'emargements') return $this->em->getRepository(Emargement::class)->findBy(['signaturePath' => $paths]);
        if ($directory === 'proofs') return $this->em->getRepository(Paiement::class)->findBy(['justificatif' => $file]);
        if ($directory === 'depense') return $this->em->getRepository(Depense::class)->findBy(['justificatifPath' => [...$paths, $file]]);
        if ($directory === 'signatures/organisme') return $this->em->getRepository(EntitePreferences::class)->findBy(['signatureOrganismePath' => $paths]);
        if ($directory === 'tampons/organisme') return $this->em->getRepository(EntitePreferences::class)->findBy(['tamponOrganismePath' => $paths]);
        if ($directory === 'entreprise/docs') return $this->em->getRepository(EntrepriseDocument::class)->findBy(['filename' => $file]);
        $types = match ($directory) {
            'devis' => [Devis::class],
            'conventions' => [ConventionContrat::class],
            default => [Attestation::class, ContratFormateur::class, ContratStagiaire::class, ConventionContrat::class, Devis::class],
        };
        $records = [];
        foreach ($types as $type) {
            array_push($records, ...$this->em->getRepository($type)->findBy(['pdfPath' => [...$paths, $file]]));
        }
        return $records;
    }

    private function mayRead(object $record, Utilisateur $user): bool
    {
        $entite = $record->getEntite();
        if (!$entite instanceof Entite || !$this->consistent($record, $entite)) return false;
        if ($this->isGranted(TenantPermission::ADMIN, $entite)) return true;

        if ($record instanceof Paiement || $record instanceof Depense || $record instanceof EntitePreferences) return false;
        if ($record instanceof Emargement) {
            return ($this->isGranted(TenantPermission::STAGIAIRE, $entite) && $record->getUtilisateur()?->getId() === $user->getId())
                || ($this->isGranted(TenantPermission::FORMATEUR, $entite) && $record->getSession()->hasFormateurUtilisateur($user));
        }
        if ($record instanceof EntrepriseDocument) return $this->company($entite, $record->getEntreprise(), $user);
        if ($record instanceof ContratFormateur) {
            return ($this->isGranted(TenantPermission::FORMATEUR, $entite) && $record->getFormateur()?->getUtilisateur()?->getId() === $user->getId())
                || $this->delegated($entite, $user, 'contrats-formateurs', $record->getId());
        }
        if ($record instanceof Attestation || $record instanceof ContratStagiaire) {
            $inscription = $record->getInscription();
            return ($this->isGranted(TenantPermission::STAGIAIRE, $entite) && $inscription->getStagiaire()?->getId() === $user->getId())
                || $this->company($entite, $inscription->getEntreprise(), $user)
                || $this->delegated($entite, $user, 'inscriptions', $inscription->getId());
        }
        if ($record instanceof Devis) {
            return ($this->isGranted(TenantPermission::STAGIAIRE, $entite) && $record->getDestinataire()?->getId() === $user->getId())
                || $this->company($entite, $record->getEntrepriseDestinataire(), $user)
                || $this->delegated($entite, $user, 'devis', $record->getId());
        }
        if ($record instanceof ConventionContrat) {
            if ($this->company($entite, $record->getEntreprise(), $user)
                || $this->delegated($entite, $user, 'conventions', $record->getId())) return true;
            if (!$this->isGranted(TenantPermission::STAGIAIRE, $entite)) return false;
            if ($record->getStagiaire()?->getId() === $user->getId()) return true;
            foreach ($record->getInscriptions() as $inscription) {
                if ($inscription->getStagiaire()?->getId() === $user->getId()) return true;
            }
        }
        return false;
    }

    private function consistent(object $record, Entite $entite): bool
    {
        foreach (['getSession', 'getEntreprise', 'getEntrepriseDestinataire', 'getFormateur', 'getFacture'] as $getter) {
            if (method_exists($record, $getter) && ($related = $record->$getter()) && $related->getEntite()?->getId() !== $entite->getId()) return false;
        }
        $inscriptions = method_exists($record, 'getInscriptions') ? $record->getInscriptions()->toArray() : [];
        if (method_exists($record, 'getInscription')) {
            $inscription = $record->getInscription();
            if (!$inscription && ($record instanceof Attestation || $record instanceof ContratStagiaire)) return false;
            if ($inscription) $inscriptions[] = $inscription;
        }
        try {
            foreach ($inscriptions as $inscription) DossierDocumentAccess::inscription($entite, $inscription);
        } catch (NotFoundHttpException) { return false; }
        return !($record instanceof Emargement || $record instanceof ContratFormateur) || $record->getSession() !== null;
    }

    private function company(Entite $entite, ?Entreprise $company, Utilisateur $user): bool
    {
        return $company && $company->getEntite()?->getId() === $entite->getId()
            && $this->isGranted(TenantPermission::ENTREPRISE, $entite)
            && $user->getEntreprise()?->getId() === $company->getId();
    }

    private function delegated(Entite $entite, Utilisateur $user, string $module, int $id): bool
    {
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $entite]);
        if (!$membership?->isActive() || (!$membership->hasRole(UtilisateurEntite::TENANT_COMMERCIAL)
            && !($membership->hasRole(UtilisateurEntite::TENANT_OPCO) && in_array($module, ['inscriptions', 'conventions'], true)))) return false;
        try { $this->dossiers->grant($membership, $module, $id); return true; }
        catch (NotFoundHttpException) { return false; }
    }
}
