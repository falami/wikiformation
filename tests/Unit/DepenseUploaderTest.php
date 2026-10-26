<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Depense\DepenseUploader;
use App\Service\FileUploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DepenseUploaderTest extends TestCase
{
    private array $files = [];
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        foreach (array_reverse($this->directories) as $directory) if (is_dir($directory)) rmdir($directory);
        parent::tearDown();
    }

    private function upload(string $contents, string $name = 'receipt.png', ?string $claimedMime = null, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'expense-proof-test-');
        $this->files[] = $file;
        file_put_contents($file, $contents);
        return new UploadedFile($file, $name, $claimedMime, $error, true);
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/expense-proof-dir-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->directories[] = $directory;
        return $directory;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6WQAAAABJRU5ErkJggg==');
    }

    public function testUploadUsesContentMimeAndSafeUniqueFileName(): void
    {
        $directory = $this->directory();
        $uploader = new DepenseUploader(new FileUploader(), $directory, '/uploads/depense');
        $first = $uploader->uploadProof($this->upload($this->png(), '../../reçu.php', 'application/x-php'));
        $second = $uploader->uploadProof($this->upload($this->png(), '../../reçu.php', 'application/x-php'));
        $this->files[] = $directory.'/'.$first;
        $this->files[] = $directory.'/'.$second;
        self::assertNotSame($first, $second);
        self::assertSame(basename($first), $first);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+-[a-f0-9]{12}\.png$/D', $first);
        self::assertSame($this->png(), file_get_contents($directory.'/'.$first));
        self::assertSame($directory.'/'.$first, $uploader->absolutePath($first));
    }

    #[DataProvider('supportedFormats')]
    public function testSupportedReceiptFormatsAreAccepted(string $format, string $mime): void
    {
        if ($format === 'pdf') {
            $pdf = new \FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(40, 10, 'Receipt');
            $contents = $pdf->Output('S');
        } else {
            // Real 2×2 images: fixtures keep MIME validation independent of GD codecs.
            $fixtures = [
                'jpg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=',
                'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=',
                'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=',
            ];
            $contents = base64_decode($fixtures[$format], true);
            self::assertIsString($contents);
        }
        $file = $this->upload($contents, 'receipt.'.$format);
        self::assertSame($mime, $file->getMimeType());
        DepenseUploader::validateProof($file);
    }

    public static function supportedFormats(): iterable
    {
        yield ['pdf', 'application/pdf'];
        yield ['jpg', 'image/jpeg'];
        yield ['png', 'image/png'];
        yield ['webp', 'image/webp'];
    }

    #[DataProvider('unsupportedContents')]
    public function testClientMimeAndExtensionCannotDisguiseUnsafeContent(string $contents, string $name): void
    {
        $fileUploader = $this->createMock(FileUploader::class);
        $fileUploader->expects(self::never())->method('upload');
        $uploader = new DepenseUploader($fileUploader, sys_get_temp_dir(), '/uploads/depense');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Justificatif invalide');
        $uploader->uploadProof($this->upload($contents, $name, 'image/jpeg'));
    }

    public static function unsupportedContents(): iterable
    {
        yield ['<?php echo "unsafe";', 'receipt.jpg'];
        yield ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'receipt.png'];
        yield ['<html><body>not a receipt</body></html>', 'receipt.pdf'];
        yield ['plain text', 'receipt.webp'];
    }

    public function testEmptyProofIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non vide');
        DepenseUploader::validateProof($this->upload(''));
    }

    public function testOversizedProofIsRejectedBeforeStorage(): void
    {
        $upload = $this->upload($this->png());
        $handle = fopen($upload->getPathname(), 'ab');
        ftruncate($handle, 8 * 1024 * 1024 + 1);
        fclose($handle);
        clearstatcache(true, $upload->getPathname());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('8 Mo');
        DepenseUploader::validateProof($upload);
    }

    public function testIncompleteUploadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transmis correctement');
        DepenseUploader::validateProof($this->upload($this->png(), 'receipt.png', 'image/png', UPLOAD_ERR_PARTIAL));
    }

    #[DataProvider('unsafePaths')]
    public function testStoredFileNamesCannotEscapeReceiptDirectory(string $filename): void
    {
        $uploader = new DepenseUploader(new FileUploader(), sys_get_temp_dir().'/proofs', '/uploads/depense');
        self::assertNull($uploader->absolutePath($filename));
    }

    public static function unsafePaths(): iterable
    {
        yield ['../receipt.pdf'];
        yield ['/tmp/receipt.pdf'];
        yield ['folder/receipt.pdf'];
        yield ['..\\receipt.pdf'];
        yield ['C:\\receipt.pdf'];
    }

    public function testDeleteOnlyRemovesReceiptInsideConfiguredDirectory(): void
    {
        $directory = $this->directory();
        $outside = $this->upload('outside')->getPathname();
        $inside = $directory.'/receipt.pdf';
        file_put_contents($inside, 'inside');
        $this->files[] = $inside;
        $uploader = new DepenseUploader(new FileUploader(), $directory, '/uploads/depense');
        $uploader->deleteIfExists('../'.basename($outside));
        self::assertFileExists($outside);
        $uploader->deleteIfExists('receipt.pdf');
        self::assertFileDoesNotExist($inside);
        $uploader->deleteIfExists('receipt.pdf');
        self::assertFileExists($outside);
    }
}
