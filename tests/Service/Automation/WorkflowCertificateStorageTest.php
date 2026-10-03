<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\Automation\TrainingWorkflow;
use App\Entity\{ConventionContrat, Entite, Inscription, Session, Utilisateur};
use App\Service\Automation\WorkflowCertificateStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WorkflowCertificateStorageTest extends TestCase
{
    private string $directory;
    private WorkflowCertificateStorage $storage;
    private TrainingWorkflow $workflow;
    private Inscription $inscription;
    private Utilisateur $actor;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/wikiformation-certificate-test-'.bin2hex(random_bytes(6)); mkdir($this->directory,0700);
        $this->storage=new WorkflowCertificateStorage($this->directory.'/private');
        $this->actor=$this->identified(new Utilisateur(),1);
        $entite=$this->identified(new Entite(),1); $session=$this->identified((new Session())->setEntite($entite),1);
        $this->inscription=$this->identified((new Inscription())->setEntite($entite)->setSession($session),1);
        $convention=$this->identified((new ConventionContrat())->setEntite($entite)->setSession($session)->addInscription($this->inscription),1);
        $this->workflow=$this->identified((new TrainingWorkflow())->setEntite($entite)->setSession($session)->setConvention($convention)->setCreateur($this->actor),1);
    }

    protected function tearDown(): void
    {
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($this->directory);
    }

    public function testValidatedCertificateKeepsPreviousBytesAndVersionHistory(): void
    {
        $first=$this->storage->store($this->upload('original'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'));
        $validated=$this->storage->getValidated($this->inscription);
        self::assertNotNull($validated); self::assertSame(1,$validated['version']); self::assertSame(realpath($first),$validated['path']);
        self::assertSame(hash_file('sha256',$first),$validated['hash']);
        self::assertSame(1,$validated['validatedBy']);
        $bytes=file_get_contents($first);
        $second=$this->storage->store($this->upload('corrected'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'),'Correction du nom');
        self::assertNotSame($first,$second); self::assertSame($bytes,file_get_contents($first));
        self::assertSame(2,$this->storage->getValidated($this->inscription)['version']);
        self::assertSame(realpath($first),$this->storage->getVersion($this->inscription,1)['path']);
        self::assertSame('Correction du nom',$this->inscription->getMeta()['workflowCertificate']['reason']);
        self::assertCount(1,$this->inscription->getMeta()['workflowCertificate']['history']);
        $this->expectException(\DomainException::class);
        $this->storage->store($this->upload('silent-replace'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'));
    }

    public function testNonPdfAndFutureDatesAreRejectedWithoutChangingTheInscription(): void
    {
        $file=$this->directory.'/invalid.pdf'; file_put_contents($file,'<html>not a certificate</html>');
        try {
            $this->storage->store(new UploadedFile($file,'certificate.pdf','application/pdf',null,true),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'));
            self::fail('Non-PDF accepted.');
        } catch (\DomainException $e) { self::assertStringContainsString('PDF',$e->getMessage()); }
        self::assertNull($this->storage->getValidated($this->inscription));
        try {
            $this->storage->store($this->upload('future'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('tomorrow'));
            self::fail('Future issue date accepted.');
        } catch (\DomainException $e) { self::assertStringContainsString('date',$e->getMessage()); }
        self::assertNull($this->storage->getValidated($this->inscription));
    }

    public function testCrossTenantDocumentsAndTamperedFilesAreNeverReturned(): void
    {
        $foreign=$this->identified(new Entite(),2);
        $this->workflow->setEntite($foreign);
        try {
            $this->storage->store($this->upload('foreign'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'));
            self::fail('Foreign entity accepted.');
        } catch (\DomainException $e) { self::assertStringContainsString('dossier',$e->getMessage()); }
        $this->workflow->setEntite($this->inscription->getEntite());
        $path=$this->storage->store($this->upload('original'),$this->workflow,$this->inscription,$this->actor,new \DateTimeImmutable('today'));
        file_put_contents($path,'%PDF-1.4 modified bytes');
        self::assertNull($this->storage->getValidated($this->inscription),'Tampered PDF must be blocked before sending.');
        $meta=$this->inscription->getMeta(); $meta['workflowCertificate']['file']='../../private-file.pdf'; $this->inscription->setMeta($meta);
        self::assertNull($this->storage->getValidated($this->inscription));
        self::assertNull($this->storage->getVersion($this->inscription,1));
    }

    private function upload(string $text): UploadedFile
    {
        $path=$this->directory.'/source-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($path,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ".$text."\n%%EOF");
        return new UploadedFile($path,'certificat.pdf','application/pdf',null,true);
    }

    private function identified(object $entity,int $id): mixed { (new \ReflectionProperty($entity,'id'))->setValue($entity,$id); return $entity; }
}
