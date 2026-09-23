<?php

declare(strict_types=1);

namespace App\Tests\Service\Convention;

use App\Entity\{ConventionContrat, Session, SessionJour};
use App\Service\Convention\ConventionDocument;
use App\Service\Pdf\PdfManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Response};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\{Environment, Loader\ArrayLoader};

final class ConventionDocumentTest extends TestCase
{
    public function testUnsignedPreviewRecalculatesWhileSignedOriginalRemainsUntouched(): void
    {
        $root = sys_get_temp_dir() . '/wf-convention-preview-' . bin2hex(random_bytes(5));
        mkdir($root . '/public', 0700, true);
        $original = $root . '/public/original.pdf';
        file_put_contents($original, '%PDF-original-amplitude');
        try {
            $twig = new Environment(new ArrayLoader([
                'pdf/convention_contrat.html.twig' => '{{ session.dureeFormationHeures }} heures',
            ]));
            $pdf = $this->createMock(PdfManager::class);
            $pdf->expects(self::once())->method('streamPdfFromHtml')->with('7 heures', 'Convention-CONV-TEST.pdf')
                ->willReturn(new Response('%PDF-recalculated'));
            $document = new ConventionDocument($twig, $pdf, $root);
            $session = (new Session())->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-07 08:30'))
                ->setDateFin(new \DateTimeImmutable('2026-10-07 17:00')));
            $convention = (new ConventionContrat())->setNumero('CONV-TEST')->setSession($session)->setPdfPath('original.pdf');
            $response = $document->response($convention);
            self::assertSame('%PDF-recalculated', $response->getContent());
            self::assertSame('%PDF-original-amplitude', file_get_contents($original));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $convention->setDateSignatureEntreprise(new \DateTimeImmutable());
            $signed = $document->response($convention);
            self::assertInstanceOf(BinaryFileResponse::class, $signed);
            self::assertSame(realpath($original), $signed->getFile()->getPathname());
            self::assertSame('%PDF-original-amplitude', file_get_contents($original));
        } finally {
            unlink($original); rmdir($root . '/public'); rmdir($root);
        }
    }

    public function testMissingSignedOriginalIsNeverSilentlyRecreated(): void
    {
        $pdf = $this->createMock(PdfManager::class);
        $pdf->expects(self::never())->method('streamPdfFromHtml');
        $document = new ConventionDocument(new Environment(new ArrayLoader()), $pdf, '/missing-convention-test');
        $convention = (new ConventionContrat())->setNumero('SIGNED')->setDateSignatureEntreprise(new \DateTimeImmutable());
        $this->expectException(NotFoundHttpException::class);
        $document->response($convention);
    }
}
