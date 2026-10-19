<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Facture};
use App\Form\Administrateur\InvoiceFollowUpType;
use App\Service\Billing\InvoiceFollowUp;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TenantPermission::FACTURE_MANAGE, subject: 'entite')]
final class InvoiceFollowUpController extends AbstractController
{
    #[Route('/administrateur/{entite}/factures/relancer-selection', name: 'app_invoice_remind_selection', methods: ['POST'], requirements: ['entite' => '\d+'])]
    public function remindSelection(Entite $entite, Request $request, EntityManagerInterface $em, \App\Service\Billing\InvoiceReminderRunner $runner): Response
    {
        if (!$this->isCsrfTokenValid('invoice_reminders_'.$entite->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $ids = array_values(array_unique(array_map('intval', $request->request->all('invoices'))));
        if (!$ids || count($ids) > 50) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Sélectionnez entre 1 et 50 factures.');
        $invoices = $em->getRepository(Facture::class)->findBy(['id' => $ids, 'entite' => $entite]);
        if (count($invoices) !== count($ids)) throw $this->createAccessDeniedException();
        $counts = ['sent' => 0, 'ignored' => 0, 'unknown' => 0];
        foreach ($invoices as $invoice) {
            ++$counts[$runner->send($invoice, new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')), true)];
        }
        $this->addFlash($counts['unknown'] ? 'warning' : 'success', sprintf('%d relance(s) transmise(s) à la messagerie. %d ignorée(s) (solde, adresse ou délai de 24 h). %d envoi(s) à vérifier.', $counts['sent'], $counts['ignored'], $counts['unknown']));
        return $this->redirectToRoute('app_administrateur_dashboard_index', ['entite' => $entite->getId()]);
    }

    #[Route('/administrateur/{entite}/facture/{id}/suivi', name: 'app_invoice_follow_up', methods: ['GET', 'POST'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    public function settings(Entite $entite, Facture $invoice, Request $request, EntityManagerInterface $em): Response
    {
        if ($invoice->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
        $settings = InvoiceFollowUp::settings($invoice);
        $form = $this->createForm(InvoiceFollowUpType::class, array_intersect_key($settings, array_flip(['renewalMonths', 'reminderMonths'])))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->wrapInTransaction(function () use ($em, $invoice, $form) {
                $em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
                $meta = $invoice->getMeta() ?? [];
                $settings = InvoiceFollowUp::settings($invoice);
                $data = $form->getData();
                if ($settings['renewalMonths'] !== $data['renewalMonths']) $settings['renewalsHandled'] = 0;
                $meta['follow_up'] = array_replace($settings, $data);
                $invoice->setMeta($meta);
            });
            $this->addFlash('success', 'Les deux réglages de suivi ont été enregistrés.');
            return $this->redirectToRoute('app_invoice_follow_up', ['entite' => $entite->getId(), 'id' => $invoice->getId()]);
        }
        return $this->render('administrateur/facture/follow_up.html.twig', ['entite' => $entite, 'f' => $invoice, 'form' => $form, 'renewalDate' => InvoiceFollowUp::renewalDate($invoice), 'reminderDate' => InvoiceFollowUp::reminderDate($invoice)]);
    }

    #[Route('/administrateur/{entite}/facture/{id}/renouvellement-traite', name: 'app_invoice_renewal_handled', methods: ['POST'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    public function handled(Entite $entite, Facture $invoice, Request $request, EntityManagerInterface $em): Response
    {
        if ($invoice->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid('renewal_'.$invoice->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $em->wrapInTransaction(function () use ($em, $invoice, $request) {
            $em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            $date = InvoiceFollowUp::renewalDate($invoice);
            // The occurrence token also makes double-clicks and old tabs harmless.
            if (!$date || $date->format('Y-m-d') !== $request->request->getString('occurrence') || $date > new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'))) return;
            $meta = $invoice->getMeta() ?? [];
            $settings = InvoiceFollowUp::settings($invoice);
            $settings['renewalsHandled'] = 1 + (int)($settings['renewalsHandled'] ?? 0);
            $meta['follow_up'] = $settings;
            $invoice->setMeta($meta);
        });
        return $this->redirectToRoute('app_administrateur_dashboard_index', ['entite' => $entite->getId()]);
    }
}
