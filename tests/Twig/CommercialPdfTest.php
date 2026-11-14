<?php

declare(strict_types=1);
namespace App\Tests\Twig;

use App\Entity\{Devis, Entite, Entreprise, Facture, LigneDevis, LigneFacture};
use App\Enum\{DevisStatus, FactureStatus};
use App\Service\Billing\{InvoiceSnapshot, InvoiceTotals};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CommercialPdfTest extends KernelTestCase
{
    public function testQuoteDescriptionIsEscapedAndMarkersAreNormalizedWithoutChangingStoredContent(): void
    {
        self::bootKernel();
        $issuer=(new Entite())->setNom('Centre exemple');
        $label="Prestation <script>unsafe</script>\n- - Vérification\nTexte complémentaire\n• Préparation";
        $quote=(new Devis())->setEntite($issuer)->setNumero('DEV-TEST')->setDateEmission(new \DateTimeImmutable('2026-11-13'))
            ->setStatus(DevisStatus::SENT)->setDevise('EUR')->setMontantHtCents(6000)->setMontantTvaCents(0)->setMontantTtcCents(6000);
        $line=(new LigneDevis())->setLabel($label)->setQte(1)->setPuHtCents(6000)->setTva(0);
        $quote->addLigne($line);
        $html=self::getContainer()->get('twig')->render('pdf/devis.html.twig',['entite'=>$issuer,'devis'=>$quote,'studioBypass'=>true]);
        $crawler=new Crawler($html);
        self::assertCount(5,$crawler->filter('.lines-table thead th'));
        self::assertCount(0,$crawler->filter('script'));
        self::assertSame($label,$line->getLabel());
        $text=$crawler->filter('.lines-table')->text();
        self::assertStringContainsString('Vérification',$text);
        self::assertStringNotContainsString('- -',$text);
        self::assertStringContainsString('0,00 %',$text);
        self::assertStringNotContainsString('Montant TVA 20%',$html);
        self::assertStringContainsString('60,00 €',$crawler->filter('.totaux')->text());
    }

    public function testQuoteDiscountDisplayMatchesStoredCentRounding(): void
    {
        self::bootKernel();
        $issuer=(new Entite())->setNom('Centre exemple');
        $quote=(new Devis())->setEntite($issuer)->setNumero('DEV-ROUND')->setDateEmission(new \DateTimeImmutable('2026-11-13'))
            ->setStatus(DevisStatus::SENT)->setDevise('EUR')->setMontantHtCents(50)->setMontantTvaCents(0)->setMontantTtcCents(50);
        $line=(new LigneDevis())->setLabel('Remise à arrondir')->setQte(1)->setPuHtCents(101)->setTva(0)->setRemisePourcent(50);
        $quote->addLigne($line);
        $render=fn() => new Crawler(self::getContainer()->get('twig')->render('pdf/devis.html.twig',['entite'=>$issuer,'devis'=>$quote,'studioBypass'=>true]));
        $crawler=$render();
        self::assertSame('0,50 €',$crawler->filter('.lines-table tbody tr')->first()->filter('td')->last()->text());
        self::assertStringContainsString('-0,51 €',$crawler->filter('.line-discount')->text());
        self::assertSame('0,50 €',$crawler->filter('.totaux .val.total')->text());

        $line->setRemisePourcent(null);
        $quote->setRemiseGlobalePourcent(50);
        $crawler=$render();
        $global=$crawler->filter('.totaux tr')->reduce(fn(Crawler $row) => str_contains($row->text(),'Remise globale'));
        self::assertCount(1,$global);
        self::assertSame('-0,51 €',$global->filter('.val')->text());
        self::assertSame('0,50 €',$crawler->filter('.totaux .val.total')->text());
    }

    public function testInvoiceUsesFrozenIdentityAmountsAndTermsAfterLiveRecordsChange(): void
    {
        self::bootKernel();
        $issuer=(new Entite())->setNom('Émetteur à la date d’émission')->setSiret('12345678900012')->setIban('FR761234567890');
        $company=(new Entreprise())->setRaisonSociale('Client à la date d’émission');
        $invoice=(new Facture())->setEntite($issuer)->setEntrepriseDestinataire($company)->setNumero('FAC-TEST')
            ->setDateEmission(new \DateTimeImmutable('2026-11-13'))->setStatus(FactureStatus::DUE)->setDevise('EUR')
            ->setMeta(['invoice_terms'=>['dueDate'=>'2026-12-13','serviceDate'=>'2026-11-10','paymentMeans'=>'Virement bancaire',
                'purchaseOrderReference'=>'BC-2026-42','earlyPaymentDiscount'=>'Aucun escompte',
                'latePaymentPenalty'=>'Taux contractuel de retard','recoveryCompensationCents'=>4000,'legalIdentity'=>'Identité juridique conservée']]);
        $line=(new LigneFacture())->setLabel('Prestation figée')->setQte(1)->setPuHtCents(10000)->setTva(20);
        $invoice->addLigne($line);
        InvoiceTotals::finalizeNewInvoice($invoice); InvoiceSnapshot::capture($invoice);
        $issuer->setNom('Nouvel émetteur')->setIban('NOUVEL-IBAN');
        $company->setRaisonSociale('Nouveau client'); $line->setLabel('Nouveau libellé')->setPuHtCents(90000);
        $html=self::getContainer()->get('twig')->render('pdf/facture.html.twig',['entite'=>$issuer,'facture'=>$invoice,'studioBypass'=>true]);
        $crawler=new Crawler($html);
        self::assertCount(5,$crawler->filter('.lines-table thead th'));
        $text=$crawler->filter('body')->text();
        foreach(['Émetteur à la date d’émission','Client à la date d’émission','Prestation figée','120,00 €','BC-2026-42','Aucun escompte','Taux contractuel de retard','40,00 €','Identité juridique conservée','FR761234567890'] as $expected) self::assertStringContainsString($expected,$text);
        foreach(['Nouvel émetteur','Nouveau client','Nouveau libellé','NOUVEL-IBAN','900,00 €'] as $unexpected) self::assertStringNotContainsString($unexpected,$text);
        self::assertStringContainsString('wf-document-footer',$html);
    }
}
