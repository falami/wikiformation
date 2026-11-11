<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\Facture;
use App\Service\Billing\InvoiceSnapshot;
use App\Service\EInvoicing\{EInvoiceRunner, InvoicePayload, SuperPdpClient, SuperPdpException};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:einvoicing:diagnose-conversion', description: 'Diagnostiquer une conversion UBL sans déposer la facture ni modifier la base.')]
final class DiagnoseEInvoiceConversionCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private InvoicePayload $payload, private SuperPdpClient $api) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('numero', InputArgument::REQUIRED, 'Numéro exact de facture.')
            ->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Organisme obligatoire.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Appeler le convertisseur et conserver son refus dans un fichier temporaire privé.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getOption('entite');
        if (!ctype_digit($id) || (int) $id < 1) { $output->writeln('Indiquez --entite avec un identifiant valide.'); return Command::INVALID; }
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['entite' => (int) $id, 'numero' => $input->getArgument('numero')]);
        if (!$invoice) { $output->writeln('Facture introuvable dans cet organisme.'); return Command::FAILURE; }
        $issues = $this->payload->issues($invoice);
        if ($issues !== []) { foreach ($issues as $issue) $output->writeln($issue, OutputInterface::OUTPUT_RAW); return Command::FAILURE; }
        if (!$input->getOption('execute')) { $output->writeln('Contrôles locaux réussis. Ajoutez --execute pour tester la conversion seule.'); return Command::SUCCESS; }
        $report = null;
        try {
            $snapshot = InvoiceSnapshot::read($invoice);
            $directory = $this->api->directoryEntries($snapshot['customer']['siren']);
            $endpoint = EInvoiceRunner::recipientAddress($snapshot['customer']['siren'], $directory['data']);
            $this->api->convertInvoice($this->payload->build($invoice, $endpoint), static function (string $body) use (&$report): void {
                // tempnam creates an unpredictable file with mode 0600, outside the public web directory.
                $file = tempnam(sys_get_temp_dir(), 'einvoice-error-');
                if ($file === false) throw new \RuntimeException('Impossible de créer le rapport privé.');
                if (file_put_contents($file, $body, LOCK_EX) !== strlen($body)) { unlink($file); throw new \RuntimeException('Impossible d’écrire le rapport privé.'); }
                $report = $file;
            });
            $output->writeln('Conversion réussie. Aucun dépôt de facture ni modification en base.');
            return Command::SUCCESS;
        } catch (SuperPdpException|\DomainException $error) {
            $output->writeln($error->getMessage(), OutputInterface::OUTPUT_RAW);
            if ($report !== null) {
                $output->writeln('Détail du refus dans le fichier privé : '.$report, OutputInterface::OUTPUT_RAW);
                $output->writeln('Consultez-le sur le serveur ; masquez les données personnelles avant partage, puis supprimez ce fichier.');
            }
            return Command::FAILURE;
        }
    }
}
