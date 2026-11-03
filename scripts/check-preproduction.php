<?php

declare(strict_types=1);

// Read-only preflight. Never prints connection strings, keys or passwords.


use Doctrine\DBAL\DriverManager;
use Symfony\Component\Dotenv\Dotenv;

$stage = 'chargement des dépendances';
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $stage = 'lecture des fichiers de configuration';
    if (!is_file(dirname(__DIR__) . '/.env') && !is_file(dirname(__DIR__) . '/.env.dist')) {
        fwrite(STDERR, "[À CORRIGER] Le fichier de base .env est absent. .env.prod.local seul ne suffit pas au chargement standard de Symfony. Créez un .env vide à la racine de cette installation, sans copier celui de production.\n");
        exit(1);
    }
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env', 'prod');
    $env = static function (string $name): string {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return $value === false ? '' : (string) $value;
    };
    $checks = [
        'Environnement prod, debug désactivé' => $env('APP_ENV') === 'prod' && $env('APP_DEBUG') === '0',
        'URL dédiée à la préproduction' => rtrim($env('APP_URL'), '/') === 'https://test.wikiformation.fr',
        'Domaine dédié' => $env('APP_BASE_DOMAIN') === 'test.wikiformation.fr',
        'Secret applicatif renseigné' => strlen($env('APP_SECRET')) >= 32 && !str_contains($env('APP_SECRET'), 'UN_SECRET'),
        'Envoi de mails désactivé pour l’installation' => $env('MAILER_DSN') === 'null://null',
        'Clé Stripe secrète absente ou de test' => $env('STRIPE_SECRET_KEY') === '' || str_starts_with($env('STRIPE_SECRET_KEY'), 'sk_test_'),
        'Clé Stripe publique absente ou de test' => $env('STRIPE_PUBLIC_KEY') === '' || str_starts_with($env('STRIPE_PUBLIC_KEY'), 'pk_test_'),
    ];
    foreach ($checks as $label => $ok) echo ($ok ? '[OK] ' : '[À CORRIGER] ') . $label . PHP_EOL;
    if (in_array(false, $checks, true)) exit(1);
    $stage = 'lecture de DATABASE_URL (format de connexion)';
    if ($env('DATABASE_URL') === '') throw new RuntimeException();
    // Report variable names only: an empty value is acceptable for disabled integrations.
    $required = ['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET',
        'FULLCALENDAR_SCHEDULER_KEY', 'GOOGLE_MAPS_SERVER_KEY', 'GOOGLE_MAPS_BROWSER_KEY',
        'KM_RATE_EUR', 'GCP_PROJECT_ID', 'GOOGLE_APPLICATION_CREDENTIALS',
        'INSEE_CLIENT_ID', 'INSEE_CLIENT_SECRET', 'RECAPTCHA3_KEY', 'RECAPTCHA3_SECRET'];
    $missing = array_values(array_filter($required, static fn($name) =>
        !array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV) && getenv($name) === false));
    if ($missing) {
        echo '[À CORRIGER] Variables requises non définies : ' . implode(', ', $missing) . PHP_EOL;
        echo 'Définir les intégrations inutilisées à une chaîne vide ; KM_RATE_EUR doit être un nombre, par exemple 0.' . PHP_EOL;
        exit(1);
    }
    if (!is_numeric($env('KM_RATE_EUR'))) {
        echo '[À CORRIGER] KM_RATE_EUR doit être un nombre (exemple : 0).' . PHP_EOL;
        exit(1);
    }
    $connection = DriverManager::getConnection(['url' => $env('DATABASE_URL')]);
    $params = $connection->getParams();
    $checks['Base et utilisateur réservés à la préproduction'] =
        ($params['dbname'] ?? '') === 'vibe1331_testwikiformation'
        && ($params['user'] ?? '') === 'vibe1331_testwikiformation'
        && in_array($params['host'] ?? '', ['localhost', '127.0.0.1'], true);
    $ok = $checks['Base et utilisateur réservés à la préproduction'];
    echo ($ok ? '[OK] ' : '[À CORRIGER] ') . 'Base et utilisateur réservés à la préproduction' . PHP_EOL;
    if (!$ok) exit(1);
    $stage = 'connexion MySQL';
    $database = $connection->fetchOne('SELECT DATABASE()');
    if ($database !== 'vibe1331_testwikiformation') throw new RuntimeException();
    $stage = 'lecture du nombre de tables';
    $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    echo '[OK] Connexion à la base de préproduction.' . PHP_EOL;
    echo 'Tables présentes : ' . $count . PHP_EOL;
    echo 'Aucune écriture effectuée. Ce contrôle ne valide pas encore le schéma ni les autres services externes.' . PHP_EOL;
    $connection->close();
} catch (Throwable $error) {
    // Exception messages may contain credentials; keep diagnostics deliberately generic.
    $hint = match (true) {
        $error instanceof \Symfony\Component\Dotenv\Exception\FormatException => 'Syntaxe invalide dans un fichier .env : vérifier les guillemets et les caractères spéciaux.',
        $error instanceof \Doctrine\DBAL\Exception\ConnectionException => 'Connexion refusée ou serveur inaccessible : vérifier le mot de passe, localhost et les droits de l’utilisateur sur la base.',
        default => 'Vérifier cette étape. Aucun message technique contenant potentiellement un secret n’est affiché.',
    };
    fwrite(STDERR, '[ÉCHEC] Étape : ' . $stage . PHP_EOL . $hint . PHP_EOL);
    exit(1);
}
