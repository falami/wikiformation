<?php

declare(strict_types=1);
namespace App\Command;

use App\Entity\Entite;
use App\Service\Habilitation\HabilitationAssignments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:habilitations:sync', description: 'Crée les évaluations manquantes des sessions commencées, y compris passées.')]
final class SyncHabilitationsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private HabilitationAssignments $assignments) { parent::__construct(); }
    protected function configure(): void { $this->addOption('entite', null, InputOption::VALUE_REQUIRED, 'Organisme à traiter.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('entite');
        if ($id !== null && (!ctype_digit((string)$id) || (int)$id < 1)) return Command::INVALID;
        $criteria = $id !== null ? ['id'=>(int)$id] : [];
        foreach ($this->em->getRepository(Entite::class)->findBy($criteria) as $entite) {
            $result = $this->assignments->synchronize($entite);
            $output->writeln(sprintf('Organisme %d : %d dossier(s) créé(s), %d inscription(s) à compléter (entreprise/formateur).', $entite->getId(), $result['created'], $result['incomplete']));
        }
        return Command::SUCCESS;
    }
}
