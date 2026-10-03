<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation\WorkflowTask;
use App\Entity\{Entite, Facture, Utilisateur};
use App\Security\Permission\TenantPermission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Manual reconciliation only: never sends mail or executes the recovered task. */
final class WorkflowTaskRecovery
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}

    public static function revision(WorkflowTask $task): string
    {
        return hash('sha256', json_encode([$task->getId(), $task->getStatus(), $task->getProcessedAt()?->format(DATE_ATOM), $task->getSnapshot()], JSON_THROW_ON_ERROR));
    }

    public function recover(Entite $entity, WorkflowTask $task, Utilisateur $actor, string $decision, string $reason, string $revision, ?\DateTimeImmutable $now = null): void
    {
        $this->assertTenant($entity, $task);
        if (!$this->authorization->isGranted(TenantPermission::ADMIN, $entity)) throw new AccessDeniedException();
        $reason = trim($reason);
        if (!in_array($decision, ['confirmed', 'retry'], true) || mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            throw new \DomainException('Choisissez un résultat et précisez votre vérification (10 à 2 000 caractères).');
        }
        $now = WorkflowTime::utc($now ?? new \DateTimeImmutable());
        // DBAL transaction keeps the manager usable when a stale form is rejected.
        $this->em->getConnection()->transactional(function () use ($entity, $task, $actor, $decision, $reason, $revision, $now): void {
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $this->assertTenant($entity, $task);
            if (!hash_equals(self::revision($task), $revision)) throw new \DomainException('Cette étape a changé. Rechargez la page avant de la rapprocher.');
            if (!in_array($task->getStatus(), ['unknown', 'processing'], true)) throw new \DomainException('Seule une étape interrompue peut être rapprochée.');
            if ($task->getStatus() === 'processing' && (!$task->getProcessedAt() || $task->getProcessedAt() > $now->modify('-30 minutes'))) {
                throw new \DomainException('Attendez au moins 30 minutes après le début de cette étape pour exclure un envoi encore en cours.');
            }

            $snapshot = $task->getSnapshot();
            $invoice = null;
            $meta = null;
            $reservation = null;
            if (in_array($task->getAction(), ['invoice_send', 'reminder'], true)) {
                $invoiceId = $snapshot['invoiceId'] ?? $task->getWorkflow()->getFacture()?->getId();
                $invoice = $invoiceId ? $this->em->find(Facture::class, $invoiceId) : null;
                if (!$invoice || $invoice->getEntite()?->getId() !== $entity->getId()) throw new \DomainException('Retrouvez la facture de cette étape avant de rapprocher son envoi.');
                $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                $meta = $invoice->getMeta() ?? [];
                $reservation = $meta['workflow_deliveries'][$task->getAction()] ?? null;
                if ($reservation !== null && (int) ($reservation['taskId'] ?? 0) !== $task->getId()) {
                    throw new \DomainException('Cet envoi appartient à une autre étape. Rapprochez l’étape propriétaire dans son dossier pour éviter un doublon.');
                }
                if ($decision === 'retry' && ($reservation['status'] ?? null) === 'sent') throw new \DomainException('Cette facture a déjà un envoi confirmé. Une nouvelle émission automatique n’est pas autorisée.');
                if ($reservation && $task->getStatus() === 'processing') {
                    try { $claimedAt = new \DateTimeImmutable($reservation['claimedAt'] ?? 'now'); }
                    catch (\Exception) { throw new \DomainException('La date de réservation doit être vérifiée avant toute reprise.'); }
                    if ($claimedAt > $now->modify('-30 minutes')) throw new \DomainException('La réservation de l’envoi a moins de 30 minutes. Réessayez après vérification.');
                }
                $snapshot = array_replace($reservation['snapshot'] ?? [], $snapshot, ['invoiceId' => $invoice->getId()]);
            }
            if ($decision === 'confirmed' && $task->getAction() === 'invoice' && !$task->getWorkflow()->getFacture()) {
                throw new \DomainException('Aucune facture n’est rattachée à ce dossier. Confirmez l’absence de création pour replanifier l’étape.');
            }

            $audit = ['at' => $now->format(DATE_ATOM), 'actorId' => $actor->getId(), 'decision' => $decision, 'reason' => $reason,
                'previousStatus' => $task->getStatus(), 'previousProcessedAt' => $task->getProcessedAt()?->format(DATE_ATOM),
                'previousSnapshot' => array_diff_key($snapshot, ['recoveryHistory' => true]),
                'invoiceId' => $invoice?->getId(), 'reservationStatus' => $reservation['status'] ?? null];
            $history = $snapshot['recoveryHistory'] ?? [];
            $history[] = $audit;
            $snapshot['recoveryHistory'] = $history;
            $snapshot['manuallyConfirmed'] = $decision === 'confirmed';

            if ($invoice) {
                if ($decision === 'retry') {
                    // Keep ownership until this exact task claims the next attempt.
                    $meta['workflow_deliveries'][$task->getAction()] = array_replace($reservation ?? [], [
                        'status' => 'retry_authorized', 'taskId' => $task->getId(), 'workflowId' => $task->getWorkflow()->getId(),
                        'authorizedAt' => $now->format(DATE_ATOM), 'authorizedBy' => $actor->getId(), 'snapshot' => $snapshot,
                    ]);
                } else {
                    $meta['workflow_deliveries'][$task->getAction()] = array_replace($reservation ?? [], [
                        'status' => 'sent', 'taskId' => $task->getId(), 'workflowId' => $task->getWorkflow()->getId(),
                        'sentAt' => $now->format(DATE_ATOM), 'confirmedBy' => $actor->getId(), 'snapshot' => $snapshot,
                    ]);
                }
                $meta['workflow_delivery_recovery_history'][] = $audit + ['taskId' => $task->getId(), 'action' => $task->getAction()];
                $invoice->setMeta($meta);
            }
            $task->setSnapshot($snapshot)->setStatus($decision === 'retry' ? 'pending' : ($task->getAction() === 'invoice' ? 'done' : 'sent'))
                ->setProcessedAt($decision === 'retry' ? null : $now)
                ->setDetail($decision === 'retry' ? 'Absence d’exécution confirmée manuellement ; étape replanifiée.' : 'Exécution confirmée manuellement après vérification.');
            if ($decision === 'retry') $task->setDueAt($now);
            $this->em->flush();
        });
    }

    private function assertTenant(Entite $entity, WorkflowTask $task): void
    {
        if (!$entity->getId() || $task->getWorkflow()?->getEntite()?->getId() !== $entity->getId()) throw new AccessDeniedException();
    }
}
