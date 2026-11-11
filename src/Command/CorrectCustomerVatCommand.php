<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\{Facture, EInvoiceTransmission};
use App\Service\EInvoicing\{ConnectionLease, CustomerVatCorrection};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:einvoicing:correct-customer-vat', description: 'Corriger explicitement la TVA client invalide d’une facture jamais déposée. Simulation par défaut.')]
final class CorrectCustomerVatCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private ConnectionLease $leases, private CustomerVatCorrection $correction) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('numero', InputArgument::REQUIRED)
            ->addOption('entite', null, InputOption::VALUE_REQUIRED)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Appliquer la correction et archiver l’instantané précédent.')
            ->addOption('expected-old', null, InputOption::VALUE_REQUIRED, 'Ancienne TVA exacte affichée par la simulation.')
            ->addOption('expected-new', null, InputOption::VALUE_REQUIRED, 'Nouvelle TVA exacte affichée par la simulation.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getOption('entite');
        if (!ctype_digit($id) || (int) $id < 1) { $output->writeln('Organisme invalide.'); return Command::INVALID; }
        if ($input->getOption('execute') && ($input->getOption('expected-old') === null || $input->getOption('expected-new') === null)) {
            $output->writeln('Lancez la simulation, puis indiquez --expected-old et --expected-new pour appliquer.'); return Command::INVALID;
        }
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['numero' => $input->getArgument('numero'), 'entite' => (int) $id]);
        if (!$invoice) { $output->writeln('Facture introuvable dans cet organisme.'); return Command::FAILURE; }
        $rows = $this->em->getRepository(EInvoiceTransmission::class)->findBy(['facture' => $invoice]);
        if (count($rows) !== 1) { $output->writeln('Une transmission unique est requise. Vérification manuelle nécessaire.'); return Command::FAILURE; }
        $transmission = $rows[0]; $connectionId = $transmission->getConnection()->getId();
        $lease = $this->leases->acquire($connectionId);
        if ($lease === null) { $output->writeln('Une synchronisation est en cours. Réessayez après sa fin.'); return Command::FAILURE; }
        $db = $this->em->getConnection();
        try {
            $db->beginTransaction();
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($transmission, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->getEntrepriseDestinataire()) $this->em->refresh($invoice->getEntrepriseDestinataire(), LockMode::PESSIMISTIC_READ);
            $change = $this->correction->preview($invoice, $transmission);
            $output->writeln(['Ancienne TVA : '.$change['old'], 'Nouvelle TVA : '.$change['new']], OutputInterface::OUTPUT_RAW);
            if (!$change['changed']) {
                $output->writeln('La TVA est déjà identique. Aucune modification.');
            } elseif ($input->getOption('execute')) {
                $this->leases->renew($connectionId, $lease);
                $this->correction->apply($invoice, $transmission, (string) $input->getOption('expected-old'), (string) $input->getOption('expected-new'));
                $this->em->flush();
                $output->writeln('Correction appliquée et ancien instantané archivé. Aucun envoi effectué.');
            } else {
                $output->writeln('Simulation : aucune modification. Seule la TVA client sera corrigée ; montants, dates et numéro sont conservés.');
            }
            $db->commit();
            return Command::SUCCESS;
        } catch (\DomainException|\LogicException $error) {
            if ($db->isTransactionActive()) $db->rollBack();
            $output->writeln($error->getMessage(), OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        } finally {
            if ($db->isTransactionActive()) $db->rollBack();
            $this->leases->release($connectionId, $lease);
        }
    }
}
