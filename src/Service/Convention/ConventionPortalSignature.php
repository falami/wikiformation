<?php

declare(strict_types=1);

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, ConventionRevision, Utilisateur};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/** Records authenticated consent against an immutable copy of the document. */
final class ConventionPortalSignature
{
    public function __construct(
        private readonly ConventionDocument $documents,
        private readonly ConventionSignaturePdf $signaturePdf,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function preview(ConventionContrat $convention, Request $request): BinaryFileResponse
    {
        $digest = $this->documents->fingerprint($convention);
        $path = $this->previewPath($convention, $digest);
        if (!is_file($path)) {
            $response = $this->documents->response($convention);
            $bytes = $response instanceof BinaryFileResponse ? file_get_contents($response->getFile()->getPathname()) : $response->getContent();
            if (!$bytes || !str_starts_with($bytes, '%PDF')) throw new \DomainException('Le document original est indisponible.');
            (new Filesystem())->mkdir(dirname($path), 0770);
            $temporary = tempnam(dirname($path), '.preview-');
            if (!$temporary) throw new \RuntimeException('Impossible de préparer le document.');
            try {
                file_put_contents($temporary, $bytes);
                chmod($temporary, 0660);
                if (!@link($temporary, $path) && !is_file($path)) throw new \RuntimeException('Impossible de conserver l’aperçu.');
            } finally { unlink($temporary); }
        }
        $request->getSession()->set('convention_reviewed_'.$convention->getId(), $digest);
        return new BinaryFileResponse($path, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function sign(ConventionContrat $convention, Utilisateur $actor, string $role, ?string $signature = null, ?string $reviewDigest = null): void
    {
        if (!in_array($role, ['stagiaire', 'entreprise'], true)) throw new \InvalidArgumentException('Signataire invalide.');
        if ($role === 'entreprise' && !$signature) throw new \DomainException('Veuillez tracer ou importer votre signature.');
        if ($signature !== null) self::validateSignature($signature);
        $path = null;
        try {
            $this->em->wrapInTransaction(function () use ($convention, $actor, $role, $signature, $reviewDigest, &$path): void {
                $this->em->refresh($convention, LockMode::PESSIMISTIC_WRITE);
                if (($role === 'stagiaire' && $convention->getDateSignatureStagiaire())
                    || ($role === 'entreprise' && $convention->getDateSignatureEntreprise() && $convention->getSignatureDataUrlEntreprise())) return;
                if (($role === 'stagiaire' && $convention->getStagiaire()?->getId() !== $actor->getId())
                    || ($role === 'entreprise' && !\App\Service\Entreprise\EntreprisePortalContext::canRepresent($actor, $convention->getEntreprise()))) {
                    throw new \DomainException('Ce document ne vous est pas destiné.');
                }
                if (!$convention->getPdfPath()) throw new \DomainException('Consultez le document avant de le signer.');
                if (!$reviewDigest || !preg_match('/^[a-f0-9]{64}$/D', $reviewDigest)
                    || !hash_equals($this->documents->fingerprint($convention), $reviewDigest)) {
                    throw new \DomainException('Consultez à nouveau le PDF : le document a changé ou n’a pas encore été ouvert.');
                }
                $preview = $this->previewPath($convention, $reviewDigest);
                $bytes = is_file($preview) ? file_get_contents($preview) : false;
                if (!$bytes || !str_starts_with($bytes, '%PDF')) throw new \DomainException('Le document original est indisponible.');

                // Archive the exact source in the revision and place visible consent in its client box.
                $now = new \DateTimeImmutable();
                $signedBytes = $role !== 'entreprise' || $signature === null ? $bytes : $this->signaturePdf->append($bytes, $convention, $actor, $role, $signature, $now);
                $filename = 'portal-'.$convention->getId().'-'.bin2hex(random_bytes(16)).'.pdf';
                $path = $this->projectDir.'/var/storage/conventions/'.$filename;
                (new Filesystem())->dumpFile($path, $signedBytes);
                chmod($path, 0660);
                if ($role === 'stagiaire') {
                    $convention->setDateSignatureStagiaire($now);
                    if ($signature !== null) $convention->setSignatureDataUrlStagiaire($signature);
                } else {
                    $convention->setDateSignatureEntreprise($now);
                    $convention->setSignatureDataUrlEntreprise($signature);
                }
                $convention->setPdfPath('private:'.$filename);
                $this->em->persist(new ConventionRevision($convention, [
                    'event' => 'portal_signature', 'actorId' => $actor->getId(), 'role' => $role,
                    'signedAt' => $now->format(DATE_ATOM), 'sourceSha256' => hash('sha256', $bytes),
                    'pdf' => $convention->getPdfPath(), 'signedPdfSha256' => hash('sha256', $signedBytes), 'method' => $signature === null ? 'authenticated_confirmation' : 'authenticated_png',
                    'signatureSha256' => $signature === null ? null : hash('sha256', $signature),
                ], 'Signature '.$role.' — original conservé', trim($actor->getPrenom().' '.$actor->getNom()), $bytes, $now));
            });
        } catch (\Throwable $error) {
            if ($path && is_file($path)) unlink($path);
            throw $error;
        }
    }

    private function previewPath(ConventionContrat $convention, string $digest): string
    {
        return $this->projectDir.'/var/storage/conventions/portal-preview-'.$convention->getId().'-'.$digest.'.pdf';
    }

    public static function validateSignature(string $signature): void
    {
        if (strlen($signature) > 750000 || !str_starts_with($signature, 'data:image/png;base64,')) {
            throw new \DomainException('Signature invalide (PNG de 500 Ko maximum).');
        }
        $bytes = base64_decode(substr($signature, 22), true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$size || strlen($bytes) > 500000 || ($size[2] ?? null) !== IMAGETYPE_PNG
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096) {
            throw new \DomainException('La signature doit être une image PNG valide.');
        }
    }
}
