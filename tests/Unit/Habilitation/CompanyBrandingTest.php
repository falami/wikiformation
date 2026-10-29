<?php

declare(strict_types=1);

namespace App\Tests\Unit\Habilitation;

use App\Entity\Entreprise;
use App\Service\Pdf\CompanyBranding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CompanyBrandingTest extends TestCase
{
    private string $root;
    private string $logos;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/wf-company-brand-'.bin2hex(random_bytes(8));
        $this->logos = $this->root.'/public/uploads/entreprises/logo';
        mkdir($this->logos, 0700, true);
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->root); }

    public function testCompanyIdentityAndLogoAreCapturedIndependentlyOfLaterChanges(): void
    {
        $image = imagecreatetruecolor(1000, 500);
        imagepng($image, $this->logos.'/company.png');
        imagedestroy($image);
        $company = (new Entreprise())->setRaisonSociale('Employeur signé')->setAdresse('10 rue Exemple')
            ->setComplement('Bâtiment B')->setCodePostal('75001')->setVille('Paris')->setPays('France')
            ->setSiret('12345678900001')->setLogo('company.png');
        $snapshot = (new CompanyBranding($this->root))->snapshot($company);
        $company->setRaisonSociale('Nouveau nom')->setAdresse('Autre adresse');
        unlink($this->logos.'/company.png');
        self::assertSame('Employeur signé', $snapshot['name']);
        self::assertSame("10 rue Exemple\nBâtiment B\n75001 Paris\nFrance", $snapshot['address']);
        self::assertSame('12345678900001', $snapshot['siret']);
        self::assertSame($snapshot['logoDataUri'], CompanyBranding::snapshotLogo($snapshot['logoDataUri']));
        $dimensions = getimagesizefromstring(base64_decode(substr($snapshot['logoDataUri'], 22)));
        self::assertSame([800, 400], array_slice($dimensions, 0, 2));
    }

    public function testNoNetworkTraversalSymlinkOrNonRasterLogoCanBeCaptured(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $this->root.'/outside.png');
        imagedestroy($image);
        symlink($this->root.'/outside.png', $this->logos.'/linked.png');
        file_put_contents($this->logos.'/vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $branding = new CompanyBranding($this->root);
        foreach (['https://example.org/logo.png', '../../../../outside.png', 'linked.png', 'vector.svg', 'missing.png', "bad\0file", '..\\outside.png'] as $filename) {
            self::assertNull($branding->snapshot((new Entreprise())->setLogo($filename))['logoDataUri'], $filename);
        }
        foreach (['file:///etc/passwd', 'https://example.org/logo.png', 'data:image/svg+xml;base64,PHN2Zz4=', 'data:image/png;base64,broken!', [], null] as $uri) {
            self::assertNull(CompanyBranding::snapshotLogo($uri));
        }
    }
}
