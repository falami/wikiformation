<?php

declare(strict_types=1);

// Runs against the currently deployed application, before git pull or migrations.
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$configFile = null;
$output = null;
$failed = false;
try {
    if ($argc !== 3 || !is_dir($argv[2])) throw new RuntimeException('Invalid backup arguments.');
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env', 'prod');
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $connection = $kernel->getContainer()->get('doctrine')->getConnection();
    $params = $connection->getParams();
    if (!in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true)) {
        throw new RuntimeException('Only MySQL/MariaDB backups are supported.');
    }
    if (empty($params['dbname'])) throw new RuntimeException('Database name is missing.');
    // Do not silently discard custom TLS/driver settings.
    if (!empty($params['driverOptions'])) throw new RuntimeException('Custom database driver options require matching dump configuration.');
    // A transactional dump is consistent only when every stored table is InnoDB.
    // Fail closed for MyISAM, Aria, unknown engines and sequences.
    $unsupportedTables = $connection->fetchFirstColumn(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE <> 'VIEW' AND (ENGINE IS NULL OR UPPER(ENGINE) <> 'INNODB')",
        [$params['dbname']]
    );
    if ($unsupportedTables !== []) {
        throw new RuntimeException('Transactional backup requires InnoDB tables only. Non-InnoDB objects found: ' . implode(', ', $unsupportedTables) . '. A locked backup with appropriate privileges is required.');
    }
    $quote = static fn(string $value): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $value) . '"';
    $settings = ['user' => $params['user'] ?? '', 'password' => $params['password'] ?? '', 'default-character-set' => 'utf8mb4'];
    if (!empty($params['unix_socket'])) $settings['socket'] = $params['unix_socket'];
    else {
        $settings['host'] = $params['host'] ?? 'localhost';
        if (!empty($params['port'])) $settings['port'] = (string) $params['port'];
    }
    $configFile = tempnam($argv[2], '.database-client-');
    if ($configFile === false) throw new RuntimeException('Cannot create database credentials file.');
    chmod($configFile, 0600);
    $config = "[client]\n";
    foreach ($settings as $key => $value) $config .= $key . '=' . $quote((string) $value) . "\n";
    if (file_put_contents($configFile, $config) === false) throw new RuntimeException('Cannot write database credentials.');
    $output = fopen($argv[2] . '/database.sql.partial', 'xb');
    if (!$output) throw new RuntimeException('Cannot create SQL backup.');
    // InnoDB snapshot avoids the global FLUSH/RELOAD privilege on shared hosting.
    // No schema changes may run concurrently; deployment migrations start afterwards.
    $process = new Process([$argv[1], '--defaults-extra-file=' . $configFile, '--single-transaction', '--skip-lock-tables', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--databases', $params['dbname']]);
    $process->setTimeout(null);
    $process->run(static function (string $type, string $buffer) use ($output): void {
        if ($type !== Process::OUT) return;
        $length = strlen($buffer);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($output, substr($buffer, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Cannot write SQL backup (disk full?).');
            $offset += $written;
        }
    });
    if (!$process->isSuccessful()) {
        // Preserve the actual diagnostic, but never expose the password or URL.
        $diagnostic = $process->getErrorOutput();
        foreach ([$params['url'] ?? '', $params['password'] ?? '', $quote((string) ($params['password'] ?? ''))] as $secret) {
            if ($secret !== '' && $secret !== '""') $diagnostic = str_replace($secret, '[REDACTED]', $diagnostic);
        }
        $diagnostic = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $diagnostic) ?? '');
        $diagnostic = substr($diagnostic, 0, 8000);
        if ($diagnostic === '') $diagnostic = 'The dump tool returned no error details.';
        file_put_contents($argv[2] . '/database-error.log', $diagnostic . "\n");
        chmod($argv[2] . '/database-error.log', 0600);
        throw new RuntimeException('Database export failed (exit ' . $process->getExitCode() . ").\n" . $diagnostic);
    }
    if (ftell($output) === 0) throw new RuntimeException('Database export is empty.');
    fclose($output);
    $output = null;
    if (!rename($argv[2] . '/database.sql.partial', $argv[2] . '/database.sql')) throw new RuntimeException('Cannot finalize SQL backup.');
    $kernel->shutdown();
} catch (Throwable $error) {
    // Never print connection parameters or subprocess output containing credentials.
    fwrite(STDERR, "Database backup failed. Deployment stopped. " . ($error instanceof RuntimeException ? $error->getMessage() : 'Check production configuration and database access.') . "\n");
    $failed = true;
} finally {
    if (is_resource($output)) fclose($output);
    if ($configFile && is_file($configFile)) unlink($configFile);
}

if ($failed) exit(1);
