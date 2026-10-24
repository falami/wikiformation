<?php

declare (strict_types=1);
namespace App\Command;

use App\Entity\Partner\Commission;
use App\Service\Partner\Commissions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
#[AsCommand(name: 'app:partners:payouts', description: 'Rapproche les commissions et verse celles échues à J+30. Simulation par défaut.')]
final class PartnerPayoutCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private Commissions $commissions,
        #[Autowire('%env(bool:default:app.partner_payouts_default:PARTNER_PAYOUTS_ENABLED)%')]
        private bool $enabled
    )
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Effectuer les transferts et virements configurés.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        if ($execute && !$this->enabled) {
            $output->writeln('<error>Versements désactivés : PARTNER_PAYOUTS_ENABLED=0.</error>');
            return Command::FAILURE;
        }
        $failed = false;
        foreach ($this->em->getRepository(Commission::class)->findBy([], ['id' => 'ASC']) as $c) {
            try {
                $status = $this->commissions->process($c, $execute);
                $output->writeln('#' . $c->id . ' · ' . $status . ($execute ? '' : ' · simulation'));
            } catch (\Throwable $e) {
                $failed = true;
                if ($this->em->isOpen()) {
                    $c->issue = 'Traitement interrompu. Consulter les journaux et les opérations Stripe avant toute reprise.';
                    if ($c->transferId && !$c->payoutAttemptAt) {
                        $c->status = 'held';
                    }
                    $this->em->flush();
                }
                $output->writeln('<error>#' . $c->id . ' · erreur de rapprochement (aucune confirmation de paiement).</error>');
                error_log('[PARTNER ' . $c->id . '] ' . $e->getMessage());
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
