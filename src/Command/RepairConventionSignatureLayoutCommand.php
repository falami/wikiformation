<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\{ConventionContrat, ConventionRevision, Utilisateur};
use App\Service\Convention\ConventionSignaturePdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'app:convention:repair-signature-layout', description: 'Replace a verified legacy signature annex with placement in the client box, retaining the old PDF in a revision.')]
final class RepairConventionSignatureLayoutCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private ConventionSignaturePdf $pdf, #[Autowire('%kernel.project_dir%')] private string $root) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('id', InputArgument::REQUIRED, 'Convention ID'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c=$this->em->find(ConventionContrat::class,(int)$input->getArgument('id'));
        if (!$c || !$c->getSignatureDataUrlEntreprise() || !$c->getDateSignatureEntreprise()) {
            $output->writeln('Aucune signature entreprise à repositionner.');return Command::FAILURE;
        }
        $revision=$this->em->getRepository(ConventionRevision::class)->findOneBy(['convention'=>$c],['id'=>'DESC']);
        $data=$revision?->getDonnees() ?? [];
        $stored=$c->getPdfPath() ?? '';
        $path=$this->root.'/var/storage/conventions/'.basename(substr($stored,8));
        if (!str_starts_with($stored,'private:') || !is_file($path) || ($data['event']??'')!=='portal_signature'
            || ($data['role']??'')!=='entreprise'
            || !hash_equals($data['signedPdfSha256']??'',hash_file('sha256',$path))
            || !hash_equals($data['signatureSha256']??'',hash('sha256',$c->getSignatureDataUrlEntreprise()))
            || !hash_equals($data['sourceSha256']??'',hash('sha256',$revision->getPdf()))) {
            $output->writeln('Historique non vérifiable : aucun changement.');return Command::FAILURE;
        }
        $old=file_get_contents($path);
        $reader=new \setasign\Fpdi\Fpdi();
        $oldCount=$reader->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($old));
        $sourceCount=$reader->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($revision->getPdf()));
        if ($oldCount!==$sourceCount+1) { $output->writeln('Aucune annexe à remplacer.');return Command::SUCCESS; }
        $actor=$this->em->find(Utilisateur::class,$data['actorId']);
        if (!$actor) return Command::FAILURE;
        $bytes=$this->pdf->append($revision->getPdf(),$c,$actor,'entreprise',$c->getSignatureDataUrlEntreprise(),$c->getDateSignatureEntreprise());
        $filename='layout-'.$c->getId().'-'.bin2hex(random_bytes(16)).'.pdf';
        $newPath=dirname($path).'/'.$filename;
        try {
            $this->em->wrapInTransaction(function() use($c,$stored,$old,$bytes,$newPath,$filename):void {
                $this->em->refresh($c,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if ($c->getPdfPath()!==$stored) throw new \RuntimeException('La convention a changé.');
                (new Filesystem())->dumpFile($newPath,$bytes);chmod($newPath,0660);
                $c->setPdfPath('private:'.$filename);
                $this->em->persist(new ConventionRevision($c,[
                    'event'=>'signature_layout_repair','previousPdf'=>$stored,
                    'sourceSha256'=>hash('sha256',$old),'signedPdfSha256'=>hash('sha256',$bytes),
                ],'Signature existante déplacée dans l’encart client','Correction de mise en page',$old));
            });
        } catch (\Throwable $e) { if(is_file($newPath))unlink($newPath);throw $e; }
        $output->writeln('Convention '.$c->getId().' corrigée ; ancien PDF archivé.');return Command::SUCCESS;
    }
}
