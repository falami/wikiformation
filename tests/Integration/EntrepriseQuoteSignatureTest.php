<?php
namespace App\Tests\Integration;

use App\Entity\{Devis, Entite, Entreprise, Utilisateur};
use App\Enum\DevisStatus;
use App\Service\Devis\EntrepriseQuoteSignature;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\{Session, Storage\MockArraySessionStorage};

final class EntrepriseQuoteSignatureTest extends KernelTestCase
{
    public function testEligibility(): void
    {
        $quote = new Devis();
        self::assertNotNull(EntrepriseQuoteSignature::unavailableReason($quote));
        foreach ([DevisStatus::SENT, DevisStatus::ACCEPTED] as $status) {
            $quote->setStatus($status);
            self::assertNull(EntrepriseQuoteSignature::unavailableReason($quote));
        }
        foreach ([DevisStatus::CANCELED, DevisStatus::INVOICED] as $status) {
            $quote->setStatus($status);
            self::assertNotNull(EntrepriseQuoteSignature::unavailableReason($quote));
        }
        $quote->setStatus(DevisStatus::SENT)->setDateValidite(new \DateTimeImmutable('2026-10-28'));
        self::assertNull(EntrepriseQuoteSignature::unavailableReason($quote, new \DateTimeImmutable('2026-10-28 23:59 Europe/Paris')));
        self::assertNotNull(EntrepriseQuoteSignature::unavailableReason($quote, new \DateTimeImmutable('2026-10-29 Europe/Paris')));
        $quote->setDateValidite(null)->setMeta(['enterprise_signature'=>['signedAt'=>'2026-10-28']]);
        self::assertNotNull(EntrepriseQuoteSignature::unavailableReason($quote));
    }

    public static function signingCases(): iterable
    {
        foreach (['success', 'missing_review', 'changed_quote', 'wrong_company'] as $case) yield $case => [$case];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signingCases')]
    public function testReviewedDocumentIsSignedAndArchivedWithoutChangingOriginalPages(string $case): void
    {
        $old = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $files = [];
        try {
            self::bootKernel();
            $container = self::getContainer();
            $em = $container->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $user = (new Utilisateur())->setEmail('quote-sign@example.test')->setPassword('unused')->setPrenom('Jean')->setNom('Test');
            $tenant = (new Entite())->setNom('Quote test')->setCreateur($user)->setPublic(false);
            $company = (new Entreprise())->setRaisonSociale('Client test')->setEntite($tenant)->setCreateur($user)->setRepresentant($user);
            $quote = (new Devis())->setEntite($tenant)->setCreateur($user)->setEntrepriseDestinataire($company)->setStatus(DevisStatus::SENT)->setNumero('TEST-'.bin2hex(random_bytes(4)));
            foreach ([$user,$tenant,$company,$quote] as $entity) $em->persist($entity);
            $em->flush();
            $service = $container->get(EntrepriseQuoteSignature::class);
            $request = Request::create('/'); $request->setSession(new Session(new MockArraySessionStorage()));
            $preview = $service->preview($quote, $request); $files[] = $preview->getFile()->getPathname();
            $review = $request->getSession()->get('quote_reviewed_'.$quote->getId());
            $image = imagecreatetruecolor(40,20); imagefill($image,0,0,imagecolorallocate($image,255,255,255)); imageline($image,2,2,35,17,imagecolorallocate($image,0,0,0));
            ob_start(); imagepng($image); $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
            if ($case === 'missing_review') $review = null;
            if ($case === 'changed_quote') { $quote->setNumero('CHANGED'); $em->flush(); }
            if ($case === 'wrong_company') {
                $other = (new Utilisateur())->setEmail('other@example.test')->setPassword('unused')->setPrenom('Other')->setNom('Person');
                $em->persist($other); $em->flush(); $user = $other;
            }
            if ($case !== 'success') {
                $this->expectException(\DomainException::class);
                $service->sign($quote, $user, $signature, $review);
                return;
            }
            $service->sign($quote, $user, $signature, $review);
            self::assertSame(DevisStatus::ACCEPTED, $quote->getStatus());
            $signed = $service->signedResponse($quote); $files[] = $signed->getFile()->getPathname();
            self::assertSame(hash_file('sha256',$files[0]), $quote->getMeta()['enterprise_signature']['sourceSha256']);
            $parser = new \Smalot\PdfParser\Parser();
            $source = $parser->parseFile($files[0]); $result = $parser->parseFile($files[1]);
            self::assertCount(count($source->getPages())+1,$result->getPages());
            self::assertStringContainsString('Acceptation du devis',$result->getText());
            self::assertNotNull(EntrepriseQuoteSignature::unavailableReason($quote));
        } finally {
            foreach ($files as $file) if (is_file($file)) unlink($file);
            self::ensureKernelShutdown();
            foreach (['ENV','SERVER'] as $i=>$name) { if ($old[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']); else $GLOBALS['_'.$name]['DATABASE_URL']=$old[$i]; }
        }
    }
}
