<?php
namespace App\Tests\Unit;
use App\Service\TrainerProfile\CvImporter;
use PHPUnit\Framework\TestCase;
final class CvImporterTest extends TestCase
{
    public function testSectionsAreExtractedWithoutInventingTitleOrCopyingContact(): void
    {
        $result = (new CvImporter())->fromText("Camille Test\ncamille@example.com\nEXPÉRIENCES PROFESSIONNELLES\n2020–2024 Formatrice chez Exemple\nAnimation Excel\nCOMPÉTENCES : Excel, Word\nDIPLÔMES ET CERTIFICATIONS\n2019 Licence informatique\nLANGUES\nFrançais\nContact\n12 rue Exemple\n06 12 34 56 78");
        self::assertSame('', $result['headline']);
        self::assertSame('', $result['summary']);
        self::assertSame("2020–2024 Formatrice chez Exemple\nAnimation Excel", $result['experience']);
        self::assertSame('Excel, Word', $result['skills']);
        self::assertSame('2019 Licence informatique', $result['education']);
        self::assertSame('Français', $result['languages']);
        self::assertStringNotContainsString('example.com', implode(' ', $result));
    }
    public function testUnknownLayoutDoesNotTurnPersonalHeaderIntoSummary(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rubriques ne sont pas reconnues');
        (new CvImporter())->fromText('Camille Test, 12 rue Exemple, un texte sans rubrique');
    }
    public function testRealPdfExtraction(): void
    {
        $pdf = new \FPDF();
        $pdf->AddPage(); $pdf->SetFont('Arial', '', 12);
        $pdf->MultiCell(0, 8, "WORK EXPERIENCE\n2020 Trainer at Example\nSKILLS\nExcel and Word");
        $path = tempnam(sys_get_temp_dir(), 'cv-test');
        try {
            $pdf->Output('F', $path);
            $result = (new CvImporter())->import($path);
            self::assertSame('2020 Trainer at Example', $result['experience']);
            self::assertSame('Excel and Word', $result['skills']);
        } finally { unlink($path); }
    }
    public function testInvalidPdfProducesActionableError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-test');
        try {
            file_put_contents($path, 'not a PDF');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Le PDF et votre CV sont conservés');
            (new CvImporter())->import($path);
        } finally { unlink($path); }
    }
    public function testImageOnlyPdfProducesManualFallback(): void
    {
        $pdf = new \FPDF(); $pdf->AddPage();
        $path = tempnam(sys_get_temp_dir(), 'cv-test');
        try {
            $pdf->Output('F', $path);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('scan');
            (new CvImporter())->import($path);
        } finally { unlink($path); }
    }
}
