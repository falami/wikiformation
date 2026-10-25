<?php
// Temporary audit harness. Every write is restricted to a newly created schema.
declare(strict_types=1);
use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{Plan, EntiteSubscription};
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\QuotaUsage;
use App\Kernel;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if (in_array('--worker', $argv, true)) {
    // Workers receive credentials only in their environment, never in argv/output.
    $options = getopt('', ['worker', 'entity:', 'owner:']);
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    (new Dotenv())->bootEnv($root.'/.env');
    $url = $_SERVER['DATABASE_URL'] ?? '';
    $database = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
    if (!preg_match('/^billing_quota_audit_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Unsafe worker schema.');
    $kernel = new Kernel('test', true); $kernel->boot();
    $em = $kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
    if ($em->getConnection()->getDatabase() !== $database.'_test') throw new RuntimeException('Unexpected worker schema.');
    $em->getConnection()->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $entity = $em->find(Entite::class, (int)$options['entity']);
    $owner = $em->find(Utilisateur::class, (int)$options['owner']);
    $user = (new Utilisateur())->setEmail('overlap-loser@mysql-audit.test')->setPassword('unused')->setNom('Concurrent')->setPrenom('Test');
    $membership = (new UtilisateurEntite())->setEntite($entity)->setCreateur($owner)->setUtilisateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
    $em->persist($user); $em->persist($membership);
    echo 'READY'.PHP_EOL; flush();
    try { $em->flush(); } catch (BillingQuotaExceededException) {
        echo 'REFUSED'.PHP_EOL;
        $kernel->shutdown(); exit(0);
    }
    throw new RuntimeException('A simultaneous writer exceeded the seat quota.');
}
if (!in_array('--run', $argv, true)) {
    echo 'Usage: php scripts/check-billing-quota-concurrency.php --run [--local-docker]'.PHP_EOL;
    echo 'Creates and removes a disposable MySQL schema; requires local Docker administrator configuration.'.PHP_EOL;
    exit(0);
}
(new Dotenv())->bootEnv($root.'/.env');
if (in_array('--local-docker', $argv, true)) $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = str_replace('database:3306', '127.0.0.1:3307', $_SERVER['DATABASE_URL']);
$sourceKernel = new Kernel('dev', true);
$sourceKernel->boot();
$params = $sourceKernel->getContainer()->get('doctrine')->getConnection()->getParams();
$sourceKernel->shutdown();
if (!in_array($params['host'] ?? '', ['localhost', '127.0.0.1'], true) || ($params['driver'] ?? '') !== 'pdo_mysql') throw new RuntimeException('Only a local MySQL server may be used.');
unset($params['dbname'], $params['url']);
$compose = Symfony\Component\Yaml\Yaml::parseFile($root.'/compose.yaml');
$rootPassword = $compose['services']['database']['environment']['MYSQL_ROOT_PASSWORD'] ?? null;
if (!is_string($rootPassword)) throw new RuntimeException('Local administrator configuration unavailable.');
if (preg_match('/^\$\{([A-Z_]+)(?::-([^}]*))?\}$/', $rootPassword, $matches)) {
    $rootPassword = $_SERVER[$matches[1]] ?? $_ENV[$matches[1]] ?? $matches[2] ?? null;
}
if (!is_string($rootPassword)) throw new RuntimeException('Local administrator configuration unresolved.');
$params['user'] = 'root'; $params['password'] = $rootPassword;
$server = DriverManager::getConnection($params);
$name = 'billing_quota_audit_'.bin2hex(random_bytes(6));
$actual = $name.'_test';
if (!preg_match('/^billing_quota_audit_[a-f0-9]{12}_test$/', $actual)) throw new RuntimeException('Unsafe schema name.');
$kernels = []; $workers = []; $created = false;
try {
    $server->executeStatement('CREATE DATABASE `'.$actual.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $url = 'mysql://'.rawurlencode($params['user']).':'.rawurlencode($params['password']).'@'.$params['host'].':'.($params['port'] ?? 3306).'/'.$name.'?charset=utf8mb4';
    if (isset($params['serverVersion'])) $url .= '&serverVersion='.rawurlencode($params['serverVersion']);
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    $_ENV['TEST_TOKEN'] = $_SERVER['TEST_TOKEN'] = '';
    $open = function () use (&$kernels, $actual) {
        $kernel = new Kernel('test', true); $kernel->boot(); $kernels[] = $kernel;
        $container = $kernel->getContainer()->get('test.service_container');
        $em = $container->get('doctrine')->getManager();
        if ($em->getConnection()->getDatabase() !== $actual) throw new RuntimeException('Unexpected schema.');
        $em->getConnection()->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return [$em, $container];
    };
    [$setup] = $open();
    (new SchemaTool($setup))->createSchema($setup->getMetadataFactory()->getAllMetadata());
    $user = function ($em, string $name) { $u = (new Utilisateur())->setEmail($name.'@mysql-audit.test')->setPassword('unused')->setNom($name)->setPrenom('Test'); $em->persist($u); return $u; };
    $member = function ($em, $entity, $creator, $u, string $role) { $m=(new UtilisateurEntite())->setEntite($entity)->setCreateur($creator)->setUtilisateur($u)->setRoles([$role]); $em->persist($m); return $m; };
    $seed = function (string $suffix) use ($setup, $user) {
        $owner = $user($setup, 'owner-'.$suffix);
        $entity = (new Entite())->setNom('audit-'.$suffix)->setCreateur($owner)->setPublic(false);
        $plan = (new Plan())->setCode('AUDIT-'.$suffix)->setName('Audit')->setMaxUtilisateurs(1)->setMaxApprenantsAn(1);
        $setup->persist($entity); $setup->persist($plan);
        $setup->persist((new EntiteSubscription())->setEntite($entity)->setPlan($plan)->setStatus(EntiteSubscription::STATUS_ACTIVE));
        $setup->flush(); return [$entity->getId(), $owner->getId()];
    };
    foreach (['seats'=>UtilisateurEntite::TENANT_ADMIN, 'learners'=>UtilisateurEntite::TENANT_STAGIAIRE] as $case=>$role) {
        [$entityId, $ownerId] = $seed($case);
        [$a, $ca] = $open(); [$b] = $open();
        $ea=$a->find(Entite::class,$entityId); $oa=$a->find(Utilisateur::class,$ownerId);
        $eb=$b->find(Entite::class,$entityId); $ob=$b->find(Utilisateur::class,$ownerId);
        $a->getConnection()->beginTransaction();
        // Establish a stale consistent-read snapshot before another writer commits.
        $a->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite WHERE entite_id = ?', [$entityId]);
        $member($b,$eb,$ob,$user($b,'winner-'.$case),$role); $b->flush();
        $member($a,$ea,$oa,$user($a,'stale-'.$case),$role);
        $rejected=false;
        try { $a->flush(); } catch (BillingQuotaExceededException $error) { $rejected=true; }
        if (!$rejected) throw new RuntimeException($case.': stale transaction exceeded quota');
        $a->getConnection()->rollBack(); $a->clear();
        $actualCount=(int)$b->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite WHERE entite_id = ?',[$entityId]);
        if ($actualCount !== 1) throw new RuntimeException($case.': expected one committed member');
        echo 'PASS '.$case.': a stale REPEATABLE READ transaction cannot consume the last seat twice'.PHP_EOL;
    }

    // Actual overlapping transactions: writer A retains its lock after flush,
    // writer B blocks on it and must reject after A commits the final place.
    [$entityId, $ownerId] = $seed('overlap');
    [$a] = $open();
    $ea=$a->find(Entite::class,$entityId); $oa=$a->find(Utilisateur::class,$ownerId);
    $a->getConnection()->beginTransaction();
    $member($a,$ea,$oa,$user($a,'overlap-winner'),UtilisateurEntite::TENANT_ADMIN);
    $a->flush();
    $worker = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, '--worker', '--entity='.$entityId, '--owner='.$ownerId], $root, ['DATABASE_URL'=>$url, 'APP_ENV'=>'test', 'APP_DEBUG'=>'1', 'TEST_TOKEN'=>''], null, 15);
    $workers[] = $worker;
    $worker->start();
    if (!$worker->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'READY'))) {
        throw new RuntimeException('The overlapping worker did not start. '.$worker->getErrorOutput());
    }
    usleep(200000);
    if (!$worker->isRunning()) throw new RuntimeException('The second writer did not wait for the tenant lock.');
    $a->getConnection()->commit();
    $worker->wait();
    if (!$worker->isSuccessful() || !str_contains($worker->getOutput(), 'REFUSED')) throw new RuntimeException('The overlapping writer was not rejected. '.$worker->getErrorOutput());
    $actualCount=(int)$a->getConnection()->fetchOne('SELECT COUNT(*) FROM utilisateur_entite WHERE entite_id = ?',[$entityId]);
    if ($actualCount !== 1) throw new RuntimeException('Overlapping writers persisted too many members.');
    echo 'PASS overlap: a simultaneous writer waits for the lock and is refused after the final seat commits'.PHP_EOL;
} finally {
    foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop(1);
    foreach (array_reverse($kernels) as $kernel) {
        $connection = $kernel->getContainer()->get('doctrine')->getConnection();
        while ($connection->isTransactionActive()) $connection->rollBack();
        $connection->close(); $kernel->shutdown();
    }
    if ($created) $server->executeStatement('DROP DATABASE IF EXISTS `'.$actual.'`');
    echo 'Disposable MySQL schema removed.'.PHP_EOL;
}
