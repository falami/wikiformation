<?php
declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\{DocumentTemplateBinding, Entite, Facture, FactureBrouillon, Utilisateur};
use App\Form\Administrateur\FactureType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\{FormError, FormFactoryInterface, FormInterface};

/** Replays untrusted working values through the tenant-scoped invoice form. */
final class InvoiceDraftForm
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    public function build(Entite $entite, Utilisateur $actor, ?array $payload = null, bool $validate = true, ?Facture $invoice = null): FormInterface
    {
        $invoice ??= (new Facture())->setEntite($entite)->setCreateur($actor)
            ->setMontantHtCents(0)->setMontantTvaCents(0)->setMontantTtcCents(0);
        $form = $this->forms->create(FactureType::class, $invoice, [
            'entite' => $entite, 'csrf_protection' => false, 'draft_mode' => true,
            'validation_groups' => $validate ? null : false,
        ]);
        if ($payload !== null) $form->submit($this->sanitize($payload));
        $base = 0;
        $unsafeAmounts = false;
        foreach ($invoice->getLignes() as $line) {
            $line->setEntite($entite)->setCreateur($actor)->setFacture($invoice);
            // Bound arithmetic before multiplying. Raw input remains in the form/payload.
            if ($line->getQte() < 0 || $line->getQte() > 1_000_000 || $line->getPuHtCents() < 0
                || ($line->getQte() > 0 && $line->getPuHtCents() > intdiv(1_000_000_000, $line->getQte()))) {
                $unsafeAmounts = true;
            } else {
                $base += $line->getQte() * $line->getPuHtCents();
            }
        }
        if ($unsafeAmounts || $base > 1_000_000_000) {
            $form->addError(new FormError('Vérifiez les quantités et les prix : le calcul sécurisé est limité à 10 000 000 € HT bruts par facture et 1 000 000 unités par ligne.'));
            foreach ($invoice->getLignes() as $line) $line->setQte(0)->setPuHtCents(0);
        }
        InvoiceTotals::finalizeNewInvoice($invoice);
        $studio = [];
        foreach (['facture', 'facture_acquittee'] as $kind) {
            $binding = $this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['entite' => $entite, 'kind' => $kind]);
            if ($binding) $studio[$kind] = $binding->getConfig();
        }
        if ($studio) $invoice->setMeta(array_replace($invoice->getMeta() ?? [], ['document_studio' => $studio]));
        return $form;
    }

    public function sanitize(array $payload): array
    {
        $fields = ['destinataire', 'entrepriseDestinataire', 'formation', 'inscriptions', 'dateEmission', 'devise', 'montantHtCents', 'montantTvaCents', 'montantTtcCents', 'status', 'lignes', 'remiseGlobalePourcent', 'remiseGlobaleMontantCents', 'note', 'followUp', 'invoiceTerms'];
        unset($payload['_token']);
        if (array_diff(array_keys($payload), $fields)) throw new \DomainException('Le formulaire contient des champs inconnus. Rechargez la page avant de continuer.');
        if (isset($payload['lignes']) && (!is_array($payload['lignes']) || count($payload['lignes']) > 500)) throw new \DomainException('Le brouillon doit contenir au maximum 500 lignes.');
        foreach (['lignes', 'inscriptions', 'followUp', 'invoiceTerms'] as $key) {
            if (isset($payload[$key]) && !is_array($payload[$key])) throw new \DomainException('Le format du formulaire est invalide.');
        }
        foreach ($payload as $key => $value) {
            if (!in_array($key, ['lignes', 'inscriptions', 'followUp', 'invoiceTerms'], true) && is_array($value)) throw new \DomainException('Le format du formulaire est invalide.');
        }
        foreach (['followUp', 'invoiceTerms', 'inscriptions'] as $key) {
            foreach ($payload[$key] ?? [] as $value) if (!is_scalar($value) && $value !== null) throw new \DomainException('Le format du formulaire est invalide.');
        }
        foreach ($payload['lignes'] ?? [] as $row) {
            if (!is_array($row)) throw new \DomainException('Le format d’une ligne est invalide.');
            foreach ($row as $value) if (!is_scalar($value) && $value !== null) throw new \DomainException('Le format d’une ligne est invalide.');
        }
        try { $json = json_encode($payload, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \DomainException('Le brouillon contient des caractères invalides.'); }
        if (strlen($json) > 1_000_000) throw new \DomainException('Le brouillon est trop volumineux.');
        return $payload;
    }

    /** @return list<string> */
    public function issues(FormInterface $form): array
    {
        $issues = [];
        foreach ($form->getErrors(true) as $error) {
            $label = $error->getOrigin()?->getConfig()->getOption('label');
            $issues[] = (is_string($label) ? ltrim($label, '*').' : ' : '').$error->getMessage();
        }
        /** @var Facture $invoice */
        $invoice = $form->getData();
        if (($invoice->getDestinataire() !== null) === ($invoice->getEntrepriseDestinataire() !== null)) $issues[] = 'Choisissez un seul destinataire : une entreprise ou une personne.';
        if ($invoice->getLignes()->isEmpty()) $issues[] = 'Ajoutez au moins une prestation détaillée.';
        if ($invoice->getDevise() !== 'EUR') $issues[] = 'La facture doit être établie en euros.';
        if ($invoice->getDateEmission()->format('Y-m-d') > (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d')) $issues[] = 'La date d’émission ne peut pas être future. Conservez le brouillon jusqu’à cette date.';
        $latest = $this->em->getConnection()->fetchOne('SELECT MAX(date_emission) FROM facture WHERE entite_id = ?', [$invoice->getEntite()->getId()]);
        if ($latest && substr($latest, 0, 10) > $invoice->getDateEmission()->format('Y-m-d')) $issues[] = 'La date d’émission précède celle de la dernière facture de votre organisme.';
        return array_values(array_unique([...$issues, ...InvoiceReadiness::issues($invoice)]));
    }

    public function reviewToken(FactureBrouillon $draft, Facture $invoice): string
    {
        // Includes contact details, legal terms and document layout: an external edit also invalidates review.
        return hash_hmac('sha256', json_encode([
            $draft->getId(), $draft->getVersion(), $draft->getPayload(), InvoiceSnapshot::draft($invoice),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->secret);
    }

    public function payloadFromForm(FormInterface $form): array
    {
        $payload = [];
        foreach ($form as $name => $child) {
            if ($name === '_token') continue;
            $payload[$name] = $child->getConfig()->getCompound() ? $this->payloadFromForm($child) : $child->getViewData();
        }
        return $payload;
    }

    public function summary(FormInterface $form): array
    {
        /** @var Facture $invoice */
        $invoice = $form->getData();
        $errors = count($form->getErrors(true));
        return [
            'customerLabel' => $invoice->getDestinataireLabel(),
            'title' => $invoice->getFormation()?->getTitre() ?: ($invoice->getLignes()->first() ? $invoice->getLignes()->first()->getLabel() : 'Prestations à compléter'),
            'totalTtcCents' => $errors ? null : $invoice->getMontantTtcCents(),
            'issueCount' => $errors,
        ];
    }
}
