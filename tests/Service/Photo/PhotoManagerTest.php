<?php

namespace App\Tests\Service\Photo;

use App\Service\FileUploader;
use App\Service\Photo\{ImageUploadException, PhotoManager};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class PhotoManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wikiformation-photo-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }

    public function testResizeReplacesPhotoOnlyAfterTheNewImageIsReady(): void
    {
        $source = $this->directory . '/source.png';
        $image = imagecreatetruecolor(2400, 1600);
        imagepng($image, $source);
        unset($image);
        $old = $this->directory . '/old.png';
        file_put_contents($old, 'original');
        $file = new UploadedFile($source, 'source.png', 'image/png', null, true);
        $filename = null;
        (new PhotoManager(new NullLogger()))->handleImageUpload($this->form($file), 'photo', function ($value) use (&$filename, $old): void {
            self::assertFileExists($old);
            $filename = $value;
        }, new FileUploader(), $this->directory, 1200, 600, 'old.png');
        self::assertFileDoesNotExist($old);
        self::assertNotNull($filename);
        self::assertSame([1200, 600], array_slice(getimagesize($this->directory . '/' . $filename), 0, 2));
    }

    public function testHugeCompressedImageIsRejectedWithoutDecodingOrDeletingTheOldPhoto(): void
    {
        // PNG header with enormous dimensions: no dangerous bitmap allocation in the test.
        $source = $this->directory . '/huge.png';
        file_put_contents($source, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0));
        $old = $this->directory . '/old.png';
        file_put_contents($old, 'original');
        $file = new UploadedFile($source, 'huge.png', 'image/png', null, true);
        try {
            (new PhotoManager(new NullLogger()))->handleImageUpload($this->form($file), 'photo', static function (): void {
                self::fail('The entity must not receive a rejected upload.');
            }, new FileUploader(), $this->directory, 1200, 600, 'old.png');
            self::fail('The image must be rejected before GD decodes it.');
        } catch (ImageUploadException $exception) {
            self::assertStringContainsString('2 400 pixels', $exception->getMessage());
        }
        self::assertSame('original', file_get_contents($old));
        self::assertFileExists($source);
        self::assertCount(2, glob($this->directory . '/*'));
    }

    public function testFailedReplacementCleansNewFileAndPreservesOldFile(): void
    {
        $source = $this->directory . '/source.png';
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $source);
        unset($image);
        file_put_contents($this->directory . '/old.png', 'original');
        try {
            (new PhotoManager(new NullLogger()))->handleImageUpload($this->form(new UploadedFile($source, 'source.png', 'image/png', null, true)), 'photo', static function (): void {
                throw new \RuntimeException('Saving the replacement failed');
            }, new FileUploader(), $this->directory, 20, 20, 'old.png');
            self::fail('Expected a handled upload failure.');
        } catch (ImageUploadException $exception) {
            self::assertStringContainsString('n’a pas pu être traitée', $exception->getMessage());
        }
        self::assertSame([$this->directory . '/old.png'], glob($this->directory . '/*'));
        self::assertSame('original', file_get_contents($this->directory . '/old.png'));
    }

    public function testUploadWorksWithinTheReported128MbMemoryLimit(): void
    {
        $script = <<<'CODE'
require $argv[1] . '/vendor/autoload.php';
$dir = $argv[2];
$image = imagecreatetruecolor(3000, 2000);
imagejpeg($image, $dir . '/input.jpg');
unset($image);
// Account for an already booted application before uploading the image.
$applicationMemory = str_repeat('a', 35 * 1024 * 1024);
$form = Symfony\Component\Form\Forms::createFormFactory()->createBuilder()->add('photo', Symfony\Component\Form\Extension\Core\Type\FileType::class)->getForm();
$form->get('photo')->setData(new Symfony\Component\HttpFoundation\File\UploadedFile($dir . '/input.jpg', 'input.jpg', 'image/jpeg', null, true));
(new App\Service\Photo\PhotoManager(new Psr\Log\NullLogger()))->handleImageUpload($form, 'photo', static function ($name) use ($dir): void {
    if (array_slice(getimagesize($dir . '/' . $name), 0, 2) !== [1200, 600]) throw new RuntimeException('Wrong thumbnail dimensions');
}, new App\Service\FileUploader(), $dir, 1200, 600);
echo 'ok';
CODE;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, dirname(__DIR__, 3), $this->directory]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('ok', $process->getOutput());
    }

    public function testBatchKeepsEveryExistingPhotoWhenOneImageCannotBeDecoded(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $this->directory . '/valid.png');
        unset($image);
        file_put_contents($this->directory . '/broken.png', "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 20, 20, 8, 2, 0, 0, 0));
        foreach (['old-cover.png', 'old-banner.png'] as $name) file_put_contents($this->directory . '/' . $name, 'original');
        $form = Forms::createFormFactoryBuilder()->addExtension(new \Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension())->getFormFactory()->createBuilder()->add('cover', FileType::class)->add('banner', FileType::class)->getForm();
        $form->submit([
            'cover' => new UploadedFile($this->directory . '/valid.png', 'valid.png', 'image/png', null, true),
            'banner' => new UploadedFile($this->directory . '/broken.png', 'broken.png', 'image/png', null, true),
        ]);
        $setter = static function (): void { self::fail('No entity is updated until every image is ready.'); };
        $result = (new PhotoManager(new NullLogger()))->handleFormImageUploads($form, [
            'cover' => ['setter' => $setter, 'width' => 20, 'height' => 20, 'oldFilename' => 'old-cover.png'],
            'banner' => ['setter' => $setter, 'width' => 20, 'height' => 20, 'oldFilename' => 'old-banner.png'],
        ], new FileUploader(), $this->directory);
        self::assertFalse($result);
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('banner')->getErrors());
        self::assertSame('original', file_get_contents($this->directory . '/old-cover.png'));
        self::assertSame('original', file_get_contents($this->directory . '/old-banner.png'));
        self::assertCount(2, glob($this->directory . '/*'));
    }

    public function testBatchProcessesGalleryImagesAndSingleImagesTogether(): void
    {
        $files = [];
        foreach (['cover.png', 'one.png', 'two.png'] as $name) {
            $image = imagecreatetruecolor(40, 20);
            imagepng($image, $this->directory . '/' . $name);
            unset($image);
            $files[] = new UploadedFile($this->directory . '/' . $name, $name, 'image/png', null, true);
        }
        $form = Forms::createFormFactoryBuilder()->addExtension(new \Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension())->getFormFactory()->createBuilder()->add('cover', FileType::class)->add('gallery', FileType::class, ['multiple' => true])->getForm();
        $form->submit(['cover' => $files[0], 'gallery' => [$files[1], $files[2]]]);
        $names = [];
        $setter = static function (string $name) use (&$names): void { $names[] = $name; };
        self::assertTrue((new PhotoManager(new NullLogger()))->handleFormImageUploads($form, [
            'cover' => ['setter' => $setter, 'width' => 20, 'height' => 20],
            'gallery' => ['setter' => $setter, 'width' => 20, 'height' => 20],
        ], new FileUploader(), $this->directory));
        self::assertTrue($form->isValid());
        self::assertCount(3, $names);
        foreach ($names as $name) self::assertSame([20, 20], array_slice(getimagesize($this->directory . '/' . $name), 0, 2));
    }

    public function testStoredFilenameCannotDeleteFilesOutsideTheUploadDirectory(): void
    {
        $outside = $this->directory . '-outside.png';
        file_put_contents($outside, 'original');
        try {
            (new PhotoManager(new NullLogger()))->deleteImageIfExists('../' . basename($outside), $this->directory);
            self::assertFileExists($outside);
        } finally {
            unlink($outside);
        }
    }

    private function form(UploadedFile $file): \Symfony\Component\Form\FormInterface
    {
        $form = Forms::createFormFactory()->createBuilder()->add('photo', FileType::class)->getForm();
        $form->get('photo')->setData($file);
        return $form;
    }
}
