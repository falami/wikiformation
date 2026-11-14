<?php
declare(strict_types=1);
namespace App\Command;
use App\Entity\Billing\ModuleAccess;
use App\Service\Module\ModuleBilling;
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:modules:reconcile', description: 'Reconcile module subscriptions and pending checkouts with Stripe (safe to repeat).')]
final class ReconcileModulesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private ModuleBilling $billing, private StripeClientFactory $stripe) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $count = 0;
        try {
            foreach ($this->em->getRepository(ModuleAccess::class)->findAll() as $row) {
                if ($row->checkoutId) {
                    $session = $this->stripe->client()->checkout->sessions->retrieve($row->checkoutId);
                    if ($session->status === 'complete') $this->billing->webhook('checkout.session.completed', $session->toArray());
                    elseif ($session->status === 'expired') $this->billing->webhook('checkout.session.expired', $session->toArray());
                }
                if ($row->subscriptionId) {
                    $this->billing->webhook('customer.subscription.updated', ['id' => $row->subscriptionId]);
                    ++$count;
                }
            }
        } catch (\Throwable $e) {
            $io->error('Synchronisation interrompue : '.$e::class.'. Corrigez la connexion Stripe puis relancez la commande.');
            return Command::FAILURE;
        }
        $io->success($count.' abonnement(s) rapproché(s).'); return Command::SUCCESS;
    }
}
