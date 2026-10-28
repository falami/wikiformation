<?php
declare(strict_types=1);
namespace App\Command;
use App\Service\Platform\StripeRevenue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:platform:revenue-sync', description:'Rapproche les factures Stripe payées des abonnements (lecture seule chez Stripe).')]
final class PlatformRevenueSyncCommand extends Command
{
    public function __construct(private StripeRevenue $revenue) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->revenue->sync().' factures rapprochées.');
        return Command::SUCCESS;
    }
}
