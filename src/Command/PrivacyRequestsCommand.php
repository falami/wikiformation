<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Privacy\DataRightsRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:privacy:requests', description: 'Suivre les demandes RGPD ; aucune suppression de données ni envoi d’e-mail.')]
final class PrivacyRequestsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('reference', InputArgument::OPTIONAL, 'Référence RGPD à consulter ou traiter')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Afficher le contenu et le contact personnel de cette seule demande (terminal privé)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'processing ou answered')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Identifiant interne de l’opérateur pour la trace')
            ->addOption('response-file', null, InputOption::VALUE_REQUIRED, 'Fichier UTF-8 contenant la réponse destinée à la personne (10 000 caractères maximum)')
            ->addOption('overdue', null, InputOption::VALUE_NONE, 'Lister uniquement les demandes ouvertes dépassant le délai initial ; code de sortie 1 si présentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(DataRightsRequest::class);
        $reference = $input->getArgument('reference');
        if (!$reference) {
            if ($input->getOption('status') || $input->getOption('details') || $input->getOption('response-file') || $input->getOption('operator')) { $io->error('Une référence est requise pour cette action.'); return Command::INVALID; }
            $query = $repo->createQueryBuilder('r')->andWhere('r.status != :answered')->setParameter('answered', 'answered')->orderBy('r.dueAt', 'ASC');
            if ($input->getOption('overdue')) $query->andWhere('r.dueAt < :now')->setParameter('now', new \DateTimeImmutable());
            $total = (int) (clone $query)->select('COUNT(r.reference)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
            $requests = $query->setMaxResults(100)->getQuery()->getResult();
            $io->table(['Référence', 'Droit', 'Statut', 'Reçue', 'Échéance initiale'], array_map(fn(DataRightsRequest $r) => [$r->getReference(), $r->getTypeLabel(), $r->getStatusLabel(), $r->getRequestedAt()->format('Y-m-d'), $r->getDueAt()->format('Y-m-d')], $requests));
            $io->text($total.' demande(s) ouverte(s) ; au plus 100 lignes affichées, échéances les plus anciennes en premier.');
            $io->note('Surveiller quotidiennement cette file et la boîte contact. Une réponse dans le délai et les mesures demandées doivent être traitées par un opérateur habilité.');
            return $input->getOption('overdue') && $total > 0 ? Command::FAILURE : Command::SUCCESS;
        }
        $item = $repo->find($reference);
        if (!$item) { $io->error('Demande introuvable.'); return Command::INVALID; }
        if ($input->getOption('status')) {
            $response = null;
            if ($path = $input->getOption('response-file')) {
                if (!is_file($path) || !is_readable($path) || filesize($path) > 40000) { $io->error('Fichier de réponse illisible ou trop volumineux.'); return Command::INVALID; }
                $response = file_get_contents($path);
                if ($response === false || !mb_check_encoding($response, 'UTF-8')) { $io->error('La réponse doit être en UTF-8.'); return Command::INVALID; }
            }
            try { $item->updateStatus($input->getOption('status'), $input->getOption('operator') ?? '', $response); }
            catch (\InvalidArgumentException|\DomainException $error) { $io->error($error->getMessage()); return Command::INVALID; }
            $this->em->flush();
            $io->success('Statut enregistré. Aucune donnée métier supprimée et aucun e-mail envoyé.');
            if ($item->getStatus() === 'answered') $io->warning('La réponse est visible dans le compte. Informez la personne par le canal convenu et conservez la preuve de remise dans le dossier restreint. Une prolongation éventuelle doit être motivée et notifiée dans le premier mois.');
        } elseif ($input->getOption('response-file') || $input->getOption('operator')) { $io->error('Précisez --status pour modifier la demande.'); return Command::INVALID; }
        $io->definitionList(['Référence' => $item->getReference()], ['Droit' => $item->getTypeLabel()], ['Statut' => $item->getStatusLabel()], ['Échéance initiale' => $item->getDueAt()->format('Y-m-d H:i')]);
        if ($input->getOption('details')) {
            $io->text('Contact : '.OutputFormatter::escape($item->getOwner()?->getEmail() ?? '(compte supprimé ; consulter le dossier opérateur)'));
            $io->text('Demande : '.OutputFormatter::escape($item->getDetails()));
            $io->text('Réponse : '.OutputFormatter::escape($item->getResponse() ?? '(en attente)'));
        }
        return Command::SUCCESS;
    }
}
