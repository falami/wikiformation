<?php

namespace App\Tests\Unit;

use App\Service\Depense\ReceiptOcrProviderInterface;
use App\Service\Depense\ReceiptParser;
use App\Service\Depense\ReceiptScanService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ReceiptScanServiceTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    private function upload(string $contents, string $name = 'receipt.png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt-test-');
        $this->paths[] = $path;
        file_put_contents($path, $contents);
        return new UploadedFile($path, $name, null, $error, true);
    }

    private function image(): UploadedFile
    {
        return $this->upload(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6WQAAAABJRU5ErkJggg=='));
    }

    public function testValidImageIsParsed(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->expects(self::once())->method('extractText')->willReturn(['text' => 'TOTAL TTC 12,00', 'lines' => []]);
        $r = (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->image());
        self::assertSame(1200, $r['montantTtcCents']);
    }

    public function testEmptyOcrProducesActionableFailure(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->method('extractText')->willReturn(['text' => ' ', 'lines' => []]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Aucun texte lisible');
        (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->image());
    }

    public function testTextDisguisedAsImageDoesNotReachProvider(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->expects(self::never())->method('extractText');
        $this->expectException(\InvalidArgumentException::class);
        (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->upload('<?php echo "unsafe";', 'receipt.jpg'));
    }

    public function testPartialUploadDoesNotReachProvider(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->expects(self::never())->method('extractText');
        $this->expectException(\InvalidArgumentException::class);
        (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->upload('partial', 'receipt.jpg', UPLOAD_ERR_PARTIAL));
    }

    public function testOversizedFileDoesNotReachProvider(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->expects(self::never())->method('extractText');
        $this->expectException(\InvalidArgumentException::class);
        (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->upload(str_repeat('a', 8 * 1024 * 1024 + 1)));
    }

    public function testProviderFailureDoesNotProduceEmptySuccessfulProposal(): void
    {
        $ocr = $this->createMock(ReceiptOcrProviderInterface::class);
        $ocr->method('extractText')->willThrowException(new \RuntimeException('Service unavailable'));
        $this->expectException(\RuntimeException::class);
        (new ReceiptScanService($ocr, new ReceiptParser()))->scan($this->image());
    }
}
