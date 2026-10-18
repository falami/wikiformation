<?php

declare(strict_types=1);

namespace App\Tests\Service\Contrat;

use App\Entity\{ContratFormateur, Utilisateur};
use App\Enum\ContratFormateurStatus;
use App\Service\Contrat\ContratFormateurVersioning;
use App\Service\Pdf\{ContratFormateurDocument, PdfManager};
use App\Service\Session\SessionParticipantCount;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ContratFormateurVersioningTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/contract-history-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/public/uploads/pdf', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function service(int $archives): ContratFormateurVersioning
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly($archives))->method('persist');
        $pdf = $this->createMock(PdfManager::class);
        // Signed documents must never be reconstructed using today's session data.
        $pdf->expects(self::never())->method('createPortraitBytes');
        return new ContratFormateurVersioning($em,
            new ContratFormateurDocument($this->directory, new SessionParticipantCount()),
            $pdf, new Environment(new ArrayLoader()), $this->directory);
    }

    public function testSignedDocumentAndEvidenceSurviveSuccessiveNewVersions(): void
    {
        $service = $this->service(2);
        $author = (new Utilisateur())->setEmail('admin@example.test');
        $contract = (new ContratFormateur())->setNumero('CF-TEST');
        $archives = [];
        for ($version = 1; $version <= 2; ++$version) {
            $bytes = "%PDF-1.4\nsigned document version $version\n%%EOF";
            $relative = "uploads/pdf/signed-$version.pdf";
            file_put_contents($this->directory . '/public/' . $relative, $bytes);
            $date = new \DateTimeImmutable("2026-10-1$version 10:00:00");
            $signature = 'data:image/png;base64,' . base64_encode('signature-' . $version);
            $contract->setPdfPath($relative)->setStatus(ContratFormateurStatus::SIGNE)
                ->setSignatureDataUrl($signature)->setSignatureAt($date)
                ->setSignatureIp('127.0.0.1')->setSignatureUserAgent('Test browser')
                ->setSignatureOrganismeAt($date)->setSignatureOrganismeNom('Responsable');

            $revision = $service->archive(clone $contract, $contract, $author, 'Nouvelle mission');
            $contract->prepareNewDraft();
            self::assertSame($version + 1, $contract->getVersionNumero());
            self::assertNull($contract->getSignatureDataUrl());
            self::assertNull($contract->getSignatureAt());
            self::assertNull($contract->getSignatureOrganismeAt());
            self::assertNull($contract->getPdfPath());
            self::assertSame(ContratFormateurStatus::BROUILLON, $contract->getStatus());
            self::assertSame($version, $revision->getNumero());
            self::assertSame($signature, $revision->getDonnees()['signatureDataUrl']);
            self::assertSame($date->format(DATE_ATOM), $revision->getDonnees()['signatureAt']);
            self::assertSame('Responsable', $revision->getDonnees()['signatureOrganismeNom']);
            self::assertSame('SIGNE', $revision->getDonnees()['status']);
            // The history must remain independent of the current exported file.
            unlink($this->directory . '/public/' . $relative);
            $archives[] = [$revision, $bytes];
        }
        foreach ($archives as [$revision, $bytes]) {
            self::assertSame($bytes, file_get_contents($service->path($revision)));
            self::assertSame(hash('sha256', $bytes), $revision->getPdfSha256());
        }
    }

    public function testMissingSignedPdfBlocksArchivingWithoutClearingSignature(): void
    {
        $service = $this->service(0);
        $contract = (new ContratFormateur())->setStatus(ContratFormateurStatus::SIGNE)
            ->setSignatureDataUrl('retained signature')->setSignatureAt(new \DateTimeImmutable());
        try {
            $service->archive(clone $contract, $contract, new Utilisateur(), 'Modification');
            self::fail('A missing signed PDF must prevent a new version.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('PDF original est introuvable', $exception->getMessage());
        }
        self::assertSame(1, $contract->getVersionNumero());
        self::assertSame('retained signature', $contract->getSignatureDataUrl());
        self::assertSame(ContratFormateurStatus::SIGNE, $contract->getStatus());
    }
}
