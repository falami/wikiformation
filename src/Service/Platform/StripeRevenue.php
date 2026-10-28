<?php
declare(strict_types=1);
namespace App\Service\Platform;

use App\Entity\Platform\Revenue;
use App\Entity\Billing\EntiteSubscription;
use App\Service\Stripe\StripeClientFactory;
use App\Service\Partner\PartnerStripeGateway;
use Doctrine\ORM\EntityManagerInterface;

/** Reads Stripe only. Invoice ids make replay/backfill idempotent. */
final class StripeRevenue
{
    public function __construct(private EntityManagerInterface $em, private StripeClientFactory $factory,
        private PartnerStripeGateway $gateway, private \Symfony\Component\Lock\LockFactory $locks) {}

    public function record(array $invoice, ?array $charges = null): ?Revenue
    {
        if (($invoice['status'] ?? '') !== 'paid' || !preg_match('/^in_[A-Za-z0-9_]+$/D', $invoice['id'] ?? '')) return null;
        $subscription = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (!is_string($subscription)) return null;
        $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['stripeSubscriptionId'=>$subscription]);
        if (!$sub) {
            $matches = $this->em->getRepository(EntiteSubscription::class)->findBy(['stripeCustomerId'=>$invoice['customer'] ?? '']);
            $tenants = [];
            foreach ($matches as $match) $tenants[$match->getEntite()->getId()] = $match;
            if (count($tenants) === 1) $sub = reset($tenants);
        }
        if (!$sub || $sub->getStripeCustomerId() !== ($invoice['customer'] ?? null)) return null;
        $paidAt = $invoice['status_transitions']['paid_at'] ?? null;
        if (!is_int($paidAt) || $paidAt <= 0) return null;
        $row = $this->em->find(Revenue::class, $invoice['id']) ?? new Revenue();
        $row->id = $invoice['id']; $row->entite = $sub->getEntite();
        $row->currency = strtolower($invoice['currency'] ?? 'eur');
        $row->live = ($invoice['livemode'] ?? false) === true;
        $row->paidAt = (new \DateTimeImmutable())->setTimestamp($paidAt);
        $row->syncedAt = new \DateTimeImmutable();
        $row->ht = isset($invoice['total_excluding_tax']) ? (int)$invoice['total_excluding_tax'] : null;
        $row->ttc = (int)($invoice['total'] ?? 0);
        $row->paid = (int)($invoice['amount_paid'] ?? 0);
        $row->label = $invoice['number'] ?? $row->id;
        $row->review = ($charges === null && $row->review) || !empty($invoice['post_payment_credit_notes_amount']) || !empty($invoice['pre_payment_credit_notes_amount']);
        if ($charges !== null) {
            $row->refund = 0; $fee = 0; $known = count($charges) > 0;
            foreach ($charges as $charge) {
                $row->refund += (int)($charge['amount_refunded'] ?? 0);
                $row->review = $row->review || !empty($charge['disputed']);
                $balance = $charge['balance_transaction'] ?? null;
                if (!is_array($balance) || ($balance['currency'] ?? '') !== $row->currency
                    || ($balance['source'] ?? '') !== ($charge['id'] ?? null) || !isset($balance['fee'])) $known = false;
                else $fee += (int)$balance['fee'];
            }
            $row->fee = $known ? $fee : null;
        }
        if ($row->refund || $row->review || $row->paid !== $row->ttc) $row->ht = null;
        $this->em->persist($row);
        return $row;
    }

    public function sync(): int
    {
        $lock = $this->locks->createLock('platform-revenue-sync', 900);
        if (!$lock->acquire()) throw new \RuntimeException('Un rapprochement est déjà en cours.');
        try {
            $client = $this->factory->client(); $count = 0; $customers = [];
            foreach ($this->em->getRepository(EntiteSubscription::class)->findAll() as $sub) {
                $customer = $sub->getStripeCustomerId();
                if (!$customer || isset($customers[$customer])) continue;
                $customers[$customer] = true;
                foreach ($client->invoices->all(['customer'=>$customer, 'status'=>'paid', 'limit'=>100])->autoPagingIterator() as $invoice) {
                    $data = $this->gateway->invoice($invoice->id);
                    $charges = [];
                    foreach ($data['_charges'] as $id) $charges[] = $client->charges->retrieve($id, ['expand'=>['balance_transaction']])->toArray();
                    if ($this->record($data, $charges)) { $this->em->flush(); ++$count; }
                    $lock->refresh();
                }
            }
            return $count;
        } finally { $lock->release(); }
    }
}
