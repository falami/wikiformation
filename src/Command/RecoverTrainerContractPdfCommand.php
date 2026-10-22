<?php

namespace App\Command;

use App\Entity\ContratFormateur;
use App\Service\Pdf\{ContratFormateurDocument, PdfManager};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

#[AsCommand(name: 'app:contrat-formateur:recuperer-pdf', description: 'Reconstitue une seule fois un PDF signé manquant, avec journal technique de récupération.')]
final class RecoverTrainerContractPdfCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContratFormateurDocument $document,
        private PdfManager $pdf,
        private Environment $twig,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('contrat', InputArgument::REQUIRED, 'Identifiant du contrat')
            ->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Identifiant de l’organisme')
            ->addOption('numero', null, InputOption::VALUE_REQUIRED, 'Numéro exact du contrat')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Effectuer la récupération ; sinon vérifier seulement');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contract = $this->em->find(ContratFormateur::class, (int) $input->getArgument('contrat'));
        if (!$contract || $contract->getEntite()?->getId() !== (int) $input->getOption('entite')
            || $contract->getNumero() !== $input->getOption('numero')) {
            $io->error('Contrat, organisme ou numéro incohérent.');
            return Command::FAILURE;
        }
        $dir = $this->projectDir . '/var/storage/contrat-formateur-recoveries';
        $audit = $dir . '/' . $contract->getId() . '.json';
        if (!$this->document->isFrozen($contract) || $this->document->storedPath($contract) || is_file($audit)) {
            $io->error('Récupération refusée : contrat non figé, PDF déjà présent ou récupération déjà effectuée.');
            return Command::FAILURE;
        }
        $io->note('Reconstitution à partir des données actuelles, avec les signatures encore enregistrées. Ce document ne remplace pas la preuve du PDF original.');
        if (!$input->getOption('execute')) {
            $io->success('Contrat éligible. Ajouter --execute pour effectuer la récupération.');
            return Command::SUCCESS;
        }
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Journal de récupération indisponible.');
        }
        // Exclusive creation prevents concurrent or repeated recovery, even if the PDF is lost again.
        $journal = fopen($audit, 'x');
        if (!$journal) {
            $io->error('Une récupération existe déjà.');
            return Command::FAILURE;
        }
        $at = new \DateTimeImmutable();
        $record = ['contract' => $contract->getId(), 'entite' => $contract->getEntite()->getId(),
            'numero' => $contract->getNumero(), 'date' => $at->format(DATE_ATOM),
            'previousPath' => $contract->getPdfPath(), 'state' => 'started'];
        fwrite($journal, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($journal);
        try {
            $data = $this->document->templateData($contract);
            // Do not use today's organisation signature as a substitute for a missing historical one.
            if (!$contract->getSignatureOrganismePath()) $data['orgSigDataUri'] = null;
            $bytes = $this->pdf->createPortraitBytes($this->twig->render('pdf/contrat_formateur.html.twig', $data));
            $relative = 'uploads/contrats-reconstitues/' . bin2hex(random_bytes(24)) . '.pdf';
            $destination = $this->projectDir . '/public/' . $relative;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0770, true) && !is_dir(dirname($destination))) {
                throw new \RuntimeException('Stockage PDF indisponible.');
            }
            if (!$bytes || file_put_contents($destination, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Écriture du PDF impossible.');
            }
            $contract->setPdfPath($relative);
            $this->em->flush();
            $record += ['path' => $relative, 'sha256' => hash('sha256', $bytes)];
            $record['state'] = 'completed';
            rewind($journal);
            ftruncate($journal, 0);
            fwrite($journal, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $io->success('PDF reconstitué et conservé. Le statut et les signatures du contrat sont inchangés.');
            return Command::SUCCESS;
        } finally {
            fclose($journal);
        }
    }
}
