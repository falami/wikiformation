<?php

namespace App\Tests\Controller\Administrateur;

use App\Controller\Administrateur\PaiementController;
use App\Entity\Paiement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PaiementProofTest extends TestCase
{
    public function testExistingProofIsKeptUnlessRemovalIsExplicit(): void
    {
        $controller = (new \ReflectionClass(PaiementController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'handleJustificatifUpload');
        $payment = (new Paiement())->setJustificatif('proof_existing.pdf');
        $method->invoke($controller, null, $payment, false);
        self::assertSame('proof_existing.pdf', $payment->getJustificatif());
        $method->invoke($controller, null, $payment, true);
        self::assertNull($payment->getJustificatif());
    }

    public function testReplacementTakesPriorityOverRemoval(): void
    {
        $directory = sys_get_temp_dir().'/payment-proof-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $source = $directory.'/input.pdf';
        file_put_contents($source, "%PDF-1.4\n%%EOF");
        $controller = (new \ReflectionClass(PaiementController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($controller, 'proofDir'))->setValue($controller, $directory);
        $payment = (new Paiement())->setJustificatif('proof_existing.pdf');
        try {
            (new \ReflectionMethod($controller, 'handleJustificatifUpload'))->invoke(
                $controller, new UploadedFile($source, 'receipt.pdf', 'application/pdf', null, true), $payment, true
            );
            self::assertNotSame('proof_existing.pdf', $payment->getJustificatif());
            self::assertNotNull($payment->getJustificatif());
            self::assertFileExists($directory.'/'.$payment->getJustificatif());
        } finally {
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }
}
