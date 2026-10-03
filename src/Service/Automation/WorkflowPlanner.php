<?php

namespace App\Service\Automation;

use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Enum\StatusInscription;
use Doctrine\ORM\EntityManagerInterface;

final class WorkflowPlanner
{
    public const LABELS = [
        'convention' => 'Convention et demande de signature', 'participants' => 'Collecte des participants',
        'participants_import' => 'Inscription des participants collectés', 'account' => 'Invitation à l’espace stagiaire',
        'convocation' => 'Convocation', 'trainer' => 'Information du formateur', 'attendance' => 'Émargement et QR code',
        'satisfaction' => 'Questionnaire de satisfaction', 'evaluation' => 'Évaluation des acquis',
        'attestation' => 'Attestation de fin de formation', 'certificate' => 'Certificat métier',
        'invoice' => 'Création de la facture', 'invoice_send' => 'Envoi de la facture',
        'reminder' => 'Relance de paiement', 'renewal' => 'Proposition de recyclage',
    ];

    public function __construct(private readonly EntityManagerInterface $em, private readonly WorkflowCertificateStorage $certificates) {}

    /** Existing successful steps are immutable; changed dates only move unsent tasks. */
    public function synchronize(TrainingWorkflow $workflow): array
    {
        // Serialize the schedule of one dossier across cron workers and administrator actions.
        $this->em->beginTransaction();
        try {
            $this->em->lock($workflow, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $tasks = $this->synchronizeLocked($workflow);
            $this->em->commit();
            return $tasks;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    private function synchronizeLocked(TrainingWorkflow $workflow): array
    {
        $existing = $this->em->getRepository(WorkflowTask::class)->findBy(['workflow' => $workflow]);
        $byKey = [];
        foreach ($existing as $task) $byKey[$task->getTaskKey()] = $task;
        $active = [];
        foreach ($this->schedule($workflow) as [$action, $target, $due]) {
            $key = $action . ':' . ($target ?? 'all');
            $active[] = $key;
            $task = $byKey[$key] ?? (new WorkflowTask())->setWorkflow($workflow)->setTaskKey($key)->setAction($action)->setTargetId($target);
            if ($task->getStatus() === 'skipped' && ($task->getSnapshot()['obsolete'] ?? false) === true) {
                $task->setStatus('pending')->setDetail(null)->setSnapshot([])->setProcessedAt(null);
            }
            if (!isset($byKey[$key]) || in_array($task->getStatus(), ['pending', 'blocked'], true)) $task->setDueAt($due);
            $this->em->persist($task);
            $byKey[$key] = $task;
        }
        foreach ($byKey as $key => $task) {
            if (!in_array($key, $active, true) && in_array($task->getStatus(), ['pending', 'blocked'], true)) {
                $task->setStatus('skipped')->setDetail('Étape devenue sans objet après modification du dossier.')->setSnapshot(['obsolete' => true]);
            }
        }
        $this->em->flush();
        $tasks = array_values($byKey);
        usort($tasks, static fn($a, $b) => $a->getDueAt() <=> $b->getDueAt() ?: $a->getId() <=> $b->getId());
        return $tasks;
    }

    public function schedule(TrainingWorkflow $workflow): array
    {
        $session = $workflow->getSession();
        $start = $session?->getDateDebut();
        $end = $session?->getDateFin();
        if (!$start || !$end) return [];
        $start = WorkflowTime::local($start);
        $end = WorkflowTime::local($end);
        $before = static fn(int $days) => $start->modify('-' . $days . ' days')->setTime(9, 0);
        $tasks = [
            ['convention', null, $before(30)], ['participants', null, $before(15)],
            ['participants_import', null, $before(7)->modify('-30 minutes')],
            ['attendance', null, $start], ['invoice', null, $end->modify('+1 day')->setTime(9, 0)],
            ['invoice_send', null, $end->modify('+1 day')->setTime(9, 5)],
        ];
        $invoiceDate = $workflow->getFacture()?->getDateEmission();
        $invoiceDate = $invoiceDate ? WorkflowTime::local($invoiceDate) : $end->modify('+1 day');
        $tasks[] = ['reminder', null, $invoiceDate->modify('+30 days')->setTime(9, 0)];
        foreach ($workflow->getConvention()->getInscriptions() as $inscription) {
            if (in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) continue;
            $id = $inscription->getId();
            if (($inscription->getMeta()['workflowNewAccount'] ?? false) === true) $tasks[] = ['account', $id, $before(7)->modify('-15 minutes')];
            $tasks[] = ['convocation', $id, $before(7)];
            foreach (['satisfaction', 'evaluation', 'attestation', 'certificate'] as $action) $tasks[] = [$action, $id, $end];
        }
        foreach ($session->getFormateursEffectifs() as $trainer) $tasks[] = ['trainer', $trainer->getId(), $before(7)];
        if ($workflow->getValidityMonths() !== null && $workflow->isRenewalEnabled()) {
            $dates = [];
            foreach ($workflow->getConvention()->getInscriptions() as $inscription) {
                $certificate = $this->certificates->getValidated($inscription);
                if ($inscription->getStatus() === StatusInscription::TERMINE && $inscription->isReussi() && $certificate) {
                    $dates[] = self::addMonths(WorkflowTime::date($certificate['issuedOn']), $workflow->getValidityMonths() - 2)->setTime(9, 0);
                }
            }
            if ($dates) $tasks[] = ['renewal', null, min($dates)];
        }
        // Convert after calendar arithmetic so 09:00 remains 09:00 through DST changes.
        return array_map(static fn(array $task): array => [$task[0], $task[1], WorkflowTime::utc($task[2])], $tasks);
    }

    /** Calendar arithmetic clamps the end of month, including leap-day certificates. */
    public static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $first = $date->modify('first day of this month')->modify('+' . $months . ' months');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $date->format('d'), (int) $first->format('t')));
    }
}
