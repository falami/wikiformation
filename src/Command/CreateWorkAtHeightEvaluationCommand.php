<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\{Entite, EvaluationTemplate, Utilisateur, UtilisateurEntite};
use App\Service\Evaluation\{EvaluationSchema, WorkAtHeightPreset};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:evaluations:creer-travail-hauteur', description: 'Crée et active le modèle Travail en hauteur issu des documents fournis (simulation sans --execute).')]
final class CreateWorkAtHeightEvaluationCommand extends Command
{
    private const TITLE = 'Travail en hauteur — théorie et pratique';

    public function __construct(private EntityManagerInterface $em, private WorkAtHeightPreset $preset, private EvaluationSchema $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('entite', InputArgument::REQUIRED, 'Identifiant de l’organisme.')
            ->addOption('auteur', null, InputOption::VALUE_REQUIRED, 'Identifiant de l’administrateur ; par défaut, créateur de l’organisme.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Enregistrer et activer le modèle.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('entite');
        $authorId = $input->getOption('auteur');
        if (!ctype_digit($id) || (int) $id < 1 || ($authorId !== null && (!ctype_digit((string) $authorId) || (int) $authorId < 1))) {
            $io->error('Les identifiants doivent être des entiers positifs.');
            return Command::INVALID;
        }
        $tenant = $this->em->find(Entite::class, (int) $id);
        if (!$tenant) {
            $io->error('Organisme introuvable.');
            return Command::INVALID;
        }
        $author = $authorId === null ? $tenant->getCreateur() : $this->em->find(Utilisateur::class, (int) $authorId);
        $membership = $author ? $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['entite' => $tenant, 'utilisateur' => $author]) : null;
        if (!$membership?->isActive() || !$membership->isTenantAdmin()) {
            $io->error('Choisissez avec --auteur un administrateur actif de cet organisme.');
            return Command::INVALID;
        }
        $schema = $this->validator->validate($this->preset->schema());
        $io->title(self::TITLE);
        $io->text(sprintf('Organisme : %s (#%d). Auteur : #%d.', $tenant->getNom(), $tenant->getId(), $author->getId()));
        $io->listing(['15 questions théoriques / 20 points et corrigé fourni.', '8 critères pratiques / 20 points, remplis par le formateur.', 'Aucun seuil imposé ; seuils modifiables dans le modèle. Deux avis favorables obligatoires pour l’attestation.']);

        $write = (bool) $input->getOption('execute');
        if ($write) {
            $this->em->beginTransaction();
        }
        try {
            // Serialize repeated CLI imports for the same organisation.
            if ($write) $this->em->lock($tenant, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(EvaluationTemplate::class)->findBy(['entite' => $tenant, 'title' => self::TITLE, 'trainerOwner' => null]);
            if (count($existing) > 1) {
                $io->error('Plusieurs modèles portent déjà ce titre. Vérifiez-les dans Théorie & pratique ; aucun changement effectué.');
                if ($write) $this->em->rollback();
                return Command::FAILURE;
            }
            $template = $existing[0] ?? null;
            if ($template && $this->validator->validate($template->getSchema()) !== $schema) {
                $io->error(sprintf('Le modèle #%d existe avec un contenu ou des seuils personnalisés. Il est conservé ; modifiez ou activez-le depuis Théorie & pratique.', $template->getId()));
                if ($write) $this->em->rollback();
                return Command::FAILURE;
            }
            if (!$write) {
                $io->note($template ? sprintf('Simulation : modèle #%d déjà présent ; il sera activé si nécessaire.', $template->getId()) : 'Simulation : un modèle actif sera créé. Ajoutez --execute pour enregistrer.');
                return Command::SUCCESS;
            }
            if (!$template) {
                $template = (new EvaluationTemplate())->setEntite($tenant)->setCreateur($author)->setTitle(self::TITLE)->setSchema($schema)->setActive(true);
                $this->em->persist($template);
            } elseif (!$template->isActive()) {
                $template->setActive(true)->setVersion($template->getVersion() + 1);
            }
            $this->em->flush();
            $this->em->commit();
            $io->success(sprintf('Modèle #%d actif et prêt à être affecté. Aucun doublon créé.', $template->getId()));
            $io->text('Ouvrez Questionnaires → Théorie & pratique → Affecter une évaluation pour choisir la session, les stagiaires et le formateur.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($write && $this->em->getConnection()->isTransactionActive()) $this->em->rollback();
            throw $e;
        }
    }
}
