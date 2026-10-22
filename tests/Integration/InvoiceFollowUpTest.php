<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Facture, Utilisateur, Paiement, Avoir};
use App\Service\Billing\{InvoiceFollowUp, InvoiceReminderRunner};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

final class InvoiceFollowUpTest extends KernelTestCase
{
    private array $database;
    private $em;
    private Entite $entity;
    private Utilisateur $user;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('invoice-test@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Invoice');
        $this->entity = (new Entite())->setNom('Test')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($this->user); $this->em->persist($this->entity); $this->em->flush();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function invoice(array $settings = []): Facture
    {
        return (new Facture())->setEntite($this->entity)->setCreateur($this->user)->setDestinataire($this->user)->setNumero('TEST-'.bin2hex(random_bytes(3)))->setDateEmission(new \DateTimeImmutable('2024-01-31'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)->setMeta(['follow_up' => $settings]);
    }
    public function testOutstandingBreakdownAllocatesPartialPaymentsAndCredits(): void
    {
        $invoice = $this->invoice();
        self::assertSame(['ht' => 10000, 'vat' => 2000, 'ttc' => 12000], InvoiceFollowUp::remainingBreakdown($invoice));
        $invoice->addPaiement((new Paiement())->setMontantCents(3000));
        $invoice->addAvoir((new Avoir())->setMontantTtcCents(3000));
        self::assertSame(['ht' => 5000, 'vat' => 1000, 'ttc' => 6000], InvoiceFollowUp::remainingBreakdown($invoice));
        $invoice->addPaiement((new Paiement())->setMontantCents(6000));
        self::assertSame(['ht' => 0, 'vat' => 0, 'ttc' => 0], InvoiceFollowUp::remainingBreakdown($invoice));
    }

    public function testCalendarAndIndependentSettings(): void
    {
        $invoice = $this->invoice(['renewalMonths' => 1]);
        self::assertSame('2024-02-29', InvoiceFollowUp::renewalDate($invoice)->format('Y-m-d'));
        self::assertNull(InvoiceFollowUp::reminderDate($invoice));
        $invoice->setMeta(['follow_up' => ['renewalMonths' => 1, 'renewalsHandled' => 1, 'reminderMonths' => 12]]);
        self::assertSame('2024-03-31', InvoiceFollowUp::renewalDate($invoice)->format('Y-m-d'));
        self::assertSame('2025-01-31', InvoiceFollowUp::reminderDate($invoice)->format('Y-m-d'));
        self::assertSame('2025-02-28', InvoiceFollowUp::anniversary(new \DateTimeImmutable('2024-02-29'), 12)->format('Y-m-d'));
        $invoice->setMeta(['follow_up' => ['reminderMonths' => 1], 'invoice_terms' => ['dueDate' => '2024-04-10']]);
        self::assertSame('2024-04-11', InvoiceFollowUp::reminderDate($invoice)->format('Y-m-d'));
    }
    public function testOutstandingAndTenantIsolation(): void
    {
        $invoice = $this->invoice(['renewalMonths' => 12]);
        $invoice->addPaiement((new Paiement())->setMontantCents(4000));
        $invoice->addAvoir((new Avoir())->setMontantTtcCents(3000));
        // Persist only invoice; in-memory collection is sufficient for monetary assertions.
        self::assertSame(5000, \App\Service\Billing\InvoiceTotals::outstanding($invoice));
        $this->user = $this->em->find(Utilisateur::class, $this->user->getId());
        $other = (new Entite())->setNom('Other')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($other); $this->em->flush();
        $invoice = $this->invoice(['renewalMonths' => 12]);
        $this->em->persist($invoice); $this->em->flush();
        $service = new InvoiceFollowUp($this->em);
        self::assertCount(1, $service->rows($this->entity, new \DateTimeImmutable('2026-01-01'))['unpaid']);
        self::assertCount(1, $service->rows($this->entity, new \DateTimeImmutable('2026-01-01'))['renewals']);
        self::assertSame(['unpaid' => [], 'renewals' => []], $service->rows($other));
        $invoice->setStatus(\App\Enum\FactureStatus::CANCELED);
        self::assertNull(InvoiceFollowUp::renewalDate($invoice));
        self::assertNull(InvoiceFollowUp::reminderDate($invoice));
    }
    public function testSendingOnceAndMonthlySpacingWithMockTransport(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $invoice = $this->invoice(['reminderMonths' => 1]);
        $this->em->persist($invoice); $this->em->flush();
        $runner = self::getContainer()->get(InvoiceReminderRunner::class);
        $now = new \DateTimeImmutable('2026-10-19 09:00:00');
        self::assertSame('sent', $runner->send($invoice, $now));
        self::assertSame('ignored', $runner->send($invoice, $now));
        self::assertSame('2026-11-19', InvoiceFollowUp::reminderDate($invoice)->format('Y-m-d'));
    }
    public function testSettingsPageValidationTenantBoundaryAndOccurrenceIdempotency(): void
    {
        $this->user->setRoles(['ROLE_SUPER_ADMIN'])->setEntite($this->entity);
        $this->em->persist((new \App\Entity\UtilisateurEntite())->setCreateur($this->user)->setUtilisateur($this->user)->setEntite($this->entity)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('follow-up-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entity)->setStatus('active')->setPlan($plan));
        $invoice = $this->invoice(['renewalMonths' => 12]);
        $this->em->persist($invoice); $this->em->flush();
        $client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->loginUser($this->user);
        $url = '/fr/administrateur/'.$this->entity->getId().'/facture/'.$invoice->getId().'/suivi';
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Enregistrer les réglages')->form();
        $client->submit($form, ['invoice_follow_up[renewalMonths]' => '12', 'invoice_follow_up[reminderMonths]' => '1']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $invoice = $this->em->find(Facture::class, $invoice->getId());
        $this->em->refresh($invoice);
        self::assertSame(1, InvoiceFollowUp::settings($invoice)['reminderMonths']);
        $this->user = $this->em->find(Utilisateur::class, $this->user->getId());
        $other = (new Entite())->setNom('Other')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($other); $this->em->flush();
        $client->request('GET', '/fr/administrateur/'.$other->getId().'/facture/'.$invoice->getId().'/suivi');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, ['invoice_follow_up' => ['renewalMonths' => '999', 'reminderMonths' => '1', '_token' => 'invalid']]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $invoice = $this->em->find(Facture::class, $invoice->getId());
        $this->em->refresh($invoice);
        self::assertSame(12, InvoiceFollowUp::settings($invoice)['renewalMonths']);
        $twig = self::getContainer()->get('twig');
        $stack = self::getContainer()->get('request_stack');
        $stack->push($client->getRequest());
        try {
            $html = $twig->render('administrateur/dashboard/_invoice_follow_up.html.twig', ['entite' => $this->entity, 'invoiceFollowUp' => (new InvoiceFollowUp($this->em))->rows($this->entity)]);
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('renewal_'.$invoice->getId())->getValue();
            $client->getRequest()->getSession()->save();
        } finally { $stack->pop(); }
        self::assertStringContainsString('Préparer une facture', $html);
        $handled = '/fr/administrateur/'.$this->entity->getId().'/facture/'.$invoice->getId().'/renouvellement-traite';
        $data = ['_token' => $token, 'occurrence' => '2025-01-31'];
        $client->request('POST', $handled, $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->request('POST', $handled, $data);
        $invoice = $this->em->find(Facture::class, $invoice->getId());
        $this->em->refresh($invoice);
        self::assertSame(1, InvoiceFollowUp::settings($invoice)['renewalsHandled']);
        $client->request('GET', $url);
        $stack->push($client->getRequest());
        try {
            $batchToken = self::getContainer()->get('security.csrf.token_manager')->getToken('invoice_reminders_'.$this->entity->getId())->getValue();
            $client->getRequest()->getSession()->save();
        } finally { $stack->pop(); }
        $batchUrl = '/fr/administrateur/'.$this->entity->getId().'/factures/relancer-selection';
        $client->request('POST', $batchUrl, ['_token' => 'invalid', 'invoices' => [$invoice->getId()]]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $batchUrl, ['_token' => $batchToken, 'invoices' => [$invoice->getId(), 999999]]);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'Every selected ID must be authorized before sending any mail.');
        $client->request('POST', $batchUrl, ['_token' => $batchToken, 'invoices' => []]);
        self::assertSame(400, $client->getResponse()->getStatusCode());

    }

    public function testManualReminderWithoutScheduleUsesBrandedMailAndCooldown(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function ($email): bool {
            self::assertStringContainsString('Un point sur votre règlement', $email->getHtmlBody());
            self::assertStringContainsString('120,00 EUR', $email->getHtmlBody());
            self::assertStringContainsString('Bonjour', $email->getTextBody());
            return true;
        }));
        self::getContainer()->set(MailerInterface::class, $mailer);
        $invoice = $this->invoice();
        $this->em->persist($invoice); $this->em->flush();
        $runner = self::getContainer()->get(InvoiceReminderRunner::class);
        $now = new \DateTimeImmutable('2026-10-19 12:00');
        self::assertSame('ignored', $runner->send($invoice, $now));
        self::assertSame('sent', $runner->send($invoice, $now, true));
        self::assertSame('ignored', $runner->send($invoice, $now->modify('+23 hours'), true));
        self::assertTrue(InvoiceReminderRunner::canSendManually($invoice, $now->modify('+24 hours')));
        $invoice->setStatus(\App\Enum\FactureStatus::CANCELED);
        self::assertFalse(InvoiceReminderRunner::canSendManually($invoice, $now->modify('+24 hours')));
    }

    public function testUncertainTransportIsNotAutomaticallyRetried(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('transport uncertain'));
        self::getContainer()->set(MailerInterface::class, $mailer);
        $invoice = $this->invoice(['reminderMonths' => 1]);
        $this->em->persist($invoice); $this->em->flush();
        $runner = self::getContainer()->get(InvoiceReminderRunner::class);
        self::assertSame('unknown', $runner->send($invoice, new \DateTimeImmutable('2026-10-19')));
        self::assertSame('ignored', $runner->send($invoice, new \DateTimeImmutable('2026-12-19')));
    }
}
