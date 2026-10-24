<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\{EInvoiceConnection, EInvoiceTransmission, EInvoiceUsage, EInvoiceUsageBatch, Entite};
use App\Entity\Billing\EntiteSubscription;
use App\Repository\Billing\EntiteSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class EInvoiceUsageBilling
{
    public function __construct(private EntityManagerInterface $em, private EntiteSubscriptionRepository $subscriptions,
        private ConnectionLease $lease, private UsageInvoiceGateway $stripe) {}

    /** Local eligibility only; activation and sending must call this after explicit consent. */
    public function assertEligible(EInvoiceConnection $connection): ?EntiteSubscription
    {
        if ($connection->getEnvironment() === 'sandbox') return null;
        if ($connection->getBillingConsentAt() && EInvoiceTariff::isInternal($connection->getEntite())) return null;
        if (!$connection->getBillingConsentAt() || !in_array($connection->getUnitPriceCents(), [1, 2], true)) throw new \DomainException('Acceptez le tarif de transmission de votre organisme avant l’activation.');
        $sub = $this->subscriptions->findLatestForEntite($connection->getEntite());
        $now = new \DateTimeImmutable();
        if (!$sub || !in_array($sub->getStatus(), ['active', 'trialing'], true)
            || !preg_match('/^cus_[A-Za-z0-9]+$/D', $sub->getStripeCustomerId() ?? '')
            || !preg_match('/^sub_[A-Za-z0-9]+$/D', $sub->getStripeSubscriptionId() ?? '')
            || ($sub->getEndedAt() && $sub->getEndedAt() <= $now)
            || ($sub->getCurrentPeriodEnd() && $sub->getCurrentPeriodEnd() <= $now)
            || ($sub->getStatus() === 'trialing' && $sub->getTrialEndsAt() && $sub->getTrialEndsAt() <= $now)) {
            throw new \DomainException('Un abonnement Stripe actif avec un client de facturation est requis pour les émissions payantes.');
        }
        return $sub;
    }

    /** Caller holds the connection lease. Snapshot BEFORE the provider POST; never price an old attempt retrospectively. */
    public function prepare(EInvoiceTransmission $transmission): void
    {
        if ($transmission->getConnection()->getEnvironment() !== 'production') return;
        if ($transmission->getAttemptedAt()) throw new \LogicException('Aucune tarification rétroactive d’une tentative.');
        $connection = $transmission->getConnection(); $sub = $this->assertEligible($connection);
        $transmission->captureUsageTerms(['unitPriceCents' => $connection->getUnitPriceCents(), 'currency' => 'eur',
            // Internal allocation, never sent to customer-facing Stripe invoice items.
            'internalAllocation' => ['providerCostCents' => 1, 'platformShareCents' => $connection->getUnitPriceCents() - 1, 'version' => 1],
            'consentedAt' => $connection->getBillingConsentAt()->format(DATE_ATOM), 'consentedBy' => $connection->getBillingConsentBy(),
            'customer' => $sub?->getStripeCustomerId(), 'subscription' => $sub?->getStripeSubscriptionId()]);
    }

    /** Caller holds the connection lease and flushes accepted transmission + ledger together. */
    public function record(EInvoiceTransmission $transmission): void
    {
        if ($transmission->getConnection()->getEnvironment() !== 'production' || !$transmission->getUsageTerms()
            || ($transmission->getUsageTerms()['unitPriceCents'] ?? null) === 0
            || !$transmission->getProviderId() || !$transmission->getAcceptedAt() || !$transmission->getAttemptedAt()) return;
        if ($this->em->getRepository(EInvoiceUsage::class)->findOneBy(['transmission' => $transmission])) return;
        if ($this->em->getRepository(EInvoiceUsage::class)->findOneBy(['connection' => $transmission->getConnection(), 'providerId' => $transmission->getProviderId()])) return;
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof EInvoiceUsage && ($scheduled->getTransmission() === $transmission
                || ($scheduled->getConnection() === $transmission->getConnection() && $scheduled->getProviderId() === $transmission->getProviderId()))) return;
        }
        $this->em->persist(new EInvoiceUsage($transmission));
    }

    public function summary(Entite $entite, ?\DateTimeImmutable $now = null): array
    {
        $period = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m');
        $rows = $this->em->getRepository(EInvoiceUsage::class)->createQueryBuilder('u')->select('COUNT(u.id) AS quantity', 'COALESCE(SUM(u.unitPriceCents), 0) AS amount')
            ->join('u.connection', 'c')->where('c.entite = :entite')->andWhere('u.period = :period')
            ->setParameter('entite', $entite)->setParameter('period', $period)->getQuery()->getSingleResult();
        return ['unitPriceCents' => EInvoiceTariff::unitPriceCents($entite), 'period' => $period, 'quantity' => (int) $rows['quantity'], 'amountCents' => (int) $rows['amount']];
    }

    /** Default is a local, read-only preview, with no Stripe request. One completed calendar month only. */
    public function process(EInvoiceConnection $connection, string $period, bool $execute = false, bool $reconcile = false): array
    {
        $this->validatePeriod($period);
        if ($connection->getEnvironment() !== 'production') throw new \DomainException('Les émissions de test ne sont jamais facturées.');
        $rows = $this->em->getRepository(EInvoiceUsage::class)->findBy(['connection' => $connection, 'period' => $period], ['id' => 'ASC']);
        $preview = ['period' => $period, 'quantity' => count($rows), 'amountCents' => array_sum(array_map(static fn ($u) => $u->getUnitPriceCents(), $rows)),
            'recorded' => 0, 'pending' => 0, 'uncertain' => 0];
        if (!$execute) {
            foreach ($this->em->getRepository(EInvoiceUsageBatch::class)->findBy(['connection' => $connection, 'period' => $period]) as $batch) {
                ++$preview[$batch->getStatus() === 'recorded' ? 'recorded' : ($batch->getAttemptedAt() ? 'uncertain' : 'pending')];
            }
            return $preview;
        }
        if ($this->em->getConnection()->isTransactionActive()) throw new \LogicException('La facturation doit démarrer hors transaction afin de rendre ses tentatives durables avant tout appel Stripe.');
        if (!$connection->getId() || !($token = $this->lease->acquire($connection->getId()))) throw new \RuntimeException('Un traitement est déjà en cours pour cet organisme.');
        try {
            // Reload under the same lease as the sending worker, before any grouping or external side effect.
            $rows = $this->em->getRepository(EInvoiceUsage::class)->findBy(['connection' => $connection, 'period' => $period, 'batch' => null], ['id' => 'ASC']);
            $groups = [];
            foreach ($rows as $row) $groups[$row->getStripeCustomerId().'|'.$row->getStripeSubscriptionId().'|'.$row->getUnitPriceCents()][] = $row;
            foreach ($groups as $group) $this->em->persist(new EInvoiceUsageBatch($group));
            $this->em->flush(); // freezes consumption membership and the exact request before Stripe can be called
            $batches = $this->em->getRepository(EInvoiceUsageBatch::class)->findBy(['connection' => $connection, 'period' => $period], ['id' => 'ASC']);
            foreach ($batches as $batch) {
                $this->em->refresh($batch);
                $this->lease->renew($connection->getId(), $token);
                if ($batch->getStatus() === 'recorded') { ++$preview['recorded']; continue; }
                if ($batch->getAttemptedAt()) {
                    if ($reconcile && ($id = $this->stripe->find($batch))) { $batch->recorded($id); $this->em->flush(); ++$preview['recorded']; }
                    else ++$preview['uncertain'];
                    continue; // never POST again after an ambiguous result, including after Stripe's 24-hour key retention
                }
                if ($reconcile) { ++$preview['pending']; continue; }
                $this->stripe->validate($batch); // read-only live subscription/customer/currency/tax verification
                $this->lease->renew($connection->getId(), $token);
                $batch->begin(); $this->em->flush();
                try { $batch->recorded($this->stripe->create($batch)); ++$preview['recorded']; }
                catch (\Throwable) { $batch->uncertain(); ++$preview['uncertain']; }
                $this->em->flush();
            }
            return $preview;
        } finally { $this->lease->release($connection->getId(), $token); }
    }

    private function validatePeriod(string $period): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $period, new \DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m') !== $period || $period >= (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m')) {
            throw new \InvalidArgumentException('Sélectionnez un mois civil terminé au format AAAA-MM.');
        }
    }
}
