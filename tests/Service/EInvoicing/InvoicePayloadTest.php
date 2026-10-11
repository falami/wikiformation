<?php
declare(strict_types=1);
namespace App\Tests\Service\EInvoicing;
use App\Entity\{Entite, Entreprise, Facture, LigneFacture};
use App\Service\Billing\{InvoiceTotals, InvoiceSnapshot};
use App\Service\EInvoicing\InvoicePayload;
use PHPUnit\Framework\TestCase;

final class InvoicePayloadTest extends TestCase
{
    public function testStructuredPayloadKeepsIssuedAmountsAndIdentityIncludingDiscounts(): void
    {
        $invoice = $this->invoice(); $invoice->setRemiseGlobalePourcent(10);
        InvoiceTotals::finalizeNewInvoice($invoice); InvoiceSnapshot::capture($invoice);
        $payload = new InvoicePayload();
        self::assertSame([], $payload->issues($invoice));
        $data = $payload->build($invoice, '0225:987654321_SERVICE');
        self::assertSame('FAC-E1-2026-0001', $data['number']);
        self::assertSame('900.00', $data['totals']['total_without_vat']);
        self::assertSame('180.00', $data['totals']['total_vat_amount']['value']);
        self::assertSame('1080.00', $data['totals']['amount_due_for_payment']);
        self::assertSame('100.00', $data['lines'][0]['allowances'][0]['amount']);
        self::assertSame('987654321_SERVICE', $data['buyer']['electronic_address']['value']);
        self::assertSame('432', $data['vat_point_date_code']);
        self::assertSame('Bâtiment A', $data['seller']['postal_address']['address_line2']);
        $invoice->getEntite()->setNom('Autre nom')->setAdresse('Autre adresse');
        $invoice->getEntrepriseDestinataire()->setSiret('11111111100012');
        self::assertSame($data, $payload->build($invoice, '0225:987654321_SERVICE'));
        $this->expectException(\DomainException::class);
        $payload->build($invoice, '0225:111111111');
    }
    public function testHistoricalAndUnsupportedInvoicesNeverBecomeAFalseCompliantTransmission(): void
    {
        $invoice = $this->invoice();
        self::assertStringContainsString('historique', implode(' ', (new InvoicePayload())->issues($invoice)));
        $invoice->getEntrepriseDestinataire()->setPays('Belgique');
        $invoice->getLignes()->first()->setTva(0);
        $meta = $invoice->getMeta(); $meta['invoice_terms']['vatCategory'] = 'E'; $meta['invoice_terms']['vatExemptionReason'] = 'Motif explicite'; $invoice->setMeta($meta);
        InvoiceTotals::finalizeNewInvoice($invoice); InvoiceSnapshot::capture($invoice);
        $issues = implode(' ', (new InvoicePayload())->issues($invoice));
        self::assertStringContainsString('internationales', $issues);
        self::assertStringContainsString('exonérée', $issues);
        $this->expectException(\DomainException::class); (new InvoicePayload())->build($invoice, '0225:987654321');
    }
    public function testVatRoundingMismatchIsFlaggedRatherThanChangingAnIssuedTotal(): void
    {
        $invoice = $this->invoice(); $invoice->getLignes()->first()->setPuHtCents(2)->setQte(1);
        $invoice->addLigne((new LigneFacture())->setLabel('Deuxième prestation')->setPuHtCents(2)->setQte(1)->setTva(20));
        InvoiceTotals::finalizeNewInvoice($invoice); InvoiceSnapshot::capture($invoice);
        self::assertStringContainsString('arrondi', implode(' ', (new InvoicePayload())->issues($invoice)));
    }
    private function invoice(): Facture
    {
        $issuer = (new Entite())->setNom('Organisme')->setSiret('12345678900012')->setAdresse('10 rue de Paris')->setComplement('Bâtiment A')->setCodePostal('75001')->setVille('Paris')->setPays('France')->setNumeroTva('FR12345678901');
        $customer = (new Entreprise())->setRaisonSociale('Client')->setSiret('98765432100012')->setAdresse('20 rue de Lyon')->setCodePostal('69001')->setVille('Lyon')->setPays('France')->setNumeroTVA('FR98765432109');
        return (new Facture())->setEntite($issuer)->setEntrepriseDestinataire($customer)->setNumero('FAC-E1-2026-0001')->setDateEmission(new \DateTimeImmutable('2026-10-08'))
            ->setMeta(['invoice_terms' => ['dueDate' => '2026-11-07', 'serviceDate' => '2026-10-07', 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Pas d’escompte',
                'latePaymentPenalty' => 'Taux contractuel vérifié', 'recoveryCompensationCents' => 4000, 'legalIdentity' => 'Mentions juridiques validées', 'vatCategory' => 'S', 'vatOnDebits' => false]])
            ->addLigne((new LigneFacture())->setLabel('Formation')->setPuHtCents(50000)->setQte(2)->setTva(20));
    }
}
