<?php

declare (strict_types=1);
namespace App\Service\Partner;

use App\Entity\Partner\{Partner, Referral, Commission};
use App\Entity\Billing\{EntiteSubscription, Plan};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
final class Commissions
{
    public function __construct(private EntityManagerInterface $em, private Referrals $referrals, private PartnerStripeGateway $stripe, private LockFactory $locks)
    {
    }
    public function record(array $payload): ?Commission
    {
        $id = (string) ($payload['id'] ?? '');
        if (!$id || !str_starts_with($id, 'in_')) {
            return null;
        }
        $lock = $this->locks->createLock('affiliate-invoice-' . $id, 300);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Commission en cours de traitement.');
        }
        try {
            $existing = $this->em->getRepository(Commission::class)->findOneBy(['invoiceId' => $id]);
            if ($existing) {
                return $existing;
            }
            $customer = $payload['customer'] ?? '';
            if (!is_string($customer) || !$customer) {
                return null;
            }
            $subId = $payload['subscription'] ?? $payload['parent']['subscription_details']['subscription'] ?? null;
            if (!is_string($subId) || !$subId) {
                return null;
            }
            // Usage-only and ordinary invoices are excluded.
            $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['stripeSubscriptionId' => $subId]);
            $sub ??= $this->em->getRepository(EntiteSubscription::class)->findOneBy(['stripeCustomerId' => $customer], ['id' => 'DESC']);
            if (!$sub || $sub->getStripeCustomerId() !== $customer) {
                return null;
            }
            $ref = $this->em->getRepository(Referral::class)->findOneBy(['entite' => $sub->getEntite()]);
            if (!$ref) {
                return null;
            }
            $invoice = $this->stripe->invoice($id);
            if (($invoice['status'] ?? '') !== 'paid') {
                return null;
            }
            $paidAt = (new \DateTimeImmutable())->setTimestamp((int) ($invoice['status_transitions']['paid_at'] ?? 0));
            if ($paidAt < $ref->createdAt) {
                return null;
            }
            $terms = $this->referrals->terms($ref->partner, $paidAt);
            if (!$terms) {
                throw new \DomainException('Conditions de commission absentes.');
            }
            $c = new Commission();
            $c->referral = $ref;
            $c->terms = $terms;
            $c->invoiceId = $id;
            $c->subscriptionId = $subId;
            $c->planName = $sub->getPlan()?->getName() ?? 'Abonnement';
            $c->currency = (string) ($invoice['currency'] ?? 'eur');
            $c->paidAt = $paidAt;
            $c->availableAt = $paidAt->modify('+30 days');
            $prices = [];
            foreach ($this->em->getRepository(Plan::class)->findAll() as $plan) {
                foreach ([$plan->getStripePriceMonthlyId(), $plan->getStripePriceYearlyId()] as $price) {
                    if ($price) {
                        $prices[] = $price;
                    }
                }
            }
            $c->evidence = ['planPrices' => $prices, 'invoiceTotal' => (int) ($invoice['total'] ?? 0), 'paidCents' => (int) ($invoice['amount_paid'] ?? 0)];
            try {
                $c->baseCents = CommissionMath::base($invoice, $prices);
                if ($c->baseCents > 0 && count($invoice['_charges']) !== 1) {
                    throw new \DomainException('Paiement sans débit unique identifié : contrôle nécessaire.');
                }
                $c->chargeId = $invoice['_charges'][0] ?? null;
                $c->grossCents = CommissionMath::percentage($c->baseCents, $terms->rateBps);
                $net = CommissionMath::net($c->grossCents, $terms->feeFixedCents, $terms->feeBps);
                $c->feeCents = $net['fee'];
                $c->netCents = $net['net'];
                if (!$c->netCents) {
                    $c->status = 'zero';
                }
            } catch (\DomainException $e) {
                $c->status = 'review';
                $c->issue = $e->getMessage();
            }
            $this->em->persist($c);
            $this->em->flush();
            return $c;
        } finally {
            $lock->release();
        }
    }
    /** The worker also rereads Stripe before release: delayed or missing refund webhooks cannot release stale money. */
    public function process(Commission $c, bool $execute = false): string
    {
        if (!$execute) {
            return $c->status;
        }
        $lock = $this->locks->createLock('affiliate-partner-' . $c->referral->partner->id, 300);
        if (!$lock->acquire()) {
            return 'locked';
        }
        try {
            $this->em->refresh($c);
            if (in_array($c->status, ['review', 'zero', 'cancelled', 'payout_failed'], true)) {
                return $c->status;
            }
            $partner = $c->referral->partner;
            if (!$partner->stripeAccountId || !$c->chargeId) {
                return $c->status;
            }
            $charge = $this->stripe->charge($c->chargeId);
            if (($charge['currency'] ?? '') !== 'eur' || !($charge['paid'] ?? false) || (int) ($charge['amount'] ?? 0) !== $c->evidence['paidCents']) {
                $c->status = 'review';
                $c->issue = 'Débit Stripe incohérent avec le paiement enregistré.';
                return $this->save($c);
            }
            $invoice = $this->stripe->invoice($c->invoiceId);
            if (!empty($invoice['_hasCreditNotes'])) {
                $c->status = 'review';
                $c->issue = 'Avoir Stripe à rapprocher avant tout nouveau versement.';
                return $this->save($c);
            }
            $refund = (int) ($charge['amount_refunded'] ?? 0);
            if (!empty($charge['disputed'])) {
                $disputeId = $charge['dispute'] ?? null;
                if (!$disputeId) {
                    $c->status = 'held';
                    $c->issue = 'Paiement contesté : commission gelée.';
                    return $this->save($c);
                }
                $dispute = $this->stripe->dispute(is_array($disputeId) ? $disputeId['id'] : $disputeId);
                if (($dispute['status'] ?? '') === 'lost') {
                    $refund = (int) $charge['amount'];
                } elseif (($dispute['status'] ?? '') !== 'won') {
                    $c->status = 'held';
                    $c->issue = 'Litige en cours : commission gelée.';
                    return $this->save($c);
                }
            }
            $c->refundedCents = min((int) $charge['amount'], $refund);
            // Unallocated charge refunds are apportioned to the subscription and add-ons pro rata.
            $earned = (int) round($c->grossCents * (1 - $c->refundedCents / max(1, (int) $charge['amount'])));
            $newNet = CommissionMath::net($earned, $c->terms->feeFixedCents, $c->terms->feeBps);
            if ($c->transferId) {
                $reverse = max(0, $c->netCents - $newNet['net']);
                if ($reverse > $c->reversedCents) {
                    try {
                        $this->stripe->reverse($c->transferId, $reverse - $c->reversedCents, 'affiliate-reverse-' . $c->id . '-' . $reverse);
                    } catch (\Throwable $e) {
                        $c->status = 'held';
                        $c->issue = 'Reprise de commission à rapprocher : les versements suivants sont suspendus.';
                        $this->em->flush();
                        throw $e;
                    }
                    $c->reversedCents = $reverse;
                    $this->em->flush();
                }
            } elseif ($c->transferAttemptAt && $newNet['net'] !== $c->netCents) {
                // An external request may have succeeded. Never change its frozen amount before reconciliation.
                $found = $this->stripe->findTransfer('affiliate-transfer-' . $c->id);
                if ($found) {
                    $this->acceptTransfer($c, $found);
                    return $this->save($c);
                }
                $c->status = 'uncertain';
                $c->issue = 'Remboursement pendant un transfert : rapprocher dans Stripe.';
                return $this->save($c);
            } else {
                $c->netCents = $newNet['net'];
                $c->feeCents = $newNet['fee'];
                if (!$c->netCents) {
                    $c->status = 'cancelled';
                    return $this->save($c);
                }
            }
            // Existing payouts must be reconciled even when a partner's future payouts are paused.
            if ($c->payoutId) {
                return $this->syncPayout($c, $this->stripe->getPayout($partner->stripeAccountId, $c->payoutId));
            }
            if ($c->reversedCents > 0 && $c->payoutAttemptAt) {
                $c->status = 'uncertain';
                $c->issue = 'Remboursement pendant un virement : rapprocher dans Stripe.';
                return $this->save($c);
            }
            if ($c->availableAt > new \DateTimeImmutable() || !$partner->automaticPayouts || !$partner->active) {
                return $this->save($c);
            }
            // A problematic older payment blocks further releases for the same partner until reconciled.
            $blocked = $this->em->getRepository(Commission::class)->createQueryBuilder('c')->join('c.referral', 'r')->where('r.partner = :p AND c.id != :id AND c.status IN (:statuses)')->setParameter('p', $partner)->setParameter('id', $c->id)->setParameter('statuses', ['review', 'uncertain', 'payout_failed', 'held'])->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($blocked) {
                return $this->save($c);
            }
            $account = $this->stripe->account($partner->stripeAccountId);
            $partner->payoutsEnabled = (bool) ($account['payouts_enabled'] ?? false) && ($account['capabilities']['transfers'] ?? '') === 'active';
            if (!$partner->payoutsEnabled || ($account['settings']['payouts']['schedule']['interval'] ?? '') !== 'manual') {
                $c->issue = 'Coordonnées bancaires ou configuration Stripe à compléter.';
                return $this->save($c);
            }
            $c->issue = null;
            if (!$c->transferId) {
                $key = 'affiliate-transfer-' . $c->id;
                $found = $this->stripe->findTransfer($key);
                if (!$found) {
                    if ($this->expired($c->transferAttemptAt)) {
                        $c->status = 'uncertain';
                        $c->issue = 'Transfert incertain : rapprochement Stripe requis.';
                        return $this->save($c);
                    }
                    $c->transferAttemptAt ??= new \DateTimeImmutable();
                    $c->status = 'transferring';
                    $this->em->flush();
                    $found = $this->stripe->transfer($partner->stripeAccountId, $c->netCents, $c->chargeId, $key);
                }
                $this->acceptTransfer($c, $found);
                $this->em->flush();
            }
            $amount = $c->netCents - $c->reversedCents;
            if (!$amount) {
                $c->status = 'cancelled';
                return $this->save($c);
            }
            $key = 'affiliate-payout-' . $c->id;
            $payout = $this->stripe->findPayout($partner->stripeAccountId, $key, $c->transferAttemptAt?->getTimestamp() ?? $c->paidAt->getTimestamp());
            if (!$payout) {
                if ($this->expired($c->payoutAttemptAt)) {
                    $c->status = 'uncertain';
                    $c->issue = 'Virement incertain : rapprochement Stripe requis.';
                    return $this->save($c);
                }
                $c->payoutAttemptAt ??= new \DateTimeImmutable();
                $c->status = 'paying';
                $this->em->flush();
                $payout = $this->stripe->payout($partner->stripeAccountId, $amount, $key);
            }
            if ((int) $payout['amount'] !== $amount || $payout['currency'] !== 'eur') {
                throw new \DomainException('Virement Stripe incohérent.');
            }
            $c->payoutId = $payout['id'];
            return $this->syncPayout($c, $payout);
        } finally {
            $lock->release();
        }
    }
    private function acceptTransfer(Commission $c, array $row): void
    {
        if ((int) $row['amount'] !== $c->netCents || $row['currency'] !== 'eur' || $row['destination'] !== $c->referral->partner->stripeAccountId) {
            throw new \DomainException('Transfert Stripe incohérent.');
        }
        $c->transferId = $row['id'];
        $c->status = 'transferred';
    }
    private function syncPayout(Commission $c, array $payout): string
    {
        $c->status = match ($payout['status']) {
            'paid' => 'paid',
            'failed', 'canceled' => 'payout_failed',
            default => 'paying',
        };
        if ($c->status === 'payout_failed') {
            $c->issue = 'Virement échoué ou annulé : vérifier les coordonnées dans Stripe avant reprise.';
        }
        $c->issue = $c->status === 'payout_failed' ? $c->issue : null;
        $c->arrivalAt = empty($payout['arrival_date']) ? null : (new \DateTimeImmutable())->setTimestamp($payout['arrival_date']);
        return $this->save($c);
    }
    private function expired(?\DateTimeImmutable $attempt): bool
    {
        return $attempt && $attempt < new \DateTimeImmutable('-23 hours');
    }
    private function save(Commission $c): string
    {
        $this->em->flush();
        return $c->status;
    }
}
