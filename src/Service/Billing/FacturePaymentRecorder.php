<?php
declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Billing\FactureCheckout;
use App\Entity\Paiement;
use App\Enum\{FactureStatus, ModePaiement};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Shared by the signed webhook and the provider-verified return page. */
final class FacturePaymentRecorder
{
    public function __construct(private EntityManagerInterface $em) {}

    public function record(FactureCheckout $checkout, array $session, ?string $connectedAccount = null): bool
    {
        if (($session['payment_status'] ?? '') !== 'paid') return false;

        return $this->em->wrapInTransaction(function () use ($checkout, $session, $connectedAccount): bool {
            $invoice = $checkout->getFacture();
            if (!$invoice) throw new \DomainException('Facture introuvable.');
            // Same lock order as checkout creation, including concurrent webhook reconciliation.
            $this->em->lock($invoice, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invoice);
            $this->em->lock($checkout, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($checkout);
            $invoice = $checkout->getFacture();
            $pi = $session['payment_intent'] ?? null;
            $operation = $invoice?->getMeta()['stripe_checkout_requests'][(string) $checkout->getId()] ?? null;
            $expectedAccount = $operation['account'] ?? $invoice?->getEntite()?->getConnect()?->getStripeAccountId();
            $expectedCurrency = $operation['params']['line_items'][0]['price_data']['currency'] ?? ($invoice?->getDevise() ?: 'EUR');
            $matchesReservation = $checkout->getStripeCheckoutSessionId() === ($session['id'] ?? '')
                || ($checkout->getStripeCheckoutSessionId() === null
                    && str_starts_with((string) ($session['id'] ?? ''), 'cs_')
                    && ($session['metadata']['type'] ?? '') === 'facture_checkout'
                    && (int) ($session['metadata']['facture_checkout_id'] ?? 0) === $checkout->getId()
                    && (int) ($session['metadata']['facture_id'] ?? 0) === $invoice?->getId()
                    && (int) ($session['metadata']['entite_id'] ?? 0) === $checkout->getEntite()?->getId());
            if (!$invoice || $checkout->getEntite()?->getId() !== $invoice->getEntite()?->getId()
                || !$matchesReservation
                || ($session['mode'] ?? '') !== 'payment'
                || !is_string($pi) || !str_starts_with($pi, 'pi_')
                || ($session['amount_total'] ?? -1) !== $checkout->getAmountTotalCents()
                || strtolower((string) ($session['currency'] ?? '')) !== strtolower($expectedCurrency)
                || $checkout->getFactureAmountCents() <= 0
                || $checkout->getFactureAmountCents() > $checkout->getAmountTotalCents()
                || ($connectedAccount !== null && $connectedAccount !== $expectedAccount)) {
                throw new \DomainException('Paiement Stripe incompatible avec la facture : rapprochement requis.');
            }
            $checkout->setStripeCheckoutSessionId($session['id']);
            if ($checkout->getStatus() === FactureCheckout::STATUS_COMPLETED) return true;
            $existing = $this->em->getRepository(Paiement::class)->findOneBy(['stripePaymentIntentId' => $pi]);
            if ($existing && ($existing->getFacture()?->getId() !== $invoice->getId()
                || $existing->getMontantCents() !== $checkout->getFactureAmountCents())) {
                throw new \DomainException('Ce débit Stripe est déjà associé à un autre règlement.');
            }
            if (!$existing) {
                $payment = (new Paiement())->setEntite($invoice->getEntite())->setCreateur($invoice->getCreateur())
                    ->setMode(ModePaiement::CB)->setDevise(strtoupper($session['currency']))
                    ->setMontantCents($checkout->getFactureAmountCents())->setStripePaymentIntentId($pi)
                    ->setPayeurUtilisateur($checkout->getPayeurUtilisateur() ?? $invoice->getDestinataire())
                    ->setPayeurEntreprise($checkout->getPayeurEntreprise() ?? $invoice->getEntrepriseDestinataire())
                    ->setVentilationSource('stripe_auto')
                    ->setMeta(['stripe' => ['checkout_session_id' => $session['id'], 'connected_account' => $connectedAccount,
                        'application_fee_cents' => $checkout->getServiceFeeCents()]]);
                $invoice->addPaiement($payment);
                $this->em->persist($payment);
            }
            $checkout->setStripePaymentIntentId($pi)->setStatus(FactureCheckout::STATUS_COMPLETED);
            if (!$invoice->isCanceled()) {
                $credits = 0;
                foreach ($invoice->getAvoirs() as $credit) $credits += $credit->getMontantTtcCents();
                $invoice->setStatus(InvoiceTotals::totalTtc($invoice) > $credits && InvoiceTotals::outstanding($invoice) === 0 ? FactureStatus::PAID : FactureStatus::PARTIALLY_PAID);
            }
            $this->em->flush();
            return true;
        });
    }
}
