<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation\TrainingWorkflow;
use App\Entity\{Inscription, Utilisateur};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stores an existing, validated professional certificate; never fabricates one. */
final class WorkflowCertificateStorage
{
    public function __construct(#[Autowire('%kernel.project_dir%/var/storage/workflow-certificates')] private readonly string $directory) {}

    /** Caller authorizes the actor and holds the inscription database lock. Returns the new path for rollback cleanup. */
    public function store(UploadedFile $file, TrainingWorkflow $workflow, Inscription $inscription, Utilisateur $actor, \DateTimeImmutable $issuedOn, ?string $reason = null): string
    {
        if (!$inscription->getId() || !$actor->getId() || $workflow->getEntite()?->getId() !== $inscription->getEntite()?->getId()
            || $workflow->getSession()?->getId() !== $inscription->getSession()?->getId()) throw new \DomainException('Le certificat ne correspond pas à ce dossier.');
        $belongs = false;
        foreach ($workflow->getConvention()->getInscriptions() as $candidate) if ($candidate->getId() === $inscription->getId()) $belongs = true;
        if (!$belongs) throw new \DomainException('Le participant ne correspond pas à cette convention.');
        $issuedOn = WorkflowTime::date($issuedOn->format('Y-m-d'));
        if ($issuedOn > WorkflowTime::date('today') || $issuedOn < WorkflowTime::date('1900-01-01')) throw new \DomainException('La date de délivrance doit être une date passée ou celle du jour.');
        if (!$file->isValid() || $file->getSize() > 10 * 1024 * 1024 || strtolower($file->getClientOriginalExtension()) !== 'pdf'
            || $file->getMimeType() !== 'application/pdf' || file_get_contents($file->getPathname(), false, null, 0, 5) !== '%PDF-') throw new \DomainException('Déposez un document PDF valide de 10 Mo maximum.');
        $meta = $inscription->getMeta() ?? [];
        $old = is_array($meta['workflowCertificate'] ?? null) ? $meta['workflowCertificate'] : null;
        $reason = trim($reason ?? '');
        if ($old && $reason === '') throw new \DomainException('Indiquez le motif de remplacement du certificat.');
        if (mb_strlen($reason) > 500) throw new \DomainException('Le motif ne doit pas dépasser 500 caractères.');
        $history = $old['history'] ?? [];
        if ($old) { unset($old['history']); $history[] = $old; }
        (new Filesystem())->mkdir($this->directory, 0770);
        $filename = bin2hex(random_bytes(24)).'.pdf';
        $file->move($this->directory, $filename);
        $path = $this->directory.'/'.$filename; @chmod($path, 0660);
        $meta['workflowCertificate'] = [
            'file' => $filename, 'hash' => hash_file('sha256', $path), 'issuedOn' => $issuedOn->format('Y-m-d'),
            'version' => (int) ($old['version'] ?? 0) + 1, 'validatedBy' => $actor->getId(), 'validatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'inscriptionId' => $inscription->getId(), 'entiteId' => $inscription->getEntite()->getId(), 'workflowId' => $workflow->getId(),
            'reason' => $reason ?: null, 'history' => $history,
        ];
        $inscription->setMeta($meta);
        return $path;
    }

    /** @return array{path:string,hash:string,issuedOn:string,version:int,validatedBy:int,validatedAt:string}|null */
    public function getValidated(Inscription $inscription): ?array
    {
        $meta = $inscription->getMeta()['workflowCertificate'] ?? null;
        return is_array($meta) ? $this->validatedVersion($inscription, $meta) : null;
    }

    /** Admin-only history downloads use the same path, identity and integrity checks. */
    public function getVersion(Inscription $inscription, int $version): ?array
    {
        $current = $inscription->getMeta()['workflowCertificate'] ?? null;
        if (!is_array($current)) return null;
        foreach (array_merge([$current], $current['history'] ?? []) as $item) {
            if (is_array($item) && ($item['version'] ?? null) === $version) return $this->validatedVersion($inscription, $item);
        }
        return null;
    }

    private function validatedVersion(Inscription $inscription, array $meta): ?array
    {
        if (($meta['entiteId'] ?? null) !== $inscription->getEntite()?->getId() || ($meta['inscriptionId'] ?? null) !== $inscription->getId()
            || !is_int($meta['validatedBy'] ?? null) || empty($meta['validatedAt']) || !is_string($meta['hash'] ?? null)
            || preg_match('/^[a-f0-9]{48}\.pdf$/D', (string) ($meta['file'] ?? '')) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($meta['issuedOn'] ?? ''), new \DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d') !== $meta['issuedOn'] || $date > WorkflowTime::date('today')) return null;
        $root = realpath($this->directory); $path = $root ? realpath($root.'/'.$meta['file']) : false;
        if (!$root || !$path || !is_file($path) || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !hash_equals($meta['hash'], hash_file('sha256', $path))) return null;
        return ['path'=>$path, 'hash'=>$meta['hash'], 'issuedOn'=>$meta['issuedOn'], 'version'=>(int)$meta['version'], 'validatedBy'=>$meta['validatedBy'], 'validatedAt'=>$meta['validatedAt']];
    }
}
