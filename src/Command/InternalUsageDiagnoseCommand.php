<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Entite;
use App\Entity\Billing\{EntiteSubscription, InternalUsageSetup};
use Doctrine\ORM\EntityManagerInterface;
use Stripe\{ApiRequestor, HttpClient\CurlClient, StripeClient};
use Stripe\Exception\{ApiErrorException, InvalidRequestException};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:billing:internal-usage:diagnose', description: 'Diagnostic en lecture seule du rattachement Stripe pour la facturation interne.')]
final class InternalUsageDiagnoseCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, #[Autowire('%stripe.secret_key%')] private string $secret)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('entite', InputArgument::REQUIRED, 'Identifiant numérique de l’organisme.')
            ->addOption('local-only', null, InputOption::VALUE_NONE, 'Lire uniquement la base locale, sans contacter Stripe.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getArgument('entite'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $io->error('Indiquez un identifiant organisme entier strictement positif.');
            return self::INVALID;
        }
        $io->title('Diagnostic de facturation interne — lecture seule');
        $io->note('Aucune création, modification, suppression ou réinitialisation locale ou Stripe. Les consentements, coordonnées et URL de paiement ne sont pas affichés.');
        try {
            $entite = $this->em->find(Entite::class, $id);
            if (!$entite) {
                $io->error('Organisme introuvable.');
                return self::INVALID;
            }
            $setup = $this->em->getRepository(InternalUsageSetup::class)->findOneBy(['entite' => $entite]);
            $latest = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $entite], ['id' => 'DESC']);
            $rows = [['Organisme', (string) $id], ['Dernier abonnement local', (string) ($latest?->getId() ?? 'absent')],
                ['Abonnement Stripe rattaché', $this->identifier($latest?->getStripeSubscriptionId(), 'sub_')],
                ['Client Stripe de l’abonnement', $this->identifier($latest?->getStripeCustomerId(), 'cus_')]];
            if (!$setup) {
                $io->table(['État local', 'Valeur'], $rows);
                $io->warning('Aucune réservation interne locale. Aucun objet Stripe ne sera déduit ou créé.');
                $this->nextSteps($io);
                return self::FAILURE;
            }
            $expires = $setup->request['checkout']['expires_at'] ?? null;
            $rows = array_merge($rows, [
                ['Réservation', preg_match('/^[a-f0-9]{32}$/D', $setup->id) ? $setup->id : 'identifiant invalide'],
                ['Création', $setup->createdAt->format(DATE_ATOM)],
                ['Première tentative', $setup->attemptedAt?->format(DATE_ATOM) ?? 'aucune enregistrée'],
                ['Ancien expires_at figé', is_int($expires) ? date(DATE_ATOM, $expires).($expires <= time() ? ' (expiré)' : ' (à venir)') : 'absent'],
                ['Abonnement de la réservation', (string) $setup->subscription->getId()],
                ['Lien avec le dernier abonnement', $setup->subscription->getId() === $latest?->getId() ? 'identique' : 'DIFFÉRENT — rapprochement requis'],
                ['Client Stripe de la réservation', $this->identifier($setup->customerId, 'cus_')],
                ['Session Stripe enregistrée', $this->identifier($setup->sessionId, 'cs_')],
            ]);
            $io->table(['État local', 'Valeur'], $rows);
            if (is_int($expires) && $expires <= time()) {
                $io->warning('La date Checkout figée a expiré. Cela explique le blocage local historique, mais ne prouve pas l’absence de session ou d’abonnement sur Stripe.');
            }
            if ($input->getOption('local-only')) {
                $io->note('Mode local : aucun appel Stripe. L’état distant reste à vérifier avec la même commande sans --local-only.');
                return self::SUCCESS;
            }
            if (!$setup->customerId || !preg_match('/^cus_[A-Za-z0-9]+$/D', $setup->customerId)) {
                $io->warning('Client Stripe absent ou invalide dans la réservation : rapprochement client requis. Une réponse de création a pu être perdue ; aucune absence de Checkout ne peut être déduite.');
                $this->nextSteps($io);
                return self::FAILURE;
            }
            if (!preg_match('/^(?:sk|rk)_live_[A-Za-z0-9]+$/D', $this->secret)) {
                $io->error('La clé Stripe de production doit être configurée pour lire l’état distant.');
                return self::FAILURE;
            }
            $client = new StripeClient(['api_key' => $this->secret, 'max_network_retries' => 0]);
            $customer = $this->request(fn() => $client->customers->retrieve($setup->customerId, [])->toArray());
            if (($customer['object'] ?? null) !== 'customer' || ($customer['id'] ?? null) !== $setup->customerId
                || ($customer['deleted'] ?? false) || ($customer['livemode'] ?? false) !== true
                || ($customer['metadata']['entite_id'] ?? null) !== (string) $id) {
                $io->error('Le client Stripe ne correspond pas à un client de production actif de cet organisme. Rapprochement client requis ; lecture des sessions interrompue.');
                return self::FAILURE;
            }
            $io->success('Client Stripe de production retrouvé ; métadonnée organisme concordante.');
            $since = max(0, $setup->createdAt->getTimestamp() - 300);
            $sessions = [];
            $scanned = 0;
            $knownSessionFound = $setup->sessionId === null;
            foreach ($this->pages(fn(array $params) => $client->checkout->sessions->all($params)->toArray(), [
                'customer' => $setup->customerId, 'created' => ['gte' => $since], 'limit' => 100,
            ]) as $session) {
                ++$scanned;
                if (($session['metadata']['internal_usage_setup'] ?? null) !== $setup->id) continue;
                $knownSessionFound = $knownSessionFound || ($session['id'] ?? null) === $setup->sessionId;
                $sessions[] = [$this->identifier($session['id'] ?? null, 'cs_'), $this->token($session['status'] ?? null),
                    $this->identifier($session['subscription'] ?? null, 'sub_'),
                    $this->timestamp($session['expires_at'] ?? null),
                    $this->matches($session, $setup) ? 'oui' : 'NON — à vérifier'];
            }
            $io->section('Sessions Checkout portant la réservation locale');
            $io->writeln(sprintf('%d session(s) examinée(s), pagination terminée, depuis %s.', $scanned, date(DATE_ATOM, $since)));
            $io->table(['Session', 'Statut', 'Abonnement', 'Expiration', 'Identité concordante'], $sessions);
            if (!$sessions) $io->warning('Aucune session trouvée pour cette réservation dans cette fenêtre et sur ce client. Ce constat seul n’autorise pas une réinitialisation.');
            if (!$knownSessionFound) $io->warning('La session enregistrée localement est absente des résultats concordants. Vérifier son identifiant directement dans Stripe.');

            $subscriptions = [];
            foreach ($this->pages(fn(array $params) => $client->subscriptions->all($params)->toArray(), [
                'customer' => $setup->customerId, 'status' => 'all', 'limit' => 100,
            ]) as $subscription) {
                $subscriptions[] = [$this->identifier($subscription['id'] ?? null, 'sub_'), $this->token($subscription['status'] ?? null),
                    ($subscription['metadata']['internal_usage_setup'] ?? null) === $setup->id ? 'oui' : 'non',
                    $this->matches($subscription, $setup) ? 'oui' : 'non',
                    ($subscription['id'] ?? null) === $setup->subscription->getStripeSubscriptionId() ? 'oui' : 'non'];
            }
            $io->section('Tous les abonnements de ce client Stripe');
            $io->table(['Abonnement', 'Statut', 'Même réservation', 'Identité concordante', 'Rattaché localement'], $subscriptions);
            if (!$subscriptions) $io->writeln('Aucun abonnement retourné ; pagination terminée.');
            $this->nextSteps($io);
            return self::SUCCESS;
        } catch (ApiErrorException $e) {
            $io->error(sprintf('Lecture Stripe interrompue : %s ; code=%s ; param=%s ; request-id=%s.',
                (new \ReflectionClass($e))->getShortName(), $this->token($e->getStripeCode()),
                $e instanceof InvalidRequestException ? $this->token($e->getStripeParam()) : 'absent',
                is_string($e->getRequestId()) && preg_match('/^req_[A-Za-z0-9]+$/D', $e->getRequestId()) ? $e->getRequestId() : 'absent'));
            $this->nextSteps($io);
            return self::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Diagnostic incomplet ('.(new \ReflectionClass($e))->getShortName().'). Aucun état distant sûr ne peut être conclu ; vérifier la connexion et les journaux serveur.');
            $this->nextSteps($io);
            return self::FAILURE;
        }
    }

    /** Every page must be read before reporting absence. Reject malformed or looping cursors. */
    private function pages(callable $fetch, array $params): \Generator
    {
        $seen = [];
        do {
            $page = $this->request(fn() => $fetch($params));
            if (($page['object'] ?? null) !== 'list' || !is_array($page['data'] ?? null) || !is_bool($page['has_more'] ?? null)) {
                throw new \UnexpectedValueException('Invalid Stripe pagination.');
            }
            foreach ($page['data'] as $row) {
                if (!is_array($row)) throw new \UnexpectedValueException('Invalid Stripe row.');
                yield $row;
            }
            if (!$page['has_more']) break;
            $last = end($page['data']);
            $cursor = is_array($last) ? ($last['id'] ?? null) : null;
            if (!is_string($cursor) || !preg_match('/^(?:cs|sub)_[A-Za-z0-9_]+$/D', $cursor) || isset($seen[$cursor])) {
                throw new \UnexpectedValueException('Invalid Stripe cursor.');
            }
            $seen[$cursor] = true;
            $params['starting_after'] = $cursor;
        } while (true);
    }

    private function request(callable $operation): array
    {
        $http = ApiRequestor::httpClient();
        $timeouts = $http instanceof CurlClient ? [$http->getTimeout(), $http->getConnectTimeout()] : null;
        try {
            if ($timeouts) {
                $http->setTimeout($timeouts[0] > 0 ? min(10, $timeouts[0]) : 10);
                $http->setConnectTimeout($timeouts[1] > 0 ? min(3, $timeouts[1]) : 3);
            }
            return $operation();
        } finally {
            if ($timeouts) { $http->setTimeout($timeouts[0]); $http->setConnectTimeout($timeouts[1]); }
        }
    }

    private function matches(array $remote, InternalUsageSetup $setup): bool
    {
        return ($remote['customer'] ?? null) === $setup->customerId && ($remote['livemode'] ?? false) === true
            && ($remote['metadata']['entite_id'] ?? null) === (string) $setup->entite->getId()
            && ($remote['metadata']['local_subscription_id'] ?? null) === (string) $setup->subscription->getId();
    }

    private function identifier(mixed $value, string $prefix): string
    {
        return $value === null ? 'absent' : (is_string($value) && preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9_]+$/D', $value) ? $value : 'invalide');
    }

    private function token(mixed $value): string
    {
        return $value === null ? 'absent' : (is_string($value)
            && !preg_match('/^(?:sk|rk|pk)_(?:live|test)_/', $value)
            && preg_match('/^[a-z][a-z0-9_\[\].]{0,99}$/D', $value) ? $value : 'masqué');
    }

    private function timestamp(mixed $value): string
    {
        return is_int($value) ? date(DATE_ATOM, $value) : 'absente';
    }

    private function nextSteps(SymfonyStyle $io): void
    {
        $io->note([
            'Session complète ou abonnement actif : contrôler les événements et livraisons webhook Stripe avant tout nouveau Checkout.',
            'Session ouverte : vérifier la session existante. Session expirée ou réservation sans résultat : rapprocher les identifiants et journaux de requêtes avant toute reprise.',
            'Ces lectures sont un instantané, sans verrou distant. Elles ne vérifient ni les factures en attente, ni les droits de création de la clé, ni la livraison webhook ; aucune réinitialisation automatique n’est autorisée par ce rapport.',
        ]);
    }
}
