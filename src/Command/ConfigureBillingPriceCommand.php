<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Billing\Plan;
use App\Repository\Billing\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:billing:configure-price', description: 'Associe un tarif Stripe existant au catalogue local, sans créer ni modifier d’abonnement Stripe.')]
final class ConfigureBillingPriceCommand extends Command
{
    public function __construct(private readonly PlanRepository $plans, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('plan', InputArgument::REQUIRED, 'Code de l’offre, par exemple SOLO.')
            ->addArgument('interval', InputArgument::REQUIRED, 'month (les nouvelles offres sont mensuelles).')
            ->addArgument('price-id', InputArgument::REQUIRED, 'Identifiant price_... du tarif Stripe existant.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher la configuration sans la sauvegarder.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plan = $this->plans->findOneBy(['code' => strtoupper((string) $input->getArgument('plan')), 'isActive' => true]);
        $interval = (string) $input->getArgument('interval');
        $priceId = trim((string) $input->getArgument('price-id'));
        if (!$plan instanceof Plan || !$plan->isAvailableForNewSubscription($interval) || !preg_match('/^price_[a-zA-Z0-9]+$/D', $priceId)) {
            $io->error('Offre, périodicité ou identifiant Stripe invalide.');
            return Command::INVALID;
        }
        $amount = $plan->getPriceFor($interval);
        if (!$amount || $amount <= 0) {
            $io->error('Le montant de cette périodicité doit être configuré avant son activation.');
            return Command::INVALID;
        }
        foreach ($this->plans->findAll() as $other) {
            if ($other->getId() !== $plan->getId() && in_array($priceId, [$other->getStripePriceMonthlyId(), $other->getStripePriceYearlyId()], true)) {
                $io->error('Ce tarif Stripe est déjà associé à une autre offre.');
                return Command::INVALID;
            }
        }
        $io->table(['Offre','Périodicité','Montant HT','Tarif Stripe'], [[$plan->getName(), $interval, number_format($amount / 100, 2, ',', ' ') . ' €', $priceId]]);
        if (!$input->getOption('dry-run')) {
            $plan->setStripePriceMonthlyId($priceId);
            $this->em->flush();
        }
        $io->success($input->getOption('dry-run') ? 'Simulation : aucune modification.' : 'Catalogue local configuré. Aucun abonnement existant modifié.');
        $io->note('Le montant, la devise EUR, la taxe exclusive et la récurrence Stripe seront vérifiés avant chaque souscription ou changement d’offre.');
        return Command::SUCCESS;
    }
}
