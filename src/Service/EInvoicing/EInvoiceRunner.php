<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\{EInvoiceConnection, EInvoiceReceived, EInvoiceTransmission, Facture};
use App\Service\Billing\InvoiceSnapshot;
use Doctrine\ORM\EntityManagerInterface;

/** Durable, bounded outbox. A provider POST is attempted at most once per local invoice. */
final class EInvoiceRunner
{
    private const MAX_PAGES = 3;
    public function __construct(
        private EntityManagerInterface $em,
        private SuperPdpClient $api,
        private ConnectionManager $connections,
        private ConnectionLease $leases,
        private InvoicePayload $payload,
        private EInvoiceUsageBilling $usage,
        private EInvoiceReadiness $readiness,
    ) {}

    /** No network, credentials access, lease or database mutation when $execute is false. */
    public function run(EInvoiceConnection $connection, bool $execute = false, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('La taille du lot doit être comprise entre 1 et 100.');
        $result = ['mode' => $execute ? 'execute' : 'simulation', 'discovered' => 0, 'blocked' => 0,
            'submitted' => 0, 'rejected' => 0, 'reconciled' => 0, 'received' => 0, 'events' => 0,
            'uncertain' => 0, 'delivered' => [], 'errors' => [], 'skipped' => null];
        if ($connection->getEnvironment() !== 'production' || !$connection->isVerified() || !$connection->getId()) {
            $result['skipped'] = 'Connexion de production vérifiée requise.';
            return $result;
        }
        if (!$execute) {
            foreach ($this->candidates($connection, $limit) as $invoice) {
                if (!$this->isNewInvoice($invoice, $connection)) continue;
                ++$result['discovered'];
                if ($this->payload->issues($invoice) !== []) ++$result['blocked'];
            }
            return $result;
        }
        if ($this->em->getConnection()->isTransactionActive()) {
            $result['errors'][] = 'La synchronisation doit démarrer hors transaction afin de valider chaque tentative avant transmission.';
            return $result;
        }
        $lease = $this->leases->acquire($connection->getId());
        if ($lease === null) { $result['skipped'] = 'Synchronisation déjà en cours.'; return $result; }
        try {
            $this->em->refresh($connection);
            if (!$connection->isVerified() || !$connection->getCredentials()) throw new \DomainException('Connexion interrompue.');
            $session = $this->remote($connection, $lease, fn($token) => $this->api->session($token));
            if (($session['company_verification_status'] ?? '') !== 'verified') throw new \DomainException('Habilitation distante non vérifiée.');
            $company = $this->remote($connection, $lease, fn($token) => $this->api->company($token));
            $this->connections->checkCompany($connection, $company);

            // A stopped process may have sent its POST without receiving/persisting the reply.
            foreach ($this->em->getRepository(EInvoiceTransmission::class)->findBy(['connection' => $connection, 'status' => 'submitting'], ['id' => 'ASC'], $limit) as $transmission) {
                $transmission->uncertain(); ++$result['uncertain'];
            }
            $this->em->flush();
            $this->reconcile($connection, $lease, $limit, $result);
            $this->receive($connection, $lease, $limit, $result);
            $this->pollEvents($connection, $lease, $limit, $result);
            if ($connection->isAutomatic()) {
                $this->discover($connection, $limit, $result);
                $this->submit($connection, $lease, $limit, $result);
            }
            $connection->synchronized(); $this->em->flush();
        } catch (\Throwable $error) {
            // Never persist provider response bodies, tokens, personal details or SQL diagnostics.
            $safe = $error instanceof SuperPdpException ? $error->getMessage() : 'Synchronisation interrompue. Vérifiez la connexion et les dossiers en attente.';
            $result['errors'][] = $safe;
            if ($this->em->isOpen()) { $connection->failed($safe); $this->em->flush(); }
        } finally {
            $this->leases->release($connection->getId(), $lease);
        }
        return $result;
    }

    /** The root routing address wins; several subsidiary addresses never trigger a guess. */
    public static function recipientAddress(string $siren, array $entries): string
    {
        if (!preg_match('/^\d{9}$/D', $siren)) throw new \DomainException('SIREN du destinataire invalide.');
        $addresses = [];
        foreach ($entries as $entry) {
            if (($entry['is_active'] ?? false) !== true || ($entry['company']['number'] ?? null) !== $siren) continue;
            $address = $entry['identifier'] ?? '';
            if (is_string($address)) $addresses[$address] = true;
        }
        if (isset($addresses['0225:'.$siren])) return '0225:'.$siren;
        if (count($addresses) === 1 && preg_match('/^0225:'.preg_quote($siren, '/').'(?:_[A-Za-z0-9_.-]+)?$/D', (string) array_key_first($addresses))) return (string) array_key_first($addresses);
        throw new \DomainException($addresses === [] ? 'Aucune adresse de facturation active trouvée pour ce client dans l’annuaire.' : 'Plusieurs adresses de facturation existent pour ce client. Un choix explicite est nécessaire avant transmission.');
    }

    private function candidates(EInvoiceConnection $connection, int $limit): array
    {
        if (!$connection->isAutomatic() || !$connection->getEnabledAt()) return [];
        return $this->em->createQueryBuilder()->select('f')->from(Facture::class, 'f')
            ->where('f.entite = :entite AND f.dateCreation >= :enabled AND f.id > :cursor')
            ->setParameter('entite', $connection->getEntite())->setParameter('enabled', $connection->getEnabledAt())
            ->setParameter('cursor', $connection->getDiscoveryCursor() ?? 0)->orderBy('f.id', 'ASC')
            ->setMaxResults($limit)->getQuery()->getResult();
    }

    private function isNewInvoice(Facture $invoice, EInvoiceConnection $connection): bool
    {
        $issued = InvoiceSnapshot::read($invoice)['issuedAt'] ?? null;
        if (!$connection->getEnabledAt() || !$invoice->getDateCreation() || $invoice->getDateCreation()->getTimestamp() < $connection->getEnabledAt()->getTimestamp()) return false;
        if (!$issued) return true; // New invalid documents enter the blocked queue with their explicit issues.
        try { return (new \DateTimeImmutable($issued))->getTimestamp() >= $connection->getEnabledAt()->getTimestamp(); }
        catch (\Exception) { return false; }
    }

    private function discover(EInvoiceConnection $connection, int $limit, array &$result): void
    {
        foreach ($this->candidates($connection, $limit) as $invoice) {
            if ($this->isNewInvoice($invoice, $connection)
                && !$this->em->getRepository(EInvoiceTransmission::class)->findOneBy(['connection' => $connection, 'facture' => $invoice])) {
                $this->em->persist(new EInvoiceTransmission($connection, $invoice)); ++$result['discovered'];
            }
            $connection->discovered($invoice->getId());
        }
        $this->em->flush();
    }

    private function submit(EInvoiceConnection $connection, string $lease, int $limit, array &$result): void
    {
        $waiting = $this->em->createQueryBuilder()->select('t')->from(EInvoiceTransmission::class, 't')
            ->where('t.connection = :connection AND t.attemptedAt IS NULL')->setParameter('connection', $connection)
            ->orderBy('t.synchronizedAt', 'ASC')->addOrderBy('t.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
        foreach ($waiting as $transmission) {
            $this->leases->renew($connection->getId(), $lease);
            $this->em->refresh($connection);
            if (!$connection->isAutomatic() || !$connection->isVerified()) break;
            $invoice = $transmission->getFacture();
            $issues = $this->payload->issues($invoice);
            if (!$this->isNewInvoice($invoice, $connection)) $issues[] = 'Facture antérieure à l’activation : transmission automatique exclue.';
            if ($issues !== []) { $transmission->block($issues); ++$result['blocked']; $this->em->flush(); continue; }
            try {
                $this->readiness->assertCanTransmit($connection);
                $snapshot = InvoiceSnapshot::read($invoice);
                $directory = $this->api->directoryEntries($snapshot['customer']['siren']);
                $endpoint = self::recipientAddress($snapshot['customer']['siren'], $directory['data']);
                $this->leases->renew($connection->getId(), $lease);
                $xml = $this->api->convertInvoice($this->payload->build($invoice, $endpoint));
                $this->leases->renew($connection->getId(), $lease);
                $validation = $this->api->validateInvoice($xml);
                if (($validation['data'][0]['is_valid'] ?? null) !== true) throw new \DomainException('Le document structuré a échoué aux contrôles de la plateforme. Corrigez ses données avant transmission.');
                $transmission->prepared($xml);
                // Refresh credentials BEFORE reserving the POST, and persist any rotation immediately.
                $this->leases->renew($connection->getId(), $lease);
                $token = $this->connections->accessToken($connection);
                $this->usage->prepare($transmission);
            } catch (\DomainException $error) {
                $transmission->block([$error->getMessage()]); ++$result['blocked']; $this->em->flush(); continue;
            }
            $transmission->begin();
            $this->em->flush(); // durable at-most-once boundary, even if the process dies during POST
            try {
                $reply = $this->api->sendInvoice($token, $xml, $transmission->getExternalId());
                $this->checkRemoteInvoice($connection, $reply, 'out');
                if (isset($reply['external_id']) && $reply['external_id'] !== $transmission->getExternalId()) throw new \RuntimeException('Référence distante incohérente.');
                $transmission->accepted((string) $reply['id']);
                $this->usage->record($transmission);
                $this->em->flush();
                ++$result['submitted'];
                $result['delivered'][] = ['providerId' => (string) $reply['id'], 'direction' => 'out', 'transmissionId' => $transmission->getId()];
            } catch (\Throwable $error) {
                if ($error instanceof SuperPdpException && !$error->isUncertain()) { $transmission->rejected($error->getMessage()); ++$result['rejected']; }
                else { $transmission->uncertain(); ++$result['uncertain']; }
                if ($this->em->isOpen()) $this->em->flush();
                else throw $error;
                // Stop a shared transport/auth outage from making the remainder of this batch ambiguous.
                if (!$error instanceof SuperPdpException || $error->isUncertain() || in_array($error->httpStatus, [401, 403, 429], true)) break;
            }
        }
    }

    private function reconcile(EInvoiceConnection $connection, string $lease, int $limit, array &$result): void
    {
        // Scan monotonically by provider ID; foreign/out-of-band emissions are never billed by us.
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $cursor = $connection->getOutgoingCursor();
            $data = $this->remote($connection, $lease, fn($token) => $this->api->listInvoices($token, 'out', $cursor, $limit));
            foreach ($data['data'] as $row) {
                $this->checkRemoteInvoice($connection, $row, 'out');
                $id = (string) $row['id']; $this->advancing($cursor, $id);
                $external = $row['external_id'] ?? null;
                if (is_string($external) && $external !== '') {
                    $transmission = $this->em->getRepository(EInvoiceTransmission::class)->findOneBy(['connection' => $connection, 'externalId' => $external]);
                    if ($transmission && $transmission->getAttemptedAt() && in_array($transmission->getStatus(), ['uncertain', 'submitting'], true)) {
                        $transmission->accepted($id); $this->usage->record($transmission); ++$result['reconciled'];
                        $result['delivered'][] = ['providerId' => $id, 'direction' => 'out', 'transmissionId' => $transmission->getId()];
                    }
                }
                $connection->reconciledThrough($id); $cursor = $id;
            }
            $this->em->flush();
            if (!$data['has_after']) break;
            if ($data['data'] === []) throw new \RuntimeException('Pagination distante invalide.');
        }
    }

    private function receive(EInvoiceConnection $connection, string $lease, int $limit, array &$result): void
    {
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $cursor = $connection->getIncomingCursor();
            $data = $this->remote($connection, $lease, fn($token) => $this->api->listInvoices($token, 'in', $cursor, $limit));
            foreach ($data['data'] as $row) {
                $this->checkRemoteInvoice($connection, $row, 'in');
                $id = (string) $row['id']; $this->advancing($cursor, $id);
                if (!$this->em->getRepository(EInvoiceReceived::class)->findOneBy(['connection' => $connection, 'providerId' => $id])) {
                    $summary = array_intersect_key($row, array_flip(['id', 'company_id', 'created_at', 'direction', 'processing_rule']));
                    $this->em->persist(new EInvoiceReceived($connection, $id, $summary)); ++$result['received'];
                }
                $connection->synchronized($id); $cursor = $id;
            }
            $this->em->flush();
            if (!$data['has_after']) break;
            if ($data['data'] === []) throw new \RuntimeException('Pagination distante invalide.');
        }
    }

    private function pollEvents(EInvoiceConnection $connection, string $lease, int $limit, array &$result): void
    {
        $transmissions = $this->em->createQueryBuilder()->select('t')->from(EInvoiceTransmission::class, 't')
            ->where('t.connection = :connection AND t.providerId IS NOT NULL')->setParameter('connection', $connection)
            ->orderBy('t.synchronizedAt', 'ASC')->addOrderBy('t.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
        foreach ($transmissions as $transmission) {
            $cursor = null;
            foreach ($transmission->getEvents() as $event) if ($cursor === null || self::compareIds($event['id'], $cursor) > 0) $cursor = $event['id'];
            for ($page = 0; $page < self::MAX_PAGES; ++$page) {
                $data = $this->remote($connection, $lease, fn($token) => $this->api->events($token, $transmission->getProviderId(), $cursor, $limit));
                foreach ($data['data'] as $event) {
                    if (!isset($event['id'], $event['status_code']) || (string) ($event['invoice_id'] ?? '') !== $transmission->getProviderId()) throw new \RuntimeException('Événement distant incohérent.');
                    $id = (string) $event['id']; $this->advancing($cursor, $id); $cursor = $id;
                }
                $transmission->recordEvents($data['data']); $this->em->flush(); $result['events'] += count($data['data']);
                if (!$data['has_after']) break;
                if ($data['data'] === []) throw new \RuntimeException('Pagination distante invalide.');
            }
        }
    }

    private function remote(EInvoiceConnection $connection, string $lease, callable $request): array
    {
        $this->leases->renew($connection->getId(), $lease);
        return $request($this->connections->accessToken($connection));
    }
    private function checkRemoteInvoice(EInvoiceConnection $connection, array $row, string $direction): void
    {
        if (!preg_match('/^[1-9]\d{0,18}$/D', (string) ($row['id'] ?? '')) || self::compareIds((string) $row['id'], '9223372036854775807') > 0
            || (string) ($row['company_id'] ?? '') !== $connection->getCompanyId() || ($row['direction'] ?? null) !== $direction) throw new \RuntimeException('Facture distante incohérente.');
    }
    private function advancing(?string $cursor, string $id): void
    {
        if (!preg_match('/^[1-9]\d{0,18}$/D', $id) || self::compareIds($id, '9223372036854775807') > 0 || ($cursor !== null && self::compareIds($id, $cursor) <= 0)) throw new \RuntimeException('Curseur distant incohérent.');
    }
    private static function compareIds(string $a, string $b): int { return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
}
