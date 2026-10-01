<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Entity\Entite;
use App\Service\Pdf\{BlankTrainingDocumentPdf, PdfManager};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Twig\{Environment, Loader\ArrayLoader, Loader\FilesystemLoader};

final class BlankTrainingDocumentPdfTest extends TestCase
{
    public static function models(): iterable
    {
        foreach (array_keys(BlankTrainingDocumentPdf::catalogue()) as $type) yield $type => [$type];
    }

    #[DataProvider('models')]
    public function testBlankModelRendersAsASingleA4PageWithTheCorrectOrientation(string $type): void
    {
        $root = dirname(__DIR__, 3);
        $twig = new Environment(new FilesystemLoader($root . '/templates'));
        $service = new BlankTrainingDocumentPdf($twig, new PdfManager($twig, sys_get_temp_dir()), $root);
        $entite = (new Entite())->setNom('Centre de formation de démonstration');
        $response = $service->download($entite, $type);

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertStringStartsWith('%PDF-', $response->getContent());
        self::assertMatchesRegularExpression('~/Type\s*/Pages\s.*?/Count\s+1\b~s', $response->getContent());
        $size = $type === 'emargement' ? '841.890 595.280' : '595.280 841.890';
        self::assertStringContainsString('/MediaBox [0.000 0.000 ' . $size . ']', $response->getContent());
    }

    public function testLogoIsEmbeddedOnlyFromTheTrustedLocalDirectory(): void
    {
        $root = sys_get_temp_dir() . '/wf-blank-document-' . bin2hex(random_bytes(6));
        $logoDir = $root . '/public/uploads/photos/entite/logo';
        $filesystem = new Filesystem();
        $filesystem->mkdir($logoDir);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
        file_put_contents($logoDir . '/logo.png', $png);
        file_put_contents($root . '/outside.png', $png);
        file_put_contents($logoDir . '/not-an-image.png', '<script>invalid</script>');
        symlink($root . '/outside.png', $logoDir . '/escaped.png');

        try {
            $twig = new Environment(new ArrayLoader(['pdf/modeles/emargement.html.twig' => '{{ entite.nom }}|{{ logoDataUri|default("none") }}']));
            $pdf = $this->createMock(PdfManager::class);
            $pdf->method('createLandscape')->willReturnCallback(static fn(string $html) => new Response($html));
            $service = new BlankTrainingDocumentPdf($twig, $pdf, $root);
            $entite = (new Entite())->setNom('Organisme A')->setLogo('logo.png');
            self::assertStringContainsString('data:image/png;base64,' . base64_encode($png), $service->download($entite, 'emargement')->getContent());

            foreach (['../outside.png', 'escaped.png', 'not-an-image.png', 'missing.png'] as $invalid) {
                $entite->setLogo($invalid);
                self::assertSame('Organisme A|none', $service->download($entite, 'emargement')->getContent());
            }
        } finally {
            $filesystem->remove($root);
        }
    }

    public function testUnknownTypeCannotSelectAnArbitraryTwigTemplate(): void
    {
        $pdf = $this->createMock(PdfManager::class);
        $pdf->expects(self::never())->method('createPortrait');
        $pdf->expects(self::never())->method('createLandscape');
        $service = new BlankTrainingDocumentPdf(new Environment(new ArrayLoader()), $pdf, '/missing');
        $this->expectException(\InvalidArgumentException::class);
        $service->download(new Entite(), '../../private');
    }
}
