<?php

declare(strict_types=1);
namespace App\Service\Stripe;

use App\Entity\Billing\FactureCheckout;
use App\Entity\Facture;
use App\Service\Billing\InvoiceTotals;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** One durable checkout reservation shared by all invoice payment entry points. */
final class FactureCheckoutManager
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly EntityManagerInterface $em,
        private readonly string $appUrl,
    ) {}

    public function createCheckoutForFacture(Facture $facture): FactureCheckout
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            throw new \RuntimeException('Le paiement doit être créé hors de toute transaction externe.');
        }
        $stripe = $this->stripeFactory->client();
        $this->em->beginTransaction();
        try {
            $this->em->lock($facture, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($facture);
            $entite = $facture->getEntite();
            $connect = $entite?->getConnect();
            if (!$connect || !$connect->isOnlinePaymentEnabled() || !$connect->isReadyForCheckout() || !$connect->getStripeAccountId()) {
                throw new \RuntimeException('Paiement en ligne indisponible pour cet organisme.');
            }
            $account = $connect->getStripeAccountId();
            $amount = InvoiceTotals::outstanding($facture);
            if (!$facture->canBePaid() || $amount <= 0) throw new \RuntimeException('Cette facture ne présente plus de montant à régler.');

            // Do not ignore old or response-lost reservations: their Stripe link may still be payable.
            $pending = $this->em->getRepository(FactureCheckout::class)->findBy(['facture' => $facture, 'status' => FactureCheckout::STATUS_CREATED], ['id' => 'DESC']);
            if (count($pending) > 1) throw new \RuntimeException('Plusieurs paiements en cours : rapprochement Stripe requis.');
            $checkout = $pending[0] ?? null;
            if ($checkout) {
                $this->em->refresh($checkout);
                $unchanged = $checkout->getFactureAmountCents() === $amount && $checkout->getAmountTotalCents() === $amount;
                $operation = $facture->getMeta()['stripe_checkout_requests'][(string) $checkout->getId()] ?? null;
                if ($checkout->getStripeCheckoutSessionId()) {
                    // Legacy destination charges were created on the platform; new charges are direct.
                    $options = ['stripe_account' => $operation['account'] ?? $account];
                    try {
                        $remote = $stripe->checkout->sessions->retrieve($checkout->getStripeCheckoutSessionId(), [], $options);
                    } catch (\Stripe\Exception\InvalidRequestException $error) {
                        if ($error->getStripeCode() !== 'resource_missing') throw $error;
                        $options = [];
                        $remote = $stripe->checkout->sessions->retrieve($checkout->getStripeCheckoutSessionId());
                    }
                    if ($remote->status === 'complete') throw new \RuntimeException('Paiement terminé ou en cours de traitement : rapprochement requis.');
                    if (!in_array($remote->status, ['open', 'expired'], true)) throw new \RuntimeException('État Stripe inattendu : rapprochement requis.');
                    if ($remote->status === 'open' && $unchanged) {
                        if (!$remote->url) throw new \RuntimeException('Lien de paiement Stripe manquant.');
                        $checkout->setCheckoutUrl($remote->url);
                        $this->em->flush();
                        $this->em->commit();
                        return $checkout;
                    }
                    if ($remote->status === 'open') {
                        $expired = $stripe->checkout->sessions->expire($checkout->getStripeCheckoutSessionId(), [], $options);
                        if ($expired->status !== 'expired') throw new \RuntimeException('Le précédent paiement reste actif.');
                    }
                    $checkout->setStatus(FactureCheckout::STATUS_EXPIRED);
                    $this->em->flush();
                    $checkout = null;
                } elseif (!$operation || !$unchanged || $checkout->getCreatedAt() < new \DateTimeImmutable('-23 hours')) {
                    // Stripe may discard an idempotency key after 24h. Never blindly recreate an uncertain payment.
                    throw new \RuntimeException('Paiement à rapprocher avec Stripe avant toute nouvelle tentative.');
                }
            }
            if (!$checkout) {
                // Verify the real fee payer before reserving a NEW payment. Historical links and
                // lost-response retries retain their frozen terms and idempotency key.
                $remoteAccount = $this->readAccount($stripe, $account);
                $feePolicy = DirectChargeFeePolicy::snapshot($remoteAccount, $account, (int) $entite->getId());
                $checkout = (new FactureCheckout())->setFacture($facture)->setEntite($entite)
                    ->setFactureAmountCents($amount)->setAmountTotalCents($amount)
                    ->setServiceFeeCents($connect->computeServiceFeeCents($amount))
                    ->setPayeurUtilisateur($facture->getDestinataire())->setPayeurEntreprise($facture->getEntrepriseDestinataire());
                $this->em->persist($checkout);
                $this->em->flush();
                $metadata = ['type' => 'facture_checkout', 'facture_checkout_id' => (string) $checkout->getId(), 'facture_id' => (string) $facture->getId(), 'entite_id' => (string) $entite->getId()];
                $operation = ['account' => $account, 'feePolicy' => $feePolicy, 'params' => [
                    'mode' => 'payment', 'payment_method_types' => ['card'],
                    'success_url' => rtrim($this->appUrl, '/') . '/paiement/success?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => rtrim($this->appUrl, '/') . '/paiement/cancel?facture=' . $facture->getId(),
                    'line_items' => [['quantity' => 1, 'price_data' => [
                        'currency' => strtolower($facture->getDevise() ?: 'eur'), 'unit_amount' => $checkout->getAmountTotalCents(),
                        'product_data' => ['name' => 'Facture ' . ($facture->getNumero() ?: '#' . $facture->getId())],
                    ]]],
                    'metadata' => $metadata,
                    'payment_intent_data' => ['application_fee_amount' => $checkout->getServiceFeeCents(), 'metadata' => $metadata],
                ]];
                $meta = $facture->getMeta();
                $meta['stripe_checkout_requests'][(string) $checkout->getId()] = $operation;
                $facture->setMeta($meta);
                $this->em->flush();
            }
            // Commit before the external side effect so a network interruption cannot lose the operation key.
            $this->em->commit();
        } catch (\Throwable $error) {
            if ($this->em->getConnection()->isTransactionActive()) $this->em->rollback();
            throw $error;
        }

        // The complete request and connected account are frozen before the first external attempt.
        $session = $stripe->checkout->sessions->create($operation['params'], [
            'stripe_account' => $operation['account'], 'idempotency_key' => 'invoice-checkout-' . $checkout->getId(),
        ]);
        if (!$session->id || !$session->url) throw new \RuntimeException('Réponse Stripe incomplète : rapprochement requis.');
        $checkout->setStripeCheckoutSessionId($session->id)->setCheckoutUrl($session->url);
        $this->em->flush();
        return $checkout;
    }

    private function readAccount(\Stripe\StripeClient $stripe, string $account): array
    {
        $http = \Stripe\ApiRequestor::httpClient();
        $timeouts = $http instanceof \Stripe\HttpClient\CurlClient ? [$http->getTimeout(), $http->getConnectTimeout()] : null;
        try {
            // Bound this verification while the invoice is locked, preserving stricter transport limits.
            if ($timeouts !== null) {
                $http->setTimeout($timeouts[0] > 0 ? min($timeouts[0], 10) : 10);
                $http->setConnectTimeout($timeouts[1] > 0 ? min($timeouts[1], 3) : 3);
            }
            return $stripe->accounts->retrieve($account, [])->toArray();
        } catch (\Throwable) {
            throw new \App\Exception\StripeFeeConfigurationUnavailable('Impossible de vérifier les frais Stripe pour le moment. Aucun nouveau paiement n’a été créé.');
        } finally {
            if ($timeouts !== null) {
                $http->setTimeout($timeouts[0]);
                $http->setConnectTimeout($timeouts[1]);
            }
        }
    }
}
