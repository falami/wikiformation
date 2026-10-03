<?php

namespace App\Command;

use App\Entity\Automation\TrainingWorkflow;
use App\Service\Automation\{WorkflowPlanner, WorkflowRunner};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:workflows:run', description: 'Exécute les étapes dues des dossiers activés (simulation par défaut).')]
final class RunTrainingWorkflowsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WorkflowPlanner $planner, private readonly WorkflowRunner $runner) { parent::__construct(); }
    protected function configure(): void { $this->addOption('execute', null, InputOption::VALUE_NONE, 'Autoriser les documents et envois réels.')->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Limiter à un organisme.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $criteria = ['enabled' => true];
        if ($input->getOption('entite')) $criteria['entite'] = (int) $input->getOption('entite');
        $now = new \DateTimeImmutable(); $count = 0; $failed = false;
        foreach ($this->em->getRepository(TrainingWorkflow::class)->findBy($criteria) as $workflow) {
            if (!$input->getOption('execute')) {
                foreach ($this->planner->schedule($workflow) as [$action, $target, $due]) if ($due <= $now) { $output->writeln(sprintf('Simulation dossier %d : %s, %s (Europe/Paris)', $workflow->getId(), $action, $due->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'))); ++$count; }
                continue;
            }
            try {
                $attempted = [];
                // The import can create personal invitations. Pick those up in the same run,
                // without attempting a blocked task twice during this invocation.
                for ($pass = 0; $pass < 2; ++$pass) {
                    foreach ($this->planner->synchronize($workflow) as $task) {
                        if (isset($attempted[$task->getId()])) continue;
                        $attempted[$task->getId()] = true;
                        $status = $this->runner->run($task, $now);
                        if ($status !== 'unchanged') { $output->writeln(sprintf('Dossier %d / étape %d : %s', $workflow->getId(), $task->getId(), $status)); ++$count; }
                        if ($status === 'unknown') $failed = true;
                    }
                }
            } catch (\Throwable $e) {
                $failed = true;
                $output->writeln(sprintf('<error>Dossier %d interrompu. Vérifiez les tâches en cours avant toute reprise : %s</error>', $workflow->getId(), $e->getMessage()));
                if (!$this->em->isOpen()) break;
            }
        }
        $output->writeln($count . ' étape(s) examinée(s).');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
