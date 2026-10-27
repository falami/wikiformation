<?php

declare(strict_types=1);

namespace App\Service\Devis;

use App\Entity\{Devis, Utilisateur};
use App\Enum\DevisStatus;
use App\Service\Convention\ConventionPortalSignature;
use App\Service\Entreprise\EntreprisePortalContext;
use App\Service\Pdf\PdfManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request};
use Twig\Environment;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class EntrepriseQuoteSignature
{
    public function __construct(private EntityManagerInterface $em, private Environment $twig, private PdfManager $pdf,
        #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public static function unavailableReason(Devis $quote, ?\DateTimeImmutable $now = null): ?string
    {
        if (!empty($quote->getMeta()['enterprise_signature'])) return 'Ce devis est déjà signé.';
        if (!in_array($quote->getStatus(), [DevisStatus::SENT, DevisStatus::ACCEPTED], true)) return 'Ce devis ne peut pas être signé dans son état actuel.';
        $today = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
        if ($quote->getDateValidite() && $quote->getDateValidite()->format('Y-m-d') < $today) return 'Ce devis est expiré. Contactez l’organisme pour obtenir une nouvelle proposition.';
        return null;
    }

    private function html(Devis $quote): string
    {
        return $this->twig->render('pdf/devis.html.twig', ['entite'=>$quote->getEntite(), 'devis'=>$quote]);
    }

    private function sourcePath(Devis $quote, string $digest): string
    {
        return $this->projectDir.'/var/storage/quote-signatures/source-'.$quote->getId().'-'.$digest.'.pdf';
    }

    public function preview(Devis $quote, Request $request): BinaryFileResponse
    {
        if ($signed = $this->signedResponse($quote)) return $signed;
        if ($reason = self::unavailableReason($quote)) throw new \DomainException($reason);
        $html = $this->html($quote);
        $digest = hash('sha256', $html);
        $path = $this->sourcePath($quote, $digest);
        if (!is_file($path)) {
            $bytes = $this->pdf->createPortrait($html, 'Devis')->getContent();
            if (!$bytes || !str_starts_with($bytes, '%PDF')) throw new \DomainException('Le PDF est indisponible.');
            (new Filesystem())->mkdir(dirname($path), 0770);
            // Publish the complete PDF atomically, without replacing a previous preview.
            $temporary = tempnam(dirname($path), '.preview-');
            if (!$temporary) throw new \RuntimeException('Stockage temporaire indisponible.');
            try {
                if (file_put_contents($temporary, $bytes) !== strlen($bytes)) throw new \RuntimeException('Impossible de conserver le PDF.');
                chmod($temporary, 0660);
                if (!@link($temporary, $path) && !is_file($path)) throw new \RuntimeException('Impossible de conserver le PDF.');
            } finally { unlink($temporary); }
        }
        $request->getSession()->set('quote_reviewed_'.$quote->getId(), ['digest'=>$digest, 'sha256'=>hash_file('sha256', $path)]);
        return new BinaryFileResponse($path, 200, ['Content-Type'=>'application/pdf', 'Cache-Control'=>'private, no-store']);
    }

    public function signedResponse(Devis $quote): ?BinaryFileResponse
    {
        $record = $quote->getMeta()['enterprise_signature'] ?? null;
        if (!$record) return null;
        $file = $record['file'] ?? '';
        if (!preg_match('/^signed-[a-f0-9]{32}\.pdf$/D', $file)) throw new \RuntimeException('Archive de signature invalide.');
        $path = $this->projectDir.'/var/storage/quote-signatures/'.$file;
        if (!is_file($path) || !hash_equals($record['sha256'], hash_file('sha256', $path))) throw new \RuntimeException('Archive signée indisponible ou altérée.');
        return new BinaryFileResponse($path, 200, ['Content-Type'=>'application/pdf', 'Cache-Control'=>'private, no-store']);
    }

    public function sign(Devis $quote, Utilisateur $actor, string $signature, mixed $review): void
    {
        ConventionPortalSignature::validateSignature($signature);
        $created = null;
        try {
            $this->em->wrapInTransaction(function () use ($quote, $actor, $signature, $review, &$created): void {
                $this->em->refresh($quote, LockMode::PESSIMISTIC_WRITE);
                if (!$quote->getEntrepriseDestinataire() || $quote->getEntrepriseDestinataire()->getEntite()?->getId() !== $quote->getEntite()?->getId()
                    || !EntreprisePortalContext::canRepresent($actor, $quote->getEntrepriseDestinataire())) throw new \DomainException('Ce devis ne vous est pas destiné.');
                if ($reason = self::unavailableReason($quote)) throw new \DomainException($reason);
                $digest = hash('sha256', $this->html($quote));
                if (!is_array($review) || !hash_equals($digest, (string)($review['digest'] ?? ''))) throw new \DomainException('Consultez à nouveau le PDF avant de signer : le devis a pu changer.');
                $path = $this->sourcePath($quote, $digest);
                $source = is_file($path) ? file_get_contents($path) : false;
                if (!$source || !hash_equals(hash('sha256', $source), (string)($review['sha256'] ?? ''))) throw new \DomainException('Consultez à nouveau le PDF avant de signer.');
                $now = new \DateTimeImmutable();
                $signed = $this->appendReceipt($source, $quote, $actor, $signature, $now);
                $file = 'signed-'.bin2hex(random_bytes(16)).'.pdf';
                $created = $this->projectDir.'/var/storage/quote-signatures/'.$file;
                (new Filesystem())->dumpFile($created, $signed);
                chmod($created, 0660);
                $meta = $quote->getMeta() ?? [];
                $meta['enterprise_signature'] = ['file'=>$file, 'sha256'=>hash('sha256', $signed), 'sourceSha256'=>hash('sha256', $source),
                    'sourceDigest'=>$digest, 'actorId'=>$actor->getId(), 'companyId'=>$quote->getEntrepriseDestinataire()->getId(),
                    'signedAt'=>$now->format(DATE_ATOM), 'signerName'=>trim($actor->getPrenom().' '.$actor->getNom()),
                    'method'=>'authenticated_png', 'signatureSha256'=>hash('sha256', $signature)];
                $quote->setMeta($meta)->setStatus(DevisStatus::ACCEPTED);
            });
        } catch (\Throwable $error) {
            if ($created && is_file($created)) unlink($created);
            throw $error;
        }
    }

    private function appendReceipt(string $source, Devis $quote, Utilisateur $actor, string $signature, \DateTimeImmutable $now): string
    {
        $pdf = new Fpdi();
        $count = $pdf->setSourceFile(StreamReader::createByString($source));
        for ($page=1; $page <= $count; ++$page) {
            $template = $pdf->importPage($page); $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]); $pdf->useTemplate($template);
        }
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('Helvetica', '', 11);
        $text = "Acceptation du devis ".($quote->getNumero() ?: '#'.$quote->getId())."\n\nEntreprise : ".$quote->getEntrepriseDestinataire()->getRaisonSociale()."\nSignataire : ".trim($actor->getPrenom().' '.$actor->getNom())."\nDate : ".$now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i:s')." (Europe/Paris)\n\nLe signataire confirme avoir lu et accepte les conditions du devis joint et etre habilite a signer pour cette entreprise.\n\nEmpreinte SHA-256 du document consulte :\n".hash('sha256', $source);
        $pdf->MultiCell(190, 7, iconv('UTF-8', 'Windows-1252//TRANSLIT', $text));
        $png = base64_decode(substr($signature, 22), true);
        $image = tempnam(sys_get_temp_dir(), 'quote-sign-');
        if (!$image) throw new \RuntimeException('Stockage temporaire indisponible.');
        try {
            file_put_contents($image, $png);
            [$w, $h] = getimagesizefromstring($png);
            $scale = min(100/$w, 45/$h);
            $pdf->Image($image, 15, $pdf->GetY()+10, $w*$scale, $h*$scale, 'PNG');
            return $pdf->Output('S');
        } finally { unlink($image); }
    }
}
