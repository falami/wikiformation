<?php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Facture, FactureBrouillon, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Billing\{InvoiceDraftForm, InvoiceDraftManager, InvoiceIssuer, InvoiceSnapshot, InvoiceTotals};
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\{FormError, FormInterface};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/facture', name: 'app_administrateur_facture_', requirements: ['entite' => '\d+', 'draftId' => '\d+'])]
#[IsGranted(TenantPermission::FACTURE_MANAGE, subject: 'entite')]
final class InvoiceDraftController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceDraftForm $forms,
        private readonly InvoiceDraftManager $drafts,
        private readonly InvoiceIssuer $issuer,
        private readonly PdfManager $pdf,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function newDraft(Entite $entite, Request $request): Response
    {
        $draft = new FactureBrouillon($entite, $this->actor());
        $form = $this->forms->build($entite, $this->actor());
        if ($sourceId = $request->query->getInt('renewalSource')) {
            $source = $this->em->getRepository(Facture::class)->findOneBy(['id' => $sourceId, 'entite' => $entite]);
            if (!$source) throw $this->createNotFoundException();
            $invoice = $form->getData();
            $invoice->setDestinataire($source->getDestinataire())->setEntrepriseDestinataire($source->getEntrepriseDestinataire());
            $form = $this->forms->build($entite, $this->actor(), invoice: $invoice);
        }
        return $this->editForm($entite, $draft, $request, $form);
    }

    #[Route('/brouillons', name: 'draft_index', methods: ['GET'])]
    public function index(Entite $entite, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $archived = $request->query->getBoolean('archived');
        $qb = $this->em->getRepository(FactureBrouillon::class)->createQueryBuilder('d')
            ->where('d.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('d.factureEmise IS NULL')
            ->andWhere($archived ? 'd.archivedAt IS NOT NULL' : 'd.archivedAt IS NULL')
            ->orderBy('d.updatedAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * 25)->setMaxResults(26);
        $drafts = $qb->getQuery()->getResult();
        $hasNext = count($drafts) > 25;
        $rows = [];
        foreach (array_slice($drafts, 0, 25) as $draft) {
            $summary = $draft->getSummary();
            $rows[] = ['draft' => $draft, 'clientLabel' => $summary['customerLabel'] ?? 'Destinataire à compléter',
                'label' => $summary['title'] ?? 'Prestations à compléter', 'totalTtcCents' => $summary['totalTtcCents'] ?? null,
                'issueCount' => $summary['issueCount'] ?? 0];
        }
        return $this->privateResponse($this->render('administrateur/facture/draft_index.html.twig', compact('entite', 'rows', 'page', 'hasNext', 'archived')));
    }

    #[Route('/brouillons/{draftId}/modifier', name: 'draft_edit', methods: ['GET', 'POST'])]
    public function edit(Entite $entite, int $draftId, Request $request): Response
    {
        $draft = $this->find($entite, $draftId);
        if ($draft->getFactureEmise()) return $this->issuedRedirect($draft->getFactureEmise());
        if ($draft->isArchived()) return $this->redirectToRoute('app_administrateur_facture_draft_index', ['entite' => $entite->getId(), 'archived' => 1]);
        return $this->editForm($entite, $draft, $request, $this->forms->build($entite, $this->actor(), $draft->getPayload(), false));
    }

    private function editForm(Entite $entite, FactureBrouillon $draft, Request $request, FormInterface $form): Response
    {
        $status = 200;
        $version = $draft->getVersion();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invoice_draft_edit', $request->request->getString('_draft_token'))) throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
            $version = $request->request->getInt('_draft_version');
            try {
                $payload = $this->forms->sanitize($request->request->all('facture'));
                $form = $this->forms->build($entite, $this->actor(), $payload, false);
                $this->drafts->save($draft, $payload, $version, $this->actor(), $this->forms->summary($form));
                $this->addFlash('success', 'Brouillon enregistré. Aucun numéro de facture n’a été attribué.');
                return $this->redirectToRoute($request->request->get('draft_action') === 'review' ? 'app_administrateur_facture_draft_review' : 'app_administrateur_facture_draft_edit', ['entite' => $entite->getId(), 'draftId' => $draft->getId()], 303);
            } catch (\DomainException $error) {
                $form->addError(new FormError($error->getMessage()));
                $status = $draft->getVersion() !== $version ? 409 : 422;
            }
        }
        return $this->privateResponse($this->render('administrateur/facture/form.html.twig', [
            'form' => $form, 'entite' => $entite, 'draft' => $draft, 'draftVersion' => $version,
            'draftMode' => true, 'title' => $draft->getId() ? 'Modifier le brouillon' : 'Nouvelle facture · Brouillon',
        ], new Response(status: $status)));
    }

    #[Route('/brouillons/{draftId}/verification', name: 'draft_review', methods: ['GET'])]
    public function review(Entite $entite, int $draftId): Response
    {
        $draft = $this->find($entite, $draftId);
        if ($draft->getFactureEmise()) return $this->issuedRedirect($draft->getFactureEmise());
        if ($draft->isArchived()) throw $this->createNotFoundException('Ce brouillon est archivé.');
        return $this->reviewResponse($entite, $draft);
    }

    private function reviewResponse(Entite $entite, FactureBrouillon $draft, array $additionalIssues = [], ?int $status = null): Response
    {
        $form = $this->forms->build($entite, $this->actor(), $draft->getPayload());
        $facture = $form->getData();
        $issues = [...$additionalIssues, ...$this->forms->issues($form)];
        try { $this->issuer->assertCanIssue($entite); }
        catch (\DomainException $error) { $issues[] = $error->getMessage(); }
        if ($draft->getSourceDevis() && !$draft->getSourceDevis()->getStatus()->canBeInvoiced()) $issues[] = 'Le devis d’origine ne peut plus être facturé.';
        if ($draft->getSourceConvention()?->getDevis()) $issues[] = 'La convention est désormais liée à un devis : utilisez son brouillon de facture.';
        $warnings = $this->duplicateWarnings($facture);
        if ($facture->getDateEmission()->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d')) $warnings[] = 'La date d’émission est antérieure à aujourd’hui : vérifiez sa justification et les délais applicables avant de confirmer.';
        return $this->privateResponse($this->render('administrateur/facture/draft_review.html.twig', [
            'entite' => $entite, 'draft' => $draft, 'facture' => $facture, 'issues' => array_values(array_unique($issues)),
            'warnings' => $warnings, 'snapshot' => InvoiceSnapshot::draft($facture), 'totals' => InvoiceTotals::calculate($facture),
            'reviewToken' => $this->forms->reviewToken($draft, $facture), 'canIssue' => !$issues,
        ], new Response(status: $status ?? ($issues ? 422 : 200))));
    }

    #[Route('/brouillons/{draftId}/emettre', name: 'draft_issue', methods: ['POST'])]
    public function issue(Entite $entite, int $draftId, Request $request): Response
    {
        $draft = $this->find($entite, $draftId);
        if (!$this->isCsrfTokenValid('invoice_draft_issue_'.$draftId, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Confirmation expirée.');
        if ($draft->getFactureEmise()) return $this->issuedRedirect($draft->getFactureEmise());
        if ($draft->isArchived()) throw $this->createNotFoundException('Ce brouillon est archivé.');
        if ($request->request->getString('confirm_issue') !== '1') return $this->reviewResponse($entite, $draft, ['Confirmez avoir relu la facture avant son émission définitive.'], 422);
        try {
            $facture = $this->drafts->issue($draft, $request->request->getInt('version'), $this->actor(), function (FactureBrouillon $fresh) use ($entite, $request): Facture {
                $form = $this->forms->build($entite, $this->actor(), $fresh->getPayload());
                if ($issues = $this->forms->issues($form)) throw new \DomainException(implode(' ', $issues));
                $invoice = $form->getData();
                if (!hash_equals($this->forms->reviewToken($fresh, $invoice), $request->request->getString('review_token'))) throw new \DomainException('Le document ou les coordonnées ont changé depuis la relecture. Vérifiez de nouveau la facture avant de confirmer.');
                return $invoice;
            });
        } catch (\DomainException $error) {
            if (!$this->em->isOpen()) {
                $this->addFlash('warning', $error->getMessage());
                return $this->redirectToRoute('app_administrateur_facture_draft_review', ['entite' => $entite->getId(), 'draftId' => $draftId], 303);
            }
            return $this->reviewResponse($entite, $draft, [$error->getMessage()], 409);
        }
        $this->addFlash('success', 'Facture '.$facture->getNumero().' émise. Son contenu est désormais définitif.');
        return $this->issuedRedirect($facture);
    }

    #[Route('/brouillons/{draftId}/pdf', name: 'draft_pdf', methods: ['GET'])]
    public function preview(Entite $entite, int $draftId): Response
    {
        $draft = $this->find($entite, $draftId);
        if ($draft->getFactureEmise()) return $this->redirectToRoute('app_administrateur_facture_pdf', ['entite' => $entite->getId(), 'id' => $draft->getFactureEmise()->getId()]);
        if ($draft->isArchived()) throw $this->createNotFoundException();
        $form = $this->forms->build($entite, $this->actor(), $draft->getPayload());
        if (count($form->getErrors(true))) return $this->reviewResponse($entite, $draft, ['Corrigez les champs invalides avant de produire l’aperçu PDF.'], 422);
        $facture = $form->getData();
        $html = $this->renderView('pdf/facture.html.twig', [
            'entite' => $entite, 'facture' => $facture, 'invoiceDraft' => true,
            'draftSnapshot' => InvoiceSnapshot::draft($facture), 'invoiceTotals' => InvoiceTotals::calculate($facture),
        ]);
        // Apply after Document Studio as it can replace the complete HTML template.
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) $dom->removeChild($node);
        }
        $dom->encoding = 'UTF-8';
        foreach ($dom->getElementsByTagName('a') as $anchor) $anchor->removeAttribute('href');
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " draft-banner ") or contains(concat(" ", normalize-space(@class), " "), " draft-watermark ")]') as $previousMark) $previousMark->parentNode?->removeChild($previousMark);
        $body = $dom->getElementsByTagName('body')->item(0);
        $banner = $dom->createElement('div', 'BROUILLON — NON ÉMIS · Document préparatoire, ne pas régler');
        $banner->setAttribute('style', 'position:fixed;top:0;left:0;right:0;text-align:center;font:bold 11px sans-serif;color:#71551b;border:2px solid #b88d35;background:#fff7df;padding:8px;z-index:99999');
        $body?->insertBefore($banner, $body->firstChild);
        $watermark = $dom->createElement('div', 'BROUILLON — NON ÉMIS');
        $watermark->setAttribute('style', 'position:fixed;top:42%;left:0;width:100%;text-align:center;transform:rotate(-30deg);font:bold 40px sans-serif;color:#b88d35;opacity:0.25;z-index:99999');
        $body?->appendChild($watermark);
        return $this->privateResponse($this->pdf->createPortrait($dom->saveHTML(), 'BROUILLON_'.$draft->getReference()));
    }

    #[Route('/brouillons/{draftId}/archiver', name: 'draft_archive', methods: ['POST'])]
    public function archive(Entite $entite, int $draftId, Request $request): Response
    {
        return $this->archiveAction($entite, $draftId, $request, false);
    }

    #[Route('/brouillons/{draftId}/restaurer', name: 'draft_restore', methods: ['POST'])]
    public function restore(Entite $entite, int $draftId, Request $request): Response
    {
        return $this->archiveAction($entite, $draftId, $request, true);
    }

    private function archiveAction(Entite $entite, int $draftId, Request $request, bool $restore): Response
    {
        $draft = $this->find($entite, $draftId);
        if (!$this->isCsrfTokenValid('invoice_draft_'.($restore ? 'restore_' : 'archive_').$draftId, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try {
            $method = $restore ? 'restore' : 'archive';
            $this->drafts->$method($draft, $request->request->getInt('version'), $this->actor());
            $this->addFlash('success', $restore ? 'Brouillon restauré.' : 'Brouillon archivé. Vous pouvez le retrouver dans les archives.');
        } catch (\DomainException $error) { $this->addFlash('warning', $error->getMessage()); }
        return $this->redirectToRoute('app_administrateur_facture_draft_index', ['entite' => $entite->getId()], 303);
    }

    private function duplicateWarnings(Facture $invoice): array
    {
        if (!$invoice->getDestinataire() && !$invoice->getEntrepriseDestinataire()) return [];
        $qb = $this->em->getRepository(Facture::class)->createQueryBuilder('f')
            ->where('f.entite = :entite')->setParameter('entite', $invoice->getEntite())
            ->andWhere('f.montantTtcCents = :total')->setParameter('total', $invoice->getMontantTtcCents())
            ->andWhere('f.dateEmission >= :from AND f.dateEmission < :to')
            ->setParameter('from', $invoice->getDateEmission()->modify('-30 days'))
            ->setParameter('to', $invoice->getDateEmission()->modify('+31 days'))
            ->orderBy('f.dateEmission', 'DESC')->setMaxResults(3);
        $field = $invoice->getEntrepriseDestinataire() ? 'entrepriseDestinataire' : 'destinataire';
        $qb->andWhere('f.'.$field.' = :customer')->setParameter('customer', $invoice->getEntrepriseDestinataire() ?? $invoice->getDestinataire());
        return array_map(static fn(Facture $similar) => 'Doublon possible : la facture '.$similar->getNumero().' du '.$similar->getDateEmission()->format('d/m/Y').' concerne le même client et le même montant. Vérifiez qu’il s’agit bien d’une autre prestation.', $qb->getQuery()->getResult());
    }

    private function find(Entite $entite, int $id): FactureBrouillon
    {
        return $this->em->getRepository(FactureBrouillon::class)->findOneBy(['id' => $id, 'entite' => $entite]) ?? throw $this->createNotFoundException();
    }

    private function actor(): Utilisateur { return $this->getUser(); }

    private function issuedRedirect(Facture $invoice): Response
    {
        return $this->redirectToRoute('app_administrateur_facture_show', ['entite' => $invoice->getEntite()->getId(), 'id' => $invoice->getId()]);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
