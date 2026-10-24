<?php
namespace App\Command;
use App\Service\Session\SessionStartReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
#[AsCommand(name:'app:session-start-reminders:run', description:'Rappels de la veille (simulation sans --execute).')]
final class RunSessionStartRemindersCommand extends Command {
 public function __construct(private SessionStartReminder $runner, private LockFactory $locks) { parent::__construct(); }
 protected function configure(): void { $this->addOption('execute',null,InputOption::VALUE_NONE,'Envoyer les rappels'); }
 protected function execute(InputInterface $input, OutputInterface $output): int {
  $lock=$this->locks->createLock('session-start-reminders',3600);
  if (!$lock->acquire()) { $output->writeln('Un traitement est déjà en cours.'); return Command::SUCCESS; }
  try { $result=$this->runner->run(new \DateTimeImmutable(),(bool)$input->getOption('execute')); $output->writeln(json_encode($result)); return $result['uncertain'] ? Command::FAILURE : Command::SUCCESS; }
  finally { $lock->release(); }
 }
}
