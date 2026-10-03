<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation\TrainingWorkflow;
use App\Enum\StatusSession;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Unpredictable and revocable dossier link; no email or other personal data in URLs. */
final class WorkflowPortal
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    public function token(TrainingWorkflow $workflow): string
    {
        if (!$workflow->getId()) throw new \LogicException('Le dossier doit être enregistré.');
        return hash_hmac('sha256', 'training-portal:v1:'.$workflow->getId().':'.$workflow->getPortalNonce(), $this->secret);
    }

    public function url(TrainingWorkflow $workflow): string
    {
        return $this->router->generate('app_workflow_portal', ['id' => $workflow->getId(), 'token' => $this->token($workflow), '_locale' => 'fr'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function expiresAt(TrainingWorkflow $workflow): ?\DateTimeImmutable
    {
        $end = $workflow->getSession()?->getDateFin() ?? $workflow->getSession()?->getDateDebut();
        return $end ? WorkflowTime::local($end)->modify('+30 days') : null;
    }

    public function optOutUrl(TrainingWorkflow $workflow): string
    {
        return $this->router->generate('app_workflow_portal_preferences', ['id' => $workflow->getId(), 'token' => $this->renewalToken($workflow), '_locale' => 'fr'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function renewalToken(TrainingWorkflow $workflow): string
    {
        if (!$workflow->getId()) throw new \LogicException('Le dossier doit être enregistré.');
        return hash_hmac('sha256', 'training-renewal:v1:'.$workflow->getId().':'.$workflow->getPortalNonce(), $this->secret);
    }

    public function isRenewalTokenValid(TrainingWorkflow $workflow, string $token): bool
    {
        return $workflow->getId() !== null && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && hash_equals($this->renewalToken($workflow), $token);
    }

    public function isValid(TrainingWorkflow $workflow, string $token, ?\DateTimeImmutable $now = null): bool
    {
        $session = $workflow->getSession();
        $convention = $workflow->getConvention();
        $entityId = $workflow->getEntite()?->getId();
        $expires = $this->expiresAt($workflow);
        return $workflow->getId() !== null && $entityId !== null && $session !== null && $convention !== null
            && $entityId === $session->getEntite()?->getId() && $entityId === $convention->getEntite()?->getId()
            && $convention->getSession()?->getId() === $session->getId()
            && $session->getStatus() !== StatusSession::CANCELED
            && $expires !== null && ($now ?? new \DateTimeImmutable()) <= $expires
            && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && hash_equals($this->token($workflow), $token);
    }

    public function canSign(TrainingWorkflow $workflow, ?\DateTimeImmutable $now = null): bool
    {
        $start = $workflow->getSession()?->getDateDebut();
        return $workflow->getConvention()?->getEntreprise() !== null && !$workflow->getConvention()->isSigned()
            && $start !== null && ($now ?? new \DateTimeImmutable()) < WorkflowTime::local($start);
    }
}
