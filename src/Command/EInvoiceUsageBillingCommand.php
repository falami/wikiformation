<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\EInvoiceConnection;
use App\Service\EInvoicing\EInvoiceUsageBilling;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:einvoicing:bill-usage', description: 'Simule ou regroupe les émissions électroniques au tarif accepté lors de leur transmission sur le prochain abonnement Stripe.')]
final class EInvoiceUsageBillingCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private EInvoiceUsageBilling $billing) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Mois civil terminé AAAA-MM (Europe/Paris).')
            ->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Limiter à un organisme.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Autoriser les écritures et l’ajout des lignes à l’abonnement Stripe.')
            ->addOption('reconcile', null, InputOption::VALUE_NONE, 'Avec --execute : rapprocher les lots incertains par lecture Stripe, sans créer de ligne.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $month = (string) $input->getOption('month'); $entite = $input->getOption('entite');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || $month >= (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m')
            || ($entite !== null && (!ctype_digit((string) $entite) || (int) $entite < 1))) {
            $io->error('Indiquez --month=AAAA-MM pour un mois terminé et un identifiant organisme valide.'); return Command::INVALID;
        }
        $criteria = ['environment' => 'production']; if ($entite !== null) $criteria['entite'] = (int) $entite;
        $rows = []; $errors = false;
        foreach ($this->em->getRepository(EInvoiceConnection::class)->findBy($criteria, ['id' => 'ASC']) as $connection) {
            try {
                $result = $this->billing->process($connection, $month, (bool) $input->getOption('execute'), (bool) $input->getOption('reconcile'));
                $rows[] = [$connection->getEntite()->getId(), $month, $result['quantity'], number_format($result['amountCents']/100, 2, ',', '').' €', $result['recorded'], $result['uncertain']];
                $errors = $errors || $result['uncertain'] > 0;
            } catch (\Throwable $e) {
                $errors = true;
                $io->warning('Organisme '.$connection->getEntite()->getId().' : '.($e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'traitement interrompu ; consulter les lots et rapprocher avant toute reprise.'));
            }
        }
        $io->table(['Organisme','Mois','Émissions','Total HT','Lots enregistrés','À rapprocher'], $rows);
        $io->note($input->getOption('execute') ? 'Les lignes sont rattachées au prochain abonnement Stripe concerné. Aucune facture client de formation n’a été modifiée. Enregistré ne signifie pas payé.' : 'Simulation : aucune écriture ni requête Stripe. Total des usages du mois, y compris ceux déjà regroupés.');
        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
