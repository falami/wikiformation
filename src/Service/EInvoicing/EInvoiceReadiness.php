<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\{EInvoiceConnection, Entite};
use App\Exception\InvoicePrerequisiteUnavailable;
use Doctrine\ORM\EntityManagerInterface;

/** A server-side prerequisite for new invoices, never a restriction on reading historical documents. */
class EInvoiceReadiness
{
    public function __construct(
        private EntityManagerInterface $em,
        private EInvoiceConfiguration $configuration,
        private EInvoiceUsageBilling $usage,
    ) {}

    public function assertCanIssue(Entite $entite): void
    {
        $connection = $this->em->getRepository(EInvoiceConnection::class)->findOneBy([
            'entite' => $entite, 'environment' => 'production',
        ]);
        if (!$connection) {
            throw new InvoicePrerequisiteUnavailable('Avant d’émettre une facture, configurez la facturation électronique en production dans les préférences de votre organisme, puis activez les transmissions et le paiement Stripe.');
        }
        // A second check in the same request must not reuse a stale activation/disconnection state.
        $this->em->refresh($connection);
        $this->assertCanTransmit($connection);
    }

    /** Called under the connection lease, before recording consent or enabling transmissions. */
    public function assertCanActivate(EInvoiceConnection $connection): void
    {
        $this->assertConnection($connection);
        $this->assertBilling(fn() => $this->usage->assertSubscriptionReady($connection));
    }

    public function assertCanTransmit(EInvoiceConnection $connection): void
    {
        $this->assertConnection($connection);
        if (!$connection->isAutomatic() || !$connection->getEnabledAt()) {
            throw new InvoicePrerequisiteUnavailable('Activez les transmissions automatiques dans les préférences de facturation électronique avant d’émettre une nouvelle facture.');
        }
        $this->assertBilling(fn() => $this->usage->assertEligible($connection));
    }

    private function assertBilling(callable $check): void
    {
        try { $check(); }
        catch (\DomainException $error) {
            throw new InvoicePrerequisiteUnavailable($error->getMessage(), 0, $error);
        }
    }

    private function assertConnection(EInvoiceConnection $connection): void
    {
        if (!$this->configuration->ready()) {
            throw new InvoicePrerequisiteUnavailable('La configuration technique de la facturation électronique est incomplète. Contactez l’administrateur de la plateforme avant d’émettre une facture.');
        }
        if ($connection->getEnvironment() !== 'production' || !$connection->isVerified() || !$connection->getCredentials()) {
            throw new InvoicePrerequisiteUnavailable('Connectez et vérifiez votre organisme auprès de SUPER PDP en production dans les préférences de facturation électronique. Une connexion de test ne permet pas d’émettre une facture.');
        }
        $siret = preg_replace('/\s+/', '', $connection->getEntite()->getSiret() ?? '');
        if (!preg_match('/^\d{14}$/D', $siret) || $connection->getCompanyNumber() !== substr($siret, 0, 9)) {
            throw new InvoicePrerequisiteUnavailable('L’identité de l’organisme ne correspond plus au compte SUPER PDP vérifié. Vérifiez le SIRET et reconnectez le bon organisme.');
        }
    }
}
