<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\EInvoiceConnection;
use App\Service\EInvoicing\EInvoiceRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:einvoicing:sync', description: 'Facturation électronique : simulation locale sans --execute.')]
final class SyncEInvoicesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private EInvoiceRunner $runner) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Synchroniser les connexions de production et transmettre les nouvelles factures autorisées.')
            ->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Limiter à un organisme.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Taille des lots, de 1 à 100.', '25');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('entite'); $limit = (string) $input->getOption('limit');
        if (($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $output->writeln('<error>Organisme ou taille de lot invalide.</error>'); return Command::INVALID;
        }
        $failed = false;
        $output->writeln($input->getOption('execute') ? 'Synchronisation de production.' : 'Simulation locale : aucun appel à la plateforme, aucune transmission.');
        // Keyset batching avoids loading an unbounded tenant catalogue into memory.
        $cursor = 0;
        do {
            $qb = $this->em->createQueryBuilder()->select('c')->from(EInvoiceConnection::class, 'c')
                ->where('c.environment = :environment AND c.verified = true AND c.id > :cursor')
                ->setParameter('environment', 'production')->setParameter('cursor', $cursor)->orderBy('c.id', 'ASC')->setMaxResults(100);
            if ($id !== null) $qb->andWhere('IDENTITY(c.entite) = :entite')->setParameter('entite', (int) $id);
            $connections = $qb->getQuery()->getResult();
            foreach ($connections as $connection) {
                $result = $this->runner->run($connection, (bool) $input->getOption('execute'), (int) $limit);
                $output->writeln(sprintf('Organisme %d : %d nouvelle(s), %d bloquée(s), %d envoyée(s), %d rejetée(s), %d rapprochée(s), %d reçue(s), %d incertaine(s).',
                    $connection->getEntite()->getId(), $result['discovered'], $result['blocked'], $result['submitted'], $result['rejected'], $result['reconciled'], $result['received'], $result['uncertain']));
                if ($result['skipped']) $output->writeln($result['skipped']);
                foreach ($result['errors'] as $error) $output->writeln('<error>'.htmlspecialchars($error, ENT_QUOTES).'</error>');
                $failed = $failed || $result['errors'] !== [] || $result['uncertain'] > 0 || $result['rejected'] > 0;
                $cursor = $connection->getId();
            }
            $this->em->clear();
        } while (count($connections) === 100);
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
