<?php

namespace App\Service\Automation;

use App\Entity\Automation\WorkflowTask;
use App\Enum\StatusSession;
use App\Exception\InvoicePrerequisiteUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class WorkflowRunner
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WorkflowBilling $billing,
        private readonly WorkflowActionHandler $actions, private readonly LoggerInterface $logger) {}

    public function run(WorkflowTask $task, \DateTimeImmutable $now): string
    {
        $now = WorkflowTime::utc($now);
        $w = $task->getWorkflow();
        if (!$w->isEnabled() || $task->getDueAt() > $now || !in_array($task->getStatus(), ['pending', 'blocked'], true)) return 'unchanged';
        // Atomic claim is shared by all workers, and is committed BEFORE any email can leave.
        $claimed = $this->em->getConnection()->executeStatement("UPDATE workflow_task SET status = 'processing', processed_at = ? WHERE id = ? AND status IN ('pending', 'blocked')", [$now->format('Y-m-d H:i:s'), $task->getId()]);
        if ($claimed !== 1) return 'unchanged';
        $this->em->refresh($task);
        try {
            if ($w->getSession()->getEntite()?->getId() !== $w->getEntite()?->getId() || $w->getConvention()->getEntite()?->getId() !== $w->getEntite()?->getId() || $w->getConvention()->getSession()?->getId() !== $w->getSession()->getId()) $result = ['status' => 'blocked', 'detail' => 'Le dossier ne correspond pas à cet organisme et à cette session.'];
            elseif ($w->getSession()->getStatus() === StatusSession::CANCELED) $result = ['status' => 'skipped', 'detail' => 'Session annulée.'];
            elseif (in_array($task->getAction(), ['convention', 'participants', 'participants_import', 'account', 'convocation', 'trainer'], true) && $w->getSession()->getDateFin() && WorkflowTime::local($w->getSession()->getDateFin()) < $now) $result = ['status' => 'skipped', 'detail' => 'La formation est terminée : aucun message préparatoire tardif.'];
            else $result = in_array($task->getAction(), ['invoice', 'invoice_send', 'reminder'], true) ? $this->billing->handle($task) : $this->actions->handle($task);
            $snapshot = $result['snapshot'] ?? [];
            if (isset($task->getSnapshot()['recoveryHistory'])) $snapshot['recoveryHistory'] = $task->getSnapshot()['recoveryHistory'];
            $task->setStatus($result['status'])->setDetail($result['detail'] ?? null)->setSnapshot($snapshot);
        } catch (InvoicePrerequisiteUnavailable $e) {
            if ($task->getAction() === 'invoice' && !$this->em->isOpen()) {
                return $this->recoverRolledBackInvoice($task, $now, $e);
            }
            if (!$this->em->isOpen()) throw $e;
            // Only creation is safe to retry automatically. A delivery action must
            // never gain an automatic retry merely because an exception is typed.
            $task->setStatus($task->getAction() === 'invoice' ? 'blocked' : 'unknown')->setDetail($e->getMessage());
        } catch (\DomainException $e) {
            if (!$this->em->isOpen()) throw $e;
            $task->setStatus('blocked')->setDetail($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Workflow step failed; do not retry without reconciliation.', ['taskId' => $task->getId(), 'exception' => $e]);
            if (!$this->em->isOpen()) throw $e;
            $task->setStatus('unknown')->setDetail('Exécution interrompue. Vérifiez les documents et la messagerie avant toute reprise.');
        }
        $task->setProcessedAt($now);
        $this->em->flush();
        return $task->getStatus();
    }

    private function recoverRolledBackInvoice(WorkflowTask $task, \DateTimeImmutable $now, InvoicePrerequisiteUnavailable $error): string
    {
        $connection = $this->em->getConnection();
        // Doctrine closes its manager after wrapInTransaction rolls back. Its DBAL
        // connection remains usable, but never write a recovery inside an outer tx.
        if ($connection->isTransactionActive()) throw $error;
        $timestamp = $now->format('Y-m-d H:i:s');
        $changed = $connection->executeStatement(
            "UPDATE workflow_task SET status = 'blocked', detail = ? WHERE id = ? AND action = 'invoice' AND status = 'processing' AND processed_at = ?",
            [$error->getMessage(), $task->getId(), $timestamp]
        );
        if ($changed !== 1) throw new \RuntimeException('La réservation de facturation a changé ; vérifiez cette tâche avant toute reprise.', 0, $error);
        $task->setStatus('blocked')->setDetail($error->getMessage())->setProcessedAt($now);
        $this->logger->warning('Invoice prerequisites changed during issuance; transaction rolled back and task left retryable.', ['taskId' => $task->getId()]);
        return 'blocked';
    }
}
