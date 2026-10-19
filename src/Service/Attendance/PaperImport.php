<?php
namespace App\Service\Attendance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\{ExecutableFinder, Process};
use Symfony\Component\Filesystem\Filesystem;

/** Local processing only. OCR is a suggestion, never evidence of a signature. */
final class PaperImport
{
    public function __construct(#[Autowire('%kernel.project_dir%/var/storage/attendance-imports')] private string $root) {}

    public function directory(int $tenant, string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw new \DomainException('Import introuvable.');
        return $this->root.'/'.$tenant.'/'.$token;
    }

    public function read(int $tenant, string $token): array
    {
        $path = $this->directory($tenant, $token).'/manifest.json';
        if (!is_file($path)) throw new \DomainException('Import introuvable.');
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(int $tenant, string $token, array $data): void
    {
        (new Filesystem())->dumpFile($this->directory($tenant, $token).'/manifest.json', json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    public function prepare(UploadedFile $file, int $tenant, int $session, int $actor): string
    {
        if (!$file->isValid() || $file->getSize() > 15*1024*1024) throw new \DomainException('Fichier invalide ou supérieur à 15 Mo.');
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($mime === 'application/pdf') $ext = 'pdf';
        elseif ($ext !== 'docx' || !in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'], true)) throw new \DomainException('Choisissez un PDF ou un document Word .docx (exportez les anciens .doc en PDF).');
        if ($ext === 'docx') {
            $zip = new \ZipArchive();
            if ($zip->open($file->getPathname()) !== true) throw new \DomainException('Document Word invalide.');
            $valid = $zip->locateName('word/document.xml') !== false;
            $size = 0;
            for ($i=0; $i<$zip->numFiles; ++$i) {
                $entry=$zip->statIndex($i); $size += $entry['size'];
                if (str_contains(strtolower($entry['name']), 'vbaproject') || str_contains($entry['name'], 'embeddings/') || str_contains($entry['name'], 'activeX/')) $valid=false;
                if (str_ends_with($entry['name'], '.rels')) {
                    if ($entry['size'] > 1024*1024) $valid=false;
                    elseif (preg_match('/TargetMode\\s*=\\s*[\"\']External/i', (string)$zip->getFromIndex($i))) $valid=false;
                }
            }
            $zip->close();
            if (!$valid || $size > 100*1024*1024) throw new \DomainException('Document Word invalide ou trop volumineux.');
        }
        $render = $this->binary('pdftoppm');
        $info = $this->binary('pdfinfo');
        $office = $ext === 'docx' ? $this->binary('libreoffice') : null;
        $token = bin2hex(random_bytes(16));
        $dir = $this->directory($tenant, $token);
        (new Filesystem())->mkdir($dir, 0700);
        try {
            $originalName = $file->getClientOriginalName();
            $file->move($dir, 'original.'.$ext);
            $pdf = $dir.'/original.pdf';
            if ($office) {
                $this->run([$office, '-env:UserInstallation=file://'.$dir.'/office', '--headless', '--convert-to','pdf','--outdir',$dir,$dir.'/original.docx']);
                if (!is_file($pdf)) throw new \DomainException('Conversion Word impossible. Exportez le document en PDF.');
                (new Filesystem())->remove($dir.'/office');
            }
            $metadata = $this->run([$info, $pdf]);
            preg_match('/Pages:\s+(\d+)/', $metadata, $matches);
            $pages = (int)($matches[1] ?? 0);
            if ($pages < 1 || $pages > 20) throw new \DomainException('Import limité à 20 pages. Divisez le document en plusieurs fichiers.');
            $this->run([$render,'-scale-to','1800','-png',$pdf,$dir.'/page']);
            $images = glob($dir.'/page-*.png');
            natsort($images);
            $texts = []; $ocr = (new ExecutableFinder())->find('tesseract');
            foreach (array_values($images) as $i=>$image) {
                rename($image, $dir.'/view-'.($i+1).'.png');
                $text = '';
                if ($ocr) {
                    try { $text = $this->run([$ocr,$dir.'/view-'.($i+1).'.png','stdout','-l','fra+eng'], 30); } catch (\DomainException) {}
                }
                $texts[] = $text;
            }
            $this->save($tenant,$token,['session'=>$session,'actor'=>$actor,'name'=>$originalName,'extension'=>$ext,'sha256'=>hash_file('sha256',$dir.'/original.'.$ext),'createdAt'=>date(DATE_ATOM),'pages'=>$pages,'texts'=>$texts]);
            return $token;
        } catch (\Throwable $e) {
            (new Filesystem())->remove($dir);
            if ($e instanceof \DomainException) throw $e;
            throw new \DomainException('Ce document ne peut pas être analysé. Essayez un PDF non protégé.', previous:$e);
        }
    }

    public function crop(int $tenant, string $token, int $page, array $rect): string
    {
        $manifest = $this->read($tenant,$token);
        if ($page < 1 || $page > $manifest['pages'] || count($rect)!==4) throw new \DomainException('Page ou zone de signature invalide.');
        foreach ($rect as $number) if (!is_numeric($number) || !is_finite((float)$number)) throw new \DomainException('Zone invalide.');
        [$x,$y,$w,$h] = array_map('floatval',array_values($rect));
        if ($x<0 || $y<0 || $w<0.005 || $h<0.005 || $x+$w>1.001 || $y+$h>1.001) throw new \DomainException('Encadrez la signature sur la page.');
        $image = imagecreatefrompng($this->directory($tenant,$token).'/view-'.$page.'.png');
        $crop = imagecrop($image,['x'=>(int)($x*imagesx($image)), 'y'=>(int)($y*imagesy($image)), 'width'=>max(1,(int)($w*imagesx($image))), 'height'=>max(1,(int)($h*imagesy($image)))]);
        if (!$crop) throw new \DomainException('Découpe impossible.');
        ob_start(); imagepng($crop); $bytes=ob_get_clean();
        imagedestroy($crop); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function binary(string $name): string
    {
        return (new ExecutableFinder())->find($name) ?? throw new \DomainException('Le serveur doit être équipé de '.$name.' pour cet import. Consultez la configuration des imports d’émargements.');
    }
    private function run(array $args, int $timeout=90): string
    {
        $process = new Process($args); $process->setTimeout($timeout);
        try { $process->run(); } catch (\Throwable) { throw new \DomainException('Analyse trop longue. Divisez le document en plusieurs fichiers.'); }
        if (!$process->isSuccessful()) throw new \DomainException('Lecture du document impossible. Vérifiez son format et sa protection.');
        return $process->getOutput();
    }
}
