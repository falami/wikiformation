<?php

namespace App\Service\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Service\Email\MailerManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class SubscriptionNotification
{
    public function __construct(private EntityManagerInterface $em, private MailerManager $mailer) {}

    public function send(EntiteSubscription $subscription): void
    {
        $this->em->wrapInTransaction(function () use ($subscription): void {
            // Serialize simultaneous checkout and subscription webhooks.
            $this->em->lock($subscription, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($subscription);
            if ($subscription->getSubscriptionNotifiedAt()
                || !$subscription->getStripeSubscriptionId()
                || !in_array($subscription->getStatus(), [EntiteSubscription::STATUS_ACTIVE, EntiteSubscription::STATUS_TRIALING], true)) {
                return;
            }

            $organization = $subscription->getEntite();
            $name = $organization?->getNom() ?: 'Organisme non renseigné';
            $offer = $subscription->getPlan()?->getName() ?: 'Offre personnalisée';
            $period = $subscription->getIntervale() === 'year' ? 'Annuel' : 'Mensuel';
            $status = $subscription->getStatus() === EntiteSubscription::STATUS_TRIALING ? 'Période d’essai' : 'Actif';
            $url = 'https://dashboard.stripe.com/subscriptions/'.rawurlencode($subscription->getStripeSubscriptionId());
            $details = [
                'Organisme' => $name,
                'Contact' => $organization?->getEmail() ?: 'Non renseigné',
                'Offre' => $offer,
                'Facturation' => $period,
                'Statut' => $status,
                'Référence Stripe' => $subscription->getStripeSubscriptionId(),
            ];
            $text = "NOUVEL ABONNEMENT · WikiFormation\n\nUn organisme vient de rejoindre la plateforme.\n\n";
            foreach ($details as $label => $value) {
                $text .= $label.' : '.$value."\n";
            }
            $text .= "\nConsulter l’abonnement : ".$url;
            $this->mailer->sendMailContextWithText(
                'contact@wikiformation.fr', 'contact@wikiformation.fr',
                'Nouvel abonnement · '.$name.' · '.$offer,
                'emails/billing/subscription_notification.html.twig',
                ['details' => $details, 'offer' => $offer, 'organizationName' => $name, 'status' => $status, 'stripeUrl' => $url],
                $text,
            );
            // Failure propagates to Stripe (HTTP 500), leaving notification retryable.
            $subscription->markSubscriptionNotified();
            $this->em->flush();
        });
    }
}
