<?php
namespace App\Tests\Service\Billing;

use App\Entity\{Entite, Facture, Inscription, Session, SessionJour};
use App\Service\Billing\InvoicePreferences;
use PHPUnit\Framework\TestCase;

final class InvoicePreferencesTest extends TestCase
{
    public function testDefaultsAndLastTrainingDayPreserveExplicitValues(): void
    {
        $entity = new Entite();
        $invoice = (new Facture())->setEntite($entity)->setDateEmission(new \DateTimeImmutable('2026-10-14'));
        $session = (new Session())->setEntite($entity);
        foreach (['2026-10-20','2026-10-18'] as $date) $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable($date.' 09:00'))->setDateFin(new \DateTimeImmutable($date.' 17:00')));
        $invoice->addInscription((new Inscription())->setSession($session));
        InvoicePreferences::apply($invoice, true);
        $terms = $invoice->getMeta()['invoice_terms'];
        self::assertSame('2026-11-13', $terms['dueDate']);
        self::assertSame('2026-10-20', $terms['serviceDate']);
        self::assertSame('Virement bancaire', $terms['paymentMeans']);
        $terms['serviceDate'] = '2026-10-21'; $terms['paymentMeans'] = 'Chèque';
        $invoice->setMeta(['invoice_terms'=>$terms]);
        InvoicePreferences::apply($invoice, true);
        self::assertSame($terms, $invoice->getMeta()['invoice_terms']);
        self::assertSame((new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'), InvoicePreferences::serviceDate(new Facture()));
    }
}
