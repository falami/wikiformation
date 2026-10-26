<?php

namespace App\Tests\Unit;

use App\Service\Ocr\GoogleVisionOcrService;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use PHPUnit\Framework\TestCase;

final class GoogleVisionOcrOutputTest extends TestCase
{
    private function read(array $files): string
    {
        $objects = [];
        foreach ($files as $name => $json) {
            $object = $this->createMock(StorageObject::class);
            $object->method('name')->willReturn($name);
            $object->method('downloadAsString')->willReturn($json);
            $objects[] = $object;
        }
        $bucket = $this->createMock(Bucket::class);
        $bucket->method('objects')->willReturn(new \ArrayIterator($objects));
        $service = new GoogleVisionOcrService('test', '/no-credentials-required');
        return (new \ReflectionMethod($service, 'readVisionOutputJsonFromPrefix'))->invoke($service, $bucket, 'ocr-output/test/');
    }

    public function testPdfOutputPartsAreReadInPageOrder(): void
    {
        $result = $this->read([
            'output-11-to-20.json' => json_encode(['responses' => [['fullTextAnnotation' => ['text' => 'second']]]]),
            'output-1-to-10.json' => json_encode(['responses' => [['fullTextAnnotation' => ['text' => 'first']]]]),
        ]);
        self::assertSame("first\nsecond\n", $result);
    }

    public function testPartialPageErrorsAreNotSilentlyIgnored(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Une page');
        $this->read(['output.json' => json_encode(['responses' => [
            ['fullTextAnnotation' => ['text' => 'Total 12,00']],
            ['error' => ['code' => 3, 'message' => 'Bad page']],
        ]])]);
    }

    public function testMalformedJsonFailsInsteadOfReturningPartialText(): void
    {
        $this->expectException(\JsonException::class);
        $this->read(['output.json' => '{bad']);
    }

    public function testMissingOutputIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->read([]);
    }
}
