<?php

namespace App\Service\Automation;

use App\Entity\{Facture, LigneFacture};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Enum\{DevisStatus, FactureStatus};
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\FactureNumberGenerator;
use App\Service\Billing\InvoiceTotals;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

final class WorkflowBilling
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly FactureNumberGenerator $numbers,
        private readonly MailerManager $mailer, private readonly PdfManager $pdf, private readonly Environment $twig) {}

    public static function outstanding(Facture $invoice): int
    {
        if ($invoice->isCanceled()) return 0;
        $paid = 0;
        foreach ($invoice->getPaiements() as $payment) $paid += $payment->getMontantCents() ?? 0;
        foreach ($invoice->getAvoirs() as $credit) $paid += $credit->getMontantTtcCents();
        return max(0, InvoiceTotals::totalTtc($invoice) - $paid);
    }

    public function handle(WorkflowTask $task): array
    {
        $w = $task->getWorkflow();
        if ($task->getAction() === 'invoice') {
            if (!$w->getConvention()->getDateSignatureEntreprise() && !$w->getConvention()->getDateSignatureStagiaire()) return $this->blocked('La convention doit être signée par le client avant de facturer.');
            if (!$w->getConvention()->getDevis()) return $this->blocked('Rattachez un devis validé à cette convention.');
            $quote = $w->getConvention()->getDevis();
            if ($quote->getDevise() !== 'EUR') return $this->blocked('La facturation automatique est disponible uniquement pour les devis en EUR.');
            if (!$quote->getFactureCreee() && !$this->quoteTotalsMatch($quote)) return $this->blocked('Les montants du devis ne correspondent plus à ses lignes et remises. Vérifiez le devis avant de facturer.');
            $invoice = $this->createInvoice($w);
            return ['status' => 'done', 'detail' => 'Facture ' . $invoice->getNumero() . ' rattachée.', 'snapshot' => ['invoiceId' => $invoice->getId()]];
        }
        $invoice = $w->getFacture();
        if (!$invoice) return $this->blocked('La facture n’a pas encore été créée.');
        if ($invoice->getEntite()?->getId() !== $w->getEntite()->getId()) return $this->blocked('La facture appartient à un autre organisme.');
        if ($invoice->isCanceled()) return ['status' => 'skipped', 'detail' => 'Facture annulée.'];
        if ($invoice->getDevise() !== 'EUR') return $this->blocked('L’envoi automatique est disponible uniquement pour les factures en EUR.');
        if ($task->getAction() === 'reminder' && $invoice->getDateEmission()->modify('+30 days') > new \DateTimeImmutable()) return $this->blocked('Le délai de 30 jours après émission de la facture n’est pas encore écoulé.');
        if ($task->getAction() === 'reminder' && self::outstanding($invoice) === 0) return ['status' => 'skipped', 'detail' => 'Le solde est nul après paiements et avoirs.'];
        $to = $invoice->getEntrepriseDestinataire()?->getEmailFacturation() ?: $w->getContactEmail();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Adresse de facturation manquante ou invalide.');
        $reminder = $task->getAction() === 'reminder';
        $subject = ($reminder ? 'Rappel de règlement — ' : 'Votre facture — ') . $invoice->getNumero();
        $text = $reminder ? 'Sauf erreur de notre part, le solde de votre facture est de ' . number_format(self::outstanding($invoice) / 100, 2, ',', ' ') . ' €. Si votre règlement est en cours, merci de nous le signaler.' : 'Vous trouverez en pièce jointe la facture de votre formation. Merci de votre confiance.';
        $html = $this->twig->render('automation/email.html.twig', ['entite' => $w->getEntite(), 'heading' => $subject, 'message' => $text]);
        $totals = InvoiceTotals::calculate($invoice);
        $bytes = $this->pdf->createPortraitBytes($this->twig->render('pdf/facture.html.twig', ['facture' => $invoice, 'entite' => $w->getEntite(), 'invoiceTotals' => $totals]));
        $snapshot = ['to' => $to, 'subject' => $subject, 'invoiceId' => $invoice->getId(), 'totals' => $totals, 'currency' => $invoice->getDevise(), 'sha256' => hash('sha256', $bytes)];
        // A second workflow may reference this same invoice. Reserve the delivery on the
        // locked invoice and COMMIT before handing it to the mail transport.
        if ($previous = $this->claimDelivery($task, $invoice, $snapshot)) return $previous;
        $this->mailer->sendHtmlWithAttachment($w->getEntite(), $to, $subject, $html, $text, $bytes, $invoice->getNumero() . '.pdf');
        $this->confirmDelivery($task, $invoice);
        return ['status' => 'sent', 'detail' => 'Message remis au transport de messagerie.', 'snapshot' => $snapshot];
    }

    private function blocked(string $detail): array { return ['status' => 'blocked', 'detail' => $detail]; }

    private function claimDelivery(WorkflowTask $task, Facture $invoice, array $snapshot): ?array
    {
        return $this->em->wrapInTransaction(function () use ($task, $invoice, $snapshot) {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->isCanceled()) return ['status' => 'skipped', 'detail' => 'Facture annulée.'];
            $meta = $invoice->getMeta() ?? [];
            $deliveries = $meta['workflow_deliveries'] ?? [];
            $action = $task->getAction();
            $reservation = $deliveries[$action] ?? null;
            if (($reservation['status'] ?? null) === 'retry_authorized') {
                if ((int) ($reservation['taskId'] ?? 0) !== $task->getId()) return $this->blocked('La reprise de cet envoi est réservée à son étape d’origine.');
                $previous = $this->previousDeliveryStatus($invoice, $action, $task);
            } else {
                $previous = $reservation['status'] ?? $this->previousDeliveryStatus($invoice, $action, $task);
            }
            if ($previous === 'sent') return ['status' => 'skipped', 'detail' => 'Cet envoi a déjà été effectué pour cette facture dans un dossier.', 'snapshot' => ['invoiceId' => $invoice->getId(), 'sharedDelivery' => true]];
            if ($previous !== null) return $this->blocked('Un envoi de cette facture est déjà réservé ou son résultat est incertain. Vérifiez la messagerie avant toute reprise.');
            if ($action === 'reminder') {
                $initial = $deliveries['invoice_send']['status'] ?? $this->previousDeliveryStatus($invoice, 'invoice_send', $task);
                if ($initial !== 'sent') return $this->blocked('Vérifiez d’abord l’envoi initial de la facture.');
                if (self::outstanding($invoice) === 0) return ['status' => 'skipped', 'detail' => 'Le solde est nul après paiements et avoirs.'];
            }
            $deliveries[$action] = ['status' => 'processing', 'taskId' => $task->getId(), 'workflowId' => $task->getWorkflow()->getId(),
                'claimedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'snapshot' => $snapshot];
            $meta['workflow_deliveries'] = $deliveries;
            $invoice->setMeta($meta);
            return null;
        });
    }

    private function confirmDelivery(WorkflowTask $task, Facture $invoice): void
    {
        $this->em->wrapInTransaction(function () use ($task, $invoice) {
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $meta = $invoice->getMeta() ?? [];
            $delivery = $meta['workflow_deliveries'][$task->getAction()] ?? [];
            if (($delivery['taskId'] ?? null) !== $task->getId() || ($delivery['status'] ?? null) !== 'processing') {
                throw new \RuntimeException('La réservation de cet envoi a changé : vérifiez la messagerie.');
            }
            $delivery['status'] = 'sent';
            $delivery['sentAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $meta['workflow_deliveries'][$task->getAction()] = $delivery;
            $invoice->setMeta($meta);
        });
    }

    /** Reconcile tasks created before the invoice-level delivery reservation existed. */
    private function previousDeliveryStatus(Facture $invoice, string $action, WorkflowTask $current): ?string
    {
        $rows = $this->em->createQueryBuilder()->select('t.id, t.status, t.snapshot, IDENTITY(w.facture) AS invoiceId')
            ->from(WorkflowTask::class, 't')->join('t.workflow', 'w')
            ->where('w.entite = :entity')->andWhere('t.action = :action')->andWhere('t.status IN (:statuses)')
            ->setParameter('entity', $invoice->getEntite())->setParameter('action', $action)
            ->setParameter('statuses', ['sent', 'unknown', 'processing'])->getQuery()->getArrayResult();
        $status = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $current->getId()) continue;
            $recordedId = $row['snapshot']['invoiceId'] ?? $row['invoiceId'];
            if ((int) $recordedId !== $invoice->getId()) continue;
            if ($row['status'] === 'sent') return 'sent';
            $status = $row['status'];
        }
        return $status;
    }

    private function quoteTotalsMatch(\App\Entity\Devis $quote): bool
    {
        $totals = InvoiceTotals::calculate($quote);
        return $totals['htCents'] === $quote->getMontantHtCents()
            && $totals['tvaCents'] === $quote->getMontantTvaCents()
            && $totals['ttcCents'] === $quote->getMontantTtcCents();
    }

    public function createInvoice(TrainingWorkflow $w): Facture
    {
        return $this->em->wrapInTransaction(function () use ($w) {
            $quote = $w->getConvention()->getDevis();
            $this->em->refresh($quote, LockMode::PESSIMISTIC_WRITE);
            if ($quote->getEntite()?->getId() !== $w->getEntite()->getId() || $quote->getStatus() === DevisStatus::CANCELED) throw new \DomainException('Le devis ne peut plus être facturé.');
            if ($quote->getFactureCreee()) {
                if ($quote->getFactureCreee()->getEntite()?->getId() !== $w->getEntite()->getId()) throw new \DomainException('La facture liée appartient à un autre organisme.');
                $w->setFacture($quote->getFactureCreee()); return $quote->getFactureCreee();
            }
            if ($quote->getDevise() !== 'EUR' || !$this->quoteTotalsMatch($quote)) throw new \DomainException('Vérifiez la devise et les montants du devis avant de facturer.');
            $f = (new Facture())->setEntite($w->getEntite())->setCreateur($w->getCreateur())->setNumero($this->numbers->nextForEntite($w->getEntite()->getId()))
                ->setDateEmission(new \DateTimeImmutable())->setStatus(FactureStatus::DUE)->setDevise($quote->getDevise())
                ->setDestinataire($quote->getDestinataire())->setEntrepriseDestinataire($quote->getEntrepriseDestinataire())->setFormation($quote->getFormation())
                ->setRemiseGlobalePourcent($quote->getRemiseGlobalePourcent())->setRemiseGlobaleMontantCents($quote->getRemiseGlobaleMontantCents())
                ->setMontantHtCents($quote->getMontantHtCents())->setMontantTvaCents($quote->getMontantTvaCents())->setMontantTtcCents($quote->getMontantTtcCents());
            foreach ($quote->getLignes() as $line) $f->addLigne((new LigneFacture())->setEntite($w->getEntite())->setCreateur($w->getCreateur())
                ->setLabel($line->getLabel())->setQte($line->getQte())->setPuHtCents($line->getPuHtCents())->setTva($line->getTva())->setIsDebours($line->isDebours())
                ->setRemisePourcent($line->getRemisePourcent())->setRemiseMontantCents($line->getRemiseMontantCents()));
            $totals = InvoiceTotals::calculate($f);
            $f->setMontantHtCents($totals['htCents'])->setMontantTvaCents($totals['tvaCents'])->setMontantTtcCents($totals['ttcCents'])
                ->setMeta(['workflow_billing' => ['version' => 1, 'quoteId' => $quote->getId(), 'createdTotals' => $totals, 'currency' => $f->getDevise()]]);
            foreach ($quote->getInscriptions() as $i) $f->addInscription($i);
            $quote->setFactureCreee($f)->setStatus(DevisStatus::INVOICED);
            $w->setFacture($f);
            $this->em->persist($f);
            return $f;
        });
    }
}
