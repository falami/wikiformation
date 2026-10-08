<?php

declare(strict_types=1);
namespace App\Command;

use App\Entity\Entite;
use App\Service\TrainerReminder\TrainerReminderRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:trainer-reminders:run', description: 'Relances formateurs par organisme (simulation sans --execute).')]
final class RunTrainerRemindersCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TrainerReminderRunner $runner) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Envoyer les e-mails activés dans les paramètres.')->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Limiter à un organisme.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('entite');
        if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) { $output->writeln('<error>Identifiant organisme invalide.</error>'); return Command::INVALID; }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $count = 0; $failed = false;
        foreach ($this->em->getRepository(Entite::class)->findBy($id === null ? [] : ['id' => (int) $id]) as $entite) {
            try {
                foreach ($this->runner->candidates($entite, $now) as $message) {
                    $status = $input->getOption('execute') ? $this->runner->send($message, $now) : 'simulation';
                    $output->writeln(sprintf('Organisme %d · %s · %s', $entite->getId(), $message['scope'], $status));
                    ++$count; $failed = $failed || $status === 'unknown';
                }
            } catch (\Throwable $error) {
                $output->writeln(sprintf('<error>Organisme %d : %s</error>', $entite->getId(), $error->getMessage()));
                $failed = true;
            }
        }
        $output->writeln($count.' message(s) examiné(s).');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
