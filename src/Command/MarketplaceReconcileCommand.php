<?php
namespace App\Command;
use App\Entity\Marketplace\Purchase;
use App\Service\Marketplace\Payments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
#[AsCommand(name:'app:marketplace:reconcile',description:'Vérifier les paiements ; --settle autorise les reversements et leurs reprises.')]
final class MarketplaceReconcileCommand extends Command {
 public function __construct(private EntityManagerInterface $em,private Payments $payments,private LockFactory $locks,private \App\Service\Marketplace\Confirmations $confirmations) {parent::__construct();}
 protected function configure(): void {$this->addOption('settle',null,InputOption::VALUE_NONE,'Effectuer les reversements et reprises de remboursements.');}
 protected function execute(InputInterface $input,OutputInterface $output): int {
  $lock=$this->locks->createLock('marketplace-reconciliation',3600);if (!$lock->acquire()) return self::SUCCESS;
  $errors=0;
  try {
   foreach ($this->em->getRepository(Purchase::class)->findAll() as $purchase) {
    if (!$purchase->checkoutId || $purchase->status==='expired') continue;
    try { $input->getOption('settle') ? $this->payments->settle($purchase) : $this->payments->sync($purchase); $this->confirmations->send($purchase); }
    catch (\Throwable $e) {++$errors;$output->writeln('<error>Commande '.$purchase->id.' : rapprochement à vérifier.</error>');}
   }
  } finally {$lock->release();}
  return $errors?self::FAILURE:self::SUCCESS;
 }
}
