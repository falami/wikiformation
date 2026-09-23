<?php

namespace App\Service\Contrat;

use App\Entity\{ContratFormateur, ContratFormateurRevision, Utilisateur};
use App\Service\Pdf\{ContratFormateurDocument, PdfManager};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class ContratFormateurVersioning
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContratFormateurDocument $document,
        private PdfManager $pdf,
        private Environment $twig,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    public function assertCurrent(ContratFormateur $contrat, int $expectedLock): void
    {
        if ($expectedLock !== $contrat->getLockVersion()) {
            throw new \DomainException('Ce contrat a été modifié dans une autre fenêtre. Rechargez la page avant de continuer.');
        }
        $this->em->lock($contrat, LockMode::OPTIMISTIC, $expectedLock);
    }

    /** Archive the unmodified object; $current is the managed target of the new version. */
    public function archive(ContratFormateur $before, ContratFormateur $current, Utilisateur $author, string $reason): ContratFormateurRevision
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) throw new \DomainException('Indiquez le motif de la modification (1 à 1 000 caractères).');
        if ($before->getId() !== $current->getId() || $before->getEntite()?->getId() !== $current->getEntite()?->getId()) throw new \LogicException('Contrat incohérent.');

        $stored = $this->document->storedPath($before);
        if ($before->getStatus() === \App\Enum\ContratFormateurStatus::BROUILLON && !$this->document->isFrozen($before)) $stored = null;
        if ($this->document->isFrozen($before) && !$stored) {
            throw new \DomainException('Le PDF original est introuvable. Restaurez ce document avant de créer une nouvelle version, afin de conserver la version signée.');
        }
        $data = $this->document->templateData($before);
        // Un brouillon suit le planning actuel : conserver l’aperçu effectivement
        // présenté, plutôt qu’un ancien export devenu obsolète. Les documents
        // déjà envoyés ou signés conservent leurs octets d’origine.
        $keepStored = $stored && ($this->document->isFrozen($before) || $before->getStatus() !== \App\Enum\ContratFormateurStatus::BROUILLON);
        $bytes = $keepStored ? file_get_contents($stored) : $this->pdf->createPortraitBytes($this->twig->render('pdf/contrat_formateur.html.twig', $data));
        if (!$bytes) throw new \RuntimeException('Impossible de conserver le PDF de la version précédente.');
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Le dossier des versions est indisponible.');
        $filename = bin2hex(random_bytes(24)) . '.pdf';
        if (file_put_contents($directory . '/' . $filename, $bytes, LOCK_EX) === false) throw new \RuntimeException('Impossible de conserver le PDF.');
        $fields = ['numero', 'conditionsGenerales', 'conditionsParticulieres', 'montantPrevuCents', 'fraisMissionCents', 'tauxTva', 'numeroTvaIntra',
            'clauseEmploi', 'clauseEngagement', 'clauseObjet', 'clauseObligations', 'clauseNonConcurrence', 'clauseInexecution', 'clauseAssurance', 'clauseFinContrat', 'clauseProprieteIntellectuelle',
            'signatureDataUrl', 'signatureIp', 'signatureUserAgent', 'signatureOrganismePath', 'signatureOrganismeNom', 'signatureOrganismeFonction', 'signatureOrganismeIp', 'signatureOrganismeUserAgent'];
        $snapshot = [];
        foreach ($fields as $field) $snapshot[$field] = $before->{'get' . ucfirst($field)}();
        $snapshot += [
            'status' => $before->getStatus()->value, 'assujettiTva' => $before->isAssujettiTva(),
            'dateCreation' => $before->getDateCreation()?->format(DATE_ATOM),
            'signatureAt' => $before->getSignatureAt()?->format(DATE_ATOM),
            'signatureOrganismeAt' => $before->getSignatureOrganismeAt()?->format(DATE_ATOM),
            'signatureOrganismePar' => $before->getSignatureOrganismePar()?->getId(),
            'sessionId' => $before->getSession()?->getId(), 'sessionCode' => $before->getSession()?->getCode(),
            'formation' => $before->getSession()?->getFormationLabel(), 'formateurId' => $before->getFormateur()?->getId(),
            'formateur' => trim($before->getFormateur()?->getUtilisateur()?->getPrenom() . ' ' . $before->getFormateur()?->getUtilisateur()?->getNom()),
            'heures' => $before->getSession()?->getNombreHeuresPourFormateur($before->getFormateur()), 'effectif' => $data['effectifStage'],
            'creneaux' => array_map(static fn ($jour) => ['debut' => $jour->getDateDebut()?->format(DATE_ATOM), 'fin' => $jour->getDateFin()?->format(DATE_ATOM), 'pauseMinutes' => $jour->getPauseEffectiveMinutes(), 'heures' => $jour->getDureeFormationHeures()], $before->getSession()?->getJoursPourFormateur($before->getFormateur()) ?? []),
        ];
        $revision = new ContratFormateurRevision($current, $before->getVersionNumero(), $snapshot, $filename, hash('sha256', $bytes), $reason, trim($author->getPrenom() . ' ' . $author->getNom()) ?: $author->getEmail(), $author);
        $this->em->persist($revision);
        $current->incrementVersion();
        $current->setPdfPath(null);
        return $revision;
    }

    public function path(ContratFormateurRevision $revision): ?string
    {
        $filename = $revision->getPdfFilename();
        if (!preg_match('/^[a-f0-9]{48}\.pdf$/D', $filename)) return null;
        $path = $this->directory() . '/' . $filename;
        return is_file($path) && hash_equals($revision->getPdfSha256(), hash_file('sha256', $path)) ? $path : null;
    }

    public function discardFile(ContratFormateurRevision $revision): void
    {
        if ($path = $this->path($revision)) unlink($path);
    }

    private function directory(): string { return $this->projectDir . '/var/storage/contrat-formateur-versions'; }
}
