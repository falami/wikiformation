<?php
declare(strict_types=1);
namespace App\Tests\Service\Pdf;
use App\Entity\Entite;
use App\Service\Pdf\DocumentFooter;
use PHPUnit\Framework\TestCase;
use Twig\{Environment, Loader\FilesystemLoader};

final class DocumentFooterTest extends TestCase
{
    public function testReservesSpaceWithoutReducingExistingBankingFooterMargin(): void
    {
        $html = '<meta name="wf-document-footer" content="{}"><style>@page { margin: 10mm 12mm 58mm; }</style>';
        $result = DocumentFooter::prepare($html);
        self::assertMatchesRegularExpression('/margin-bottom: 196\.4\d+pt/', $result);
        self::assertSame('<html>Existing document</html>', DocumentFooter::prepare('<html>Existing document</html>'));
    }

    public function testFooterUsesDocumentIssuerAndEscapesUntrustedValues(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['strict_variables'=>true]);
        $entity = (new Entite())->setNom('Centre "A" <script>')->setEmail('contact@example.test')->setTelephone('+33123456789')->setSiteInternet('https://example.test');
        $html = $twig->render('pdf/_document_footer.html.twig', ['entite'=>$entity,'facture'=>['numero'=>'FAC-0042']]);
        self::assertStringNotContainsString('<script>', $html);
        preg_match('/content="([^"]*)"/', $html, $match);
        $data = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertSame('Réf. FAC-0042', $data['reference']);
        self::assertSame('https://example.test', $data['website']);
        self::assertSame('Centre "A" <script>', $data['name']);
    }
}
