<?php
declare(strict_types=1);
namespace App\Command;
use App\Entity\Facture;
use App\Service\Billing\{InvoiceFollowUp, InvoiceReminderRunner};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:invoice-reminders:run', description: 'Relances des factures impayées activées individuellement (simulation par défaut).')]
final class RunInvoiceRemindersCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly InvoiceReminderRunner $runner) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Transmettre les e-mails à la messagerie.')->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Limiter à un organisme.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('entite');
        if ($id !== null && (!ctype_digit((string)$id) || (int)$id < 1)) return Command::INVALID;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $failed = false; $count = 0;
        foreach ($this->em->getRepository(Facture::class)->findBy($id === null ? [] : ['entite' => (int)$id]) as $invoice) {
            $date = InvoiceFollowUp::reminderDate($invoice);
            if (!$date || $date > $now) continue;
            if (!InvoiceReminderRunner::recipient($invoice)) { $output->writeln('Facture '.$invoice->getId().' : adresse de facturation absente ou invalide.'); continue; }
            try {
                $status = $input->getOption('execute') ? $this->runner->send($invoice, $now) : 'simulation';
                $failed = $failed || $status === 'unknown';
                $output->writeln('Facture '.$invoice->getId().' : '.$status);
                ++$count;
            } catch (\Throwable $error) {
                $output->writeln('<error>Traitement interrompu : '.$error->getMessage().'</error>');
                return Command::FAILURE;
            }
        }
        $output->writeln($count.' relance(s) examinée(s).');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
