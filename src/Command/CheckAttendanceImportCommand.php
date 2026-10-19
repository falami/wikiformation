<?php
namespace App\Command;

use App\Service\Attendance\PaperImport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name:'app:attendance-import:check', description:'Vérifie les prérequis locaux des imports d’émargements sans modifier les données.')]
final class CheckAttendanceImportCommand extends Command
{
    public function __construct(private PaperImport $imports) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output);
        $ok=extension_loaded('gd') && function_exists('proc_open');
        $rows=[['PHP GD',extension_loaded('gd')?'Disponible':'Manquant','PDF / Word'],['proc_open',function_exists('proc_open')?'Disponible':'Désactivé','PDF / Word']];
        foreach (['pdftoppm'=>'PDF / Word','pdfinfo'=>'PDF / Word','tesseract'=>'OCR facultatif','libreoffice'=>'Word uniquement'] as $name=>$usage) {
            $path=$this->imports->findBinary($name);
            $rows[]=[$name,$path ?? 'Introuvable',$usage];
            if (!$path && in_array($name,['pdftoppm','pdfinfo'],true)) $ok=false;
        }
        $rows[]=['PHP Zip',extension_loaded('zip')?'Disponible':'Manquant','Word uniquement'];
        $io->table(['Prérequis','État / chemin','Usage'],$rows);
        $io->note('Diagnostic du PHP en ligne de commande. Le PATH et les fonctions autorisées peuvent différer du PHP du site. ATTENDANCE_TOOLS_DIR permet de fixer le dossier des exécutables. Les langues OCR fra et eng doivent aussi être installées.');
        if (!$ok) { $io->error('Import PDF indisponible. Installer les dépendances manquantes ou configurer leur chemin sur cet hébergement.'); return Command::FAILURE; }
        $io->success('Prérequis PDF détectés.');
        return Command::SUCCESS;
    }
}
