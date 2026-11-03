<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Utilisateur;
use App\Service\Preproduction\InitialData;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:preproduction:initialize', description: 'Initialisation unique de la base de préproduction vide ; simulation par défaut.')]
final class InitializePreproductionCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] private DependencyFactory $migrations,
        private InitialData $data,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private string $appUrl,
        #[Autowire('%env(MAILER_DSN)%')] private string $mailerDsn,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')] private string $stripeKey,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Créer les tables et les données initiales.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $db = $this->em->getConnection();
        $p = $db->getParams();
        if (rtrim($this->appUrl, '/') !== 'https://test.wikiformation.fr'
            || ($p['dbname'] ?? '') !== 'vibe1331_testwikiformation'
            || ($p['user'] ?? '') !== 'vibe1331_testwikiformation'
            || !in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true)
            || $this->mailerDsn !== 'null://null'
            || ($this->stripeKey !== '' && !str_starts_with($this->stripeKey, 'sk_test_'))) {
            $io->error('Initialisation refusée : domaine, base, utilisateur ou services externes non conformes à la préproduction.');
            return Command::FAILURE;
        }
        // This reviewed baseline is pinned: new entities or migrations require another audit.
        $manifest = json_decode(file_get_contents($this->projectDir.'/scripts/preproduction-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $files = [];
        foreach (['src/Entity', 'migrations'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectDir.'/'.$directory)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                $path = substr($file->getPathname(), strlen($this->projectDir) + 1);
                $files[$path] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        if ($files !== $manifest['files']) {
            $io->error('Le modèle ou les migrations ont changé depuis la préparation de cette initialisation. Un nouvel audit est nécessaire.');
            return Command::FAILURE;
        }
        $locked = false;
        $stage = 'connexion à la base';
        try {
            if ($db->fetchOne('SELECT DATABASE()') !== 'vibe1331_testwikiformation') throw new \RuntimeException();
            $locked = (int) $db->fetchOne("SELECT GET_LOCK('wk_preproduction_initialization', 0)") === 1;
            if (!$locked) { $io->error('Une initialisation est déjà en cours.'); return Command::FAILURE; }
            if ($db->createSchemaManager()->listTableNames() !== []) {
                $io->error('La base contient déjà des tables. Rien ne sera supprimé ni réinitialisé.');
                return Command::FAILURE;
            }
            $metadata = $this->em->getMetadataFactory()->getAllMetadata();
            $tool = new SchemaTool($this->em);
            $sql = $tool->getCreateSchemaSql($metadata);
            $available = $this->migrations->getMigrationRepository()->getMigrations()->getItems();
            $io->listing([
                count($sql).' instructions de création du schéma actuel.',
                '4 offres de référence, 1 offre fictive sans Stripe, 1 organisme et 1 compte administrateur.',
                count($available).' migrations historiques enregistrées comme couvertes par ce schéma initial.',
                'Aucun import de production, aucun e-mail et aucun paiement.',
            ]);
            if (!$input->getOption('execute')) {
                $io->success('Simulation terminée, aucune écriture. Relancer avec --execute pour initialiser.');
                return Command::SUCCESS;
            }
            if (!$input->isInteractive()) { $io->error('L’exécution exige une saisie interactive du compte initial.'); return Command::FAILURE; }
            $email = $io->ask('Email de connexion de votre administrateur de préproduction', null, static function ($value) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Adresse e-mail invalide.');
                return $value;
            });
            $passwordQuestion = (new Question('Mot de passe de préproduction (12 caractères minimum) : '))->setHidden(true)->setHiddenFallback(false);
            $passwordQuestion->setValidator(static function ($value) {
                if (!is_string($value) || strlen($value) < 12) throw new \InvalidArgumentException('Utilisez au moins 12 caractères.');
                return $value;
            });
            $password = $io->askQuestion($passwordQuestion);
            $confirmation = $io->askQuestion((new Question('Confirmez le mot de passe : '))->setHidden(true)->setHiddenFallback(false));
            if ($password !== $confirmation) { $io->error('Les mots de passe diffèrent. Aucune écriture.'); return Command::FAILURE; }
            $hash = $this->hasher->hashPassword(new Utilisateur(), $password);
            unset($password, $confirmation);
            $stage = 'création du schéma';
            $tool->createSchema($metadata);
            // MySQL DDL auto-commits: never pretend a failed bootstrap can roll back tables.
            $stage = 'initialisation des données et du suivi des migrations';
            $storage = $this->migrations->getMetadataStorage();
            $storage->ensureInitialized();
            $db->beginTransaction();
            $this->data->create($this->em, $email, $hash);
            foreach ($available as $migration) $storage->complete(new ExecutionResult($migration->getVersion(), executedAt: new \DateTimeImmutable()));
            $db->commit();
            $io->success('Préproduction initialisée. Le compte est vérifié et peut se connecter sans e-mail.');
            $io->note('Pour les prochaines mises à jour, utilisez le déploiement normal ciblant testwikiformation. Ne relancez pas cette initialisation.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            if ($db->isTransactionActive()) $db->rollBack();
            $io->error('Échec à l’étape : '.$stage.'. Les tables éventuellement créées sont conservées. Ne pas les supprimer automatiquement ; faire diagnostiquer l’état. Aucun secret affiché.');
            return Command::FAILURE;
        } finally {
            if ($locked) $db->fetchOne("SELECT RELEASE_LOCK('wk_preproduction_initialization')");
        }
    }
}
