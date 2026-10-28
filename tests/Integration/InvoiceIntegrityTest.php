<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Facture, LigneFacture, Paiement, Utilisateur, UtilisateurEntite};
use App\Enum\FactureStatus;
use App\Form\Administrateur\FactureType;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class InvoiceIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Facture $invoice;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        // Invoice fixtures represent already configured issuers; Stripe readiness has dedicated security tests.
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $numbers = $this->createMock(\App\Service\Sequence\FactureNumberGenerator::class);
        $sequence = 0;
        $numbers->method('nextForEntite')->willReturnCallback(static function () use (&$sequence) { return 'FAC-NEW-' . ++$sequence; });
        self::getContainer()->set(\App\Service\Sequence\FactureNumberGenerator::class, $numbers);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('invoice@example.test')->setPassword('unused')->setPrenom('Facturation')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN'])->setAdresse('1 rue Client')->setCodePostal('75001')->setVille('Paris')->setPays('France');
        $this->entite = (new Entite())->setNom('Émetteur')->setCreateur($this->admin)->setPublic(false)->setNumeroTva('FR12345678901')->setSiret('12345678900012')->setAdresse('2 rue Organisme')->setCodePostal('75001')->setVille('Paris')->setPays('France');
        $prefs = (new \App\Entity\EntitePreferences())->setEntite($this->entite)->setCreateur($this->admin)->setInvoicePreferences(['confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Aucun', 'legalIdentity' => 'Identité juridique test', 'vatCategory' => 'S', 'vatOnDebits' => false]);
        $this->entite->setPreferences($prefs); $this->em->persist($prefs);
        $this->other = (new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);
        foreach ([$this->admin, $this->entite, $this->other] as $record) $this->em->persist($record);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('invoice-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->invoice = $this->newInvoice($this->entite, 'FAC-ISSUED-1');
        $this->em->persist($this->invoice);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient());
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testIssuedInvoiceCannotBeRewrittenThroughLegacyEditRoute(): void
    {
        $client = $this->client();
        foreach (['GET', 'POST'] as $method) {
            $client->request($method, $this->url('edit'), ['facture' => ['dateEmission' => '01/01/2000', 'status' => 'canceled', 'montantTtcCents' => '0']]);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
            $persisted = $this->em->getConnection()->fetchAssociative('SELECT numero, status, montant_ttc_cents, date_emission FROM facture WHERE id = ?', [$this->invoice->getId()]);
            self::assertSame('FAC-ISSUED-1', $persisted['numero']);
            self::assertSame('due', $persisted['status']);
            self::assertSame(12000, (int) $persisted['montant_ttc_cents']);
            self::assertStringStartsWith('2026-09-15', $persisted['date_emission']);
        }
    }

    public function testUnpaidAndPaidInvoicesRemainStoredWhenDeletionIsRequested(): void
    {
        $paid = $this->newInvoice($this->entite, 'FAC-PAID-2')->setStatus(FactureStatus::PAID);
        $payment = (new Paiement())->setFacture($paid)->setEntite($this->entite)->setCreateur($this->admin)->setMontantCents(12000);
        $paid->addPaiement($payment);
        $this->em->persist($paid); $this->em->persist($payment); $this->em->flush();
        $client = $this->client();
        foreach ([[$this->invoice, 'due'], [$paid, 'paid']] as [$invoice, $status]) {
            $client->request('GET', $this->url('show', $invoice));
            $stack = self::getContainer()->get('request_stack');
            $stack->push($client->getRequest());
            try { $token = self::getContainer()->get('security.csrf.token_manager')->getToken('facture_delete_' . $invoice->getId())->getValue(); }
            finally { $client->getRequest()->getSession()->save(); $stack->pop(); }
            $client->request('POST', $this->url('delete', $invoice), ['_token' => $token]);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame($status, $this->em->getConnection()->fetchOne('SELECT status FROM facture WHERE id = ?', [$invoice->getId()]));
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ligne_facture WHERE facture_id = ?', [$invoice->getId()]));
        }
        self::assertSame(1, $this->em->getRepository(Paiement::class)->count([]));
    }

    public function testInvoiceActionsDoNotOfferDestructiveControlsAndRejectForeignRecords(): void
    {
        $foreign = $this->newInvoice($this->other, 'FAC-FOREIGN');
        $this->em->persist($foreign); $this->em->flush();
        $client = $this->client();
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_finance_dt_factures', ['entite' => $this->entite->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['recordsFiltered']);
        $actions = end($data['data'][0]);
        self::assertStringNotContainsString('js-dt-delete', $actions);
        self::assertStringContainsString('/avoir/nouveau', $actions);
        $client->catchExceptions(true);
        foreach ([['GET', 'show'], ['GET', 'edit'], ['POST', 'edit'], ['POST', 'delete'], ['GET', 'pdf'], ['GET', 'pdf_acquittee'], ['POST', 'send']] as [$method, $route]) {
            $client->request($method, $this->url($route, $foreign));
            self::assertSame(404, $client->getResponse()->getStatusCode(), $route);
        }
        $client->request('POST', $this->url('delete'), ['_token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCanceledInvoiceCannotProduceAPaidReceipt(): void
    {
        $this->invoice->setStatus(FactureStatus::CANCELED);
        $payment = (new Paiement())->setFacture($this->invoice)->setEntite($this->entite)->setCreateur($this->admin)->setMontantCents(12000);
        $this->invoice->addPaiement($payment); $this->em->persist($payment); $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('pdf_acquittee'));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
    }

    public function testPdfPrintsVatIdentityAndServiceDateWithoutBankOrEnrollment(): void
    {
        $this->invoice->setMeta(['datePrestation' => '2026-09-14']);
        self::assertNull($this->entite->getBanque());
        self::assertTrue($this->invoice->getInscriptions()->isEmpty());
        $html = self::getContainer()->get('twig')->render('pdf/facture.html.twig', ['entite' => $this->entite, 'facture' => $this->invoice]);
        self::assertStringContainsString('FR12345678901', $html);
        self::assertStringContainsString('Date prestation : 14/09/2026', $html);
    }

    public function testCreationFormDoesNotPermitInventingAPaidOrCanceledStatus(): void
    {
        $form = self::getContainer()->get('form.factory')->create(FactureType::class, null, ['entite' => $this->entite, 'csrf_protection' => false]);
        self::assertSame([FactureStatus::DUE], $form->get('status')->getConfig()->getOption('choices'));
        $form->get('status')->submit('paid');
        self::assertFalse($form->get('status')->isSynchronized());
    }

    public function testInvoicePreferencesRequireCsrfAndKeepExplicitConfirmedDefaults(): void
    {
        $client = $this->client();
        $url = self::getContainer()->get('router')->generate('app_administrateur_preferences_facturation', ['entite' => $this->entite->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('input[name="invoice_preferences[_token]"]')->attr('value');
        $data = ['dueDays' => '45', 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Aucun', 'legalIdentity' => 'Identité juridique validée', 'vatCategory' => 'S', 'vatOnDebits' => '0', 'confirmed' => '1'];
        $client->request('POST', $url, ['invoice_preferences' => $data]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(30, $this->entite->getPreferences()->getInvoicePreferences()['dueDays']);
        $client->request('POST', $url, ['invoice_preferences' => $data + ['_token' => $token]]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $settings = json_decode($this->em->getConnection()->fetchOne('SELECT invoice_preferences FROM entite_preferences WHERE entite_id = ?', [$this->entite->getId()]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(45, $settings['dueDays']); self::assertTrue($settings['confirmed']); self::assertFalse($settings['vatOnDebits']);
        self::assertSame($this->admin->getId(), $settings['confirmedBy']); self::assertNotEmpty($settings['confirmedAt']);
    }

    public function testUserInvoiceMailerChecksRecipientAndCsrfBeforeSending(): void
    {
        $unrelated = (new Utilisateur())->setEmail('unrelated@example.test')->setPassword('unused')->setPrenom('Autre')->setNom('Client');
        $this->em->persist($unrelated);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($unrelated)->setEntite($this->entite)->setRoles(['TENANT_STAGIAIRE'])->setStatus('active'));
        $this->em->flush();
        $client = $this->client();
        $url = fn(string $action, Utilisateur $user) => self::getContainer()->get('router')->generate('app_administrateur_utilisateur_facture_' . $action, ['entite' => $this->entite->getId(), 'id' => $user->getId(), 'facture' => $this->invoice->getId()]);
        $crawler = $client->request('GET', $url('modal', $this->admin));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotEmpty($crawler->filter('input[name="_token"]')->attr('value'));
        $client->request('POST', $url('send', $this->admin), ['to' => 'invoice@example.test', 'idemKey' => 'no-token']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\EmailLog::class)->count([]));
        $client->catchExceptions(true);
        $client->request('GET', $url('modal', $unrelated));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAlternativeCheckoutRequiresInvoicePermission(): void
    {
        $learner = (new Utilisateur())->setEmail('learner-invoice@example.test')->setPassword('unused')->setPrenom('Stagiaire')->setNom('Test')->setEntite($this->entite);
        $this->em->persist($learner);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($learner)->setEntite($this->entite)->setRoles(['TENANT_STAGIAIRE'])->setStatus('active'));
        $this->em->flush();
        $client = $this->client();
        $client->loginUser($learner); $client->catchExceptions(true);
        $client->request('POST', self::getContainer()->get('router')->generate('app_administrateur_facture_paiement_checkout', ['entite' => $this->entite->getId(), 'id' => $this->invoice->getId()]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
    }

    public function testNewInvoiceStoresTheSameDiscountedAmountsAsItsPdfAndDashboard(): void
    {
        $client = $this->client();
        $url = self::getContainer()->get('router')->generate('app_administrateur_facture_new', ['entite' => $this->entite->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', $url, ['facture' => [
            '_token' => $crawler->filter('input[name="facture[_token]"]')->attr('value'),
            'destinataire' => $this->admin->getId(), 'dateEmission' => '08/10/2026', 'devise' => 'EUR', 'status' => 'due',
            'montantHtCents' => '999', 'montantTvaCents' => '999', 'montantTtcCents' => '999',
            'remiseGlobaleMontantCents' => '1,00',
            'invoiceTerms' => ['serviceDate' => '2026-10-07'],
            'lignes' => [
                ['label' => 'Formation A', 'qte' => 1, 'puHtCents' => '10,00', 'tvaBp' => 2000],
                ['label' => 'Formation B', 'qte' => 1, 'puHtCents' => '10,00', 'tvaBp' => 550],
            ],
        ]]);
        self::assertSame(302, $client->getResponse()->getStatusCode(), substr(strip_tags($client->getResponse()->getContent()), -2000));
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['numero' => 'FAC-NEW-1']);
        self::assertNotNull($invoice);
        self::assertSame(1900, $invoice->getMontantHtCents());
        self::assertSame(242, $invoice->getMontantTvaCents());
        self::assertSame(2142, $invoice->getMontantTtcCents());
        self::assertSame(2142, $invoice->getTtcTotalCents());
        $html = self::getContainer()->get('twig')->render('pdf/facture.html.twig', ['entite' => $this->entite, 'facture' => $invoice]);
        self::assertMatchesRegularExpression('~Total TTC</td>\s*<td[^>]*>21,42 €</td>~', $html);
        // Register MySQL scalar equivalents only on this disposable SQLite database.
        $pdo = $this->em->getConnection()->getNativeConnection();
        $pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
        $pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$values) => implode('', $values));
        $client->request('GET', $this->url('kpis'), ['yearFilter' => '2026']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $kpis = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(14142, $kpis['ttcCents']); // 120.00 legacy + 21.42 newly issued
        $client->request('POST', $this->url('ajax'), ['search' => ['value' => 'FAC-NEW-1']]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $table = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $table['recordsFiltered']);
        self::assertStringContainsString('21,42', $table['data'][0]['ttc']);
        self::assertStringContainsString('2,42', $table['data'][0]['tva']);
    }

    public function testConvertedQuoteUsesTheSharedCalculationWithoutChangingHistoricalInvoice(): void
    {
        $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-CONVERT')
            ->setMeta(['invoice_terms' => ['serviceDate' => '2026-10-07']])
            ->setDestinataire($this->admin)->setStatus(\App\Enum\DevisStatus::ACCEPTED)->setRemiseGlobalePourcent(10)
            ->setMontantHtCents(9000)->setMontantTvaCents(1800)->setMontantTtcCents(10800);
        $quote->addLigne((new \App\Entity\LigneDevis())->setEntite($this->entite)->setCreateur($this->admin)->setLabel('Formation')
            ->setQte(1)->setPuHtCents(10000)->setTva(20));
        $this->em->persist($quote); $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('show'));
        $token = $this->token($client, 'devis_to_facture_' . $quote->getId());
        $client->request('POST', self::getContainer()->get('router')->generate('app_administrateur_devis_to_facture', ['entite' => $this->entite->getId(), 'id' => $quote->getId()]), ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNull($quote->getFactureCreee(), 'Conversion must open an editable draft before issuance.');
        self::assertNull($this->em->getRepository(Facture::class)->findOneBy(['numero' => 'FAC-NEW-1']));
        $page = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submit($page->selectButton('Valider et émettre la facture')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode(), strip_tags($client->getResponse()->getContent()));
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['numero' => 'FAC-NEW-1']);
        self::assertNotNull($invoice);
        self::assertSame(10800, $invoice->getTtcTotalCents());
        self::assertSame(1800, $invoice->getMontantTvaHorsDeboursCents());
        self::assertSame(1, $invoice->getMeta()['invoice_totals']['version']);
        self::assertSame(12000, $this->em->getConnection()->fetchOne('SELECT montant_ttc_cents FROM facture WHERE id = ?', [$this->invoice->getId()]));
    }

    public function testCanceledInvoiceCannotStartAnyAdministrativeCheckout(): void
    {
        $this->invoice->setStatus(FactureStatus::CANCELED); $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('show'));
        $client->request('POST', $this->url('checkout_start'), ['_token' => $this->token($client, 'facture_checkout_' . $this->invoice->getId())]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
    }

    public function testBothCheckoutRoutesChargeOnlyTheBalanceAfterPaymentsAndCredits(): void
    {
        $this->enableCheckout();
        $amounts = [];
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$amounts) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            if (strtoupper($method) === 'GET') return [json_encode(['id' => basename($url), 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.example.test/'.basename($url)]), 200, []];
            if (str_ends_with($url, '/customers')) return ['{"id":"cus_fixture","object":"customer"}', 200, []];
            self::assertStringEndsWith('/checkout/sessions', $url);
            self::assertCount(1, $params['line_items'], 'Platform commission must not be added as a second customer charge.');
            $amounts[] = $params['line_items'][0]['price_data']['unit_amount'];
            self::assertSame(130 + (int) round(end($amounts) * 0.025), $params['payment_intent_data']['application_fee_amount']);
            $id = 'cs_fixture_' . count($amounts);
            return [json_encode(['id' => $id, 'object' => 'checkout.session', 'url' => 'https://checkout.example.test/' . $id]), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        foreach ([[5000, 0, 7000], [0, 2000, 10000], [5000, 2000, 5000]] as $index => [$payment, $credit, $expected]) {
            $this->admin = $this->em->find(Utilisateur::class, $this->admin->getId());
            $this->entite = $this->em->find(Entite::class, $this->entite->getId());
            $invoice = $this->newInvoice($this->entite, 'FAC-BALANCE-' . $index);
            $this->em->persist($invoice);
            if ($payment) {
                $record = (new Paiement())->setFacture($invoice)->setEntite($this->entite)->setCreateur($this->admin)->setMontantCents($payment);
                $invoice->addPaiement($record); $this->em->persist($record);
            }
            if ($credit) {
                $record = (new \App\Entity\Avoir())->setDateEmission(new \DateTimeImmutable())->setNumero('AV-BALANCE-' . $index)->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents($credit)->setFactureOrigine($invoice);
                $invoice->addAvoir($record); $this->em->persist($record);
            }
            $this->em->flush();
            foreach (['checkout_start', 'paiement_checkout'] as $route) {
                $client->request('GET', $this->url('show', $invoice));
                $token = $this->token($client, 'facture_checkout_' . $invoice->getId());
                $client->request('POST', $this->url($route, $invoice), ['_token' => $token]);
                self::assertSame(302, $client->getResponse()->getStatusCode());
                self::assertStringStartsWith('https://checkout.example.test/', $client->getResponse()->headers->get('Location'));
                self::assertSame($expected, end($amounts));
            }
        }
        self::assertSame([7000, 10000, 5000], $amounts, 'Both entry points must share one payable checkout per invoice.');
        foreach ($this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->findAll() as $checkout) {
            self::assertSame($checkout->getFactureAmountCents(), $checkout->getAmountTotalCents());
            self::assertGreaterThan(0, $checkout->getServiceFeeCents());
        }
    }

    public function testObsoleteCheckoutIsExpiredBeforePublishingTheNewBalance(): void
    {
        $this->enableCheckout();
        $payment = (new Paiement())->setFacture($this->invoice)->setEntite($this->entite)->setCreateur($this->admin)->setMontantCents(5000);
        $credit = (new \App\Entity\Avoir())->setDateEmission(new \DateTimeImmutable())->setNumero('AV-STALE')->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(2000)->setFactureOrigine($this->invoice);
        $this->invoice->addPaiement($payment)->addAvoir($credit);
        $old = (new \App\Entity\Billing\FactureCheckout())->setEntite($this->entite)->setFacture($this->invoice)->setFactureAmountCents(12000)->setAmountTotalCents(12000)
            ->setStripeCheckoutSessionId('cs_old')->setCheckoutUrl('https://checkout.example.test/cs_old');
        foreach ([$payment, $credit, $old] as $record) $this->em->persist($record);
        $this->em->flush();
        $calls = [];
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$calls) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            $calls[] = $url;
            if (str_ends_with($url, '/expire')) return ['{"id":"cs_old","object":"checkout.session","status":"expired"}', 200, []];
            if (strtoupper($method) === 'GET') return [json_encode(['id' => basename($url), 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.example.test/'.basename($url)]), 200, []];
            if (str_ends_with($url, '/customers')) return ['{"id":"cus_fixture","object":"customer"}', 200, []];
            self::assertSame(5000, $params['line_items'][0]['price_data']['unit_amount']);
            return ['{"id":"cs_new","object":"checkout.session","url":"https://checkout.example.test/cs_new"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->request('GET', $this->url('show'));
        $token = $this->token($client, 'facture_checkout_' . $this->invoice->getId());
        $client->request('POST', $this->url('checkout_start'), ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('https://checkout.example.test/cs_new', $client->getResponse()->headers->get('Location'));
        self::assertStringEndsWith('/cs_old/expire', $calls[1]);
        self::assertSame('expired', $this->em->getConnection()->fetchOne('SELECT status FROM billing_facture_checkout WHERE id = ?', [$old->getId()]));
        self::assertSame(5000, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->findOneBy(['stripeCheckoutSessionId' => 'cs_new'])->getFactureAmountCents());
    }

    public function testCheckoutExpirationFailureNeverReturnsTheObsoleteLinkOrCreatesASecondCharge(): void
    {
        $this->enableCheckout();
        $payment = (new Paiement())->setFacture($this->invoice)->setEntite($this->entite)->setCreateur($this->admin)->setMontantCents(5000);
        $this->invoice->addPaiement($payment);
        $old = (new \App\Entity\Billing\FactureCheckout())->setEntite($this->entite)->setFacture($this->invoice)->setFactureAmountCents(12000)->setAmountTotalCents(12000)
            ->setStripeCheckoutSessionId('cs_old')->setCheckoutUrl('https://checkout.example.test/cs_old');
        foreach ([$payment, $old] as $record) $this->em->persist($record);
        $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(static function ($method, $url) {
            self::assertStringEndsWith('/cs_old', $url);
            throw new \RuntimeException('Provider unavailable');
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->request('GET', $this->url('show'));
        $client->request('POST', $this->url('checkout_start'), ['_token' => $this->token($client, 'facture_checkout_' . $this->invoice->getId())]);
        self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
        self::assertSame('created', $this->em->getConnection()->fetchOne('SELECT status FROM billing_facture_checkout WHERE id = ?', [$old->getId()]));
    }

    public function testLegacyCheckoutSurchargeIsExpiredEvenWhenTheInvoicePrincipalIsUnchanged(): void
    {
        $this->enableCheckout();
        $credit = (new \App\Entity\Avoir())->setDateEmission(new \DateTimeImmutable())->setNumero('AV-SURCHARGE')->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(2000)->setFactureOrigine($this->invoice);
        $this->invoice->addAvoir($credit);
        $old = (new \App\Entity\Billing\FactureCheckout())->setEntite($this->entite)->setFacture($this->invoice)->setFactureAmountCents(10000)->setAmountTotalCents(10300)->setServiceFeeCents(300)
            ->setStripeCheckoutSessionId('cs_surcharge')->setCheckoutUrl('https://checkout.example.test/cs_surcharge');
        $this->em->persist($credit); $this->em->persist($old); $this->em->flush();
        $calls = [];
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$calls) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            $calls[] = $url;
            if (str_ends_with($url, '/expire')) return ['{"id":"cs_surcharge","object":"checkout.session","status":"expired"}', 200, []];
            if (strtoupper($method) === 'GET') return [json_encode(['id' => basename($url), 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.example.test/'.basename($url)]), 200, []];
            if (str_ends_with($url, '/customers')) return ['{"id":"cus_fixture","object":"customer"}', 200, []];
            self::assertCount(1, $params['line_items']);
            self::assertSame(10000, $params['line_items'][0]['price_data']['unit_amount']);
            return ['{"id":"cs_principal","object":"checkout.session","url":"https://checkout.example.test/cs_principal"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->request('GET', $this->url('show'));
        $client->request('POST', $this->url('checkout_start'), ['_token' => $this->token($client, 'facture_checkout_' . $this->invoice->getId())]);
        self::assertSame('https://checkout.example.test/cs_principal', $client->getResponse()->headers->get('Location'));
        self::assertStringEndsWith('/cs_surcharge/expire', $calls[1]);
        self::assertSame('expired', $this->em->getConnection()->fetchOne('SELECT status FROM billing_facture_checkout WHERE id = ?', [$old->getId()]));
        self::assertSame(10000, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->findOneBy(['stripeCheckoutSessionId' => 'cs_principal'])->getAmountTotalCents());
    }

    public function testCheckoutPaymentSettlesTheNetAmountAfterAPartialCreditNote(): void
    {
        $this->enableCheckout();
        $credit = (new \App\Entity\Avoir())->setDateEmission(new \DateTimeImmutable())->setNumero('AV-SETTLED')->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(2000)->setFactureOrigine($this->invoice);
        $this->invoice->addAvoir($credit);
        $checkout = (new \App\Entity\Billing\FactureCheckout())->setEntite($this->entite)->setFacture($this->invoice)->setFactureAmountCents(10000)->setAmountTotalCents(10000)->setServiceFeeCents(300)
            ->setStripeCheckoutSessionId('cs_settled')->setCheckoutUrl('https://checkout.example.test/cs_settled')->setPayeurUtilisateur($this->admin);
        $this->em->persist($credit); $this->em->persist($checkout); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(static function ($method, $url) {
            self::assertStringEndsWith('/checkout/sessions/cs_settled', $url);
            return ['{"id":"cs_settled","object":"checkout.session","mode":"payment","currency":"eur","payment_status":"paid","payment_intent":"pi_settled","amount_total":10000}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_facture_checkout_success', ['entite' => $this->entite->getId(), 'session_id' => 'cs_settled']));
        self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
        self::assertSame('paid', $this->em->getConnection()->fetchOne('SELECT status FROM facture WHERE id = ?', [$this->invoice->getId()]));
        self::assertSame(10000, (int) $this->em->getConnection()->fetchOne('SELECT SUM(montant_cents) FROM paiement WHERE facture_id = ?', [$this->invoice->getId()]));
        self::assertSame(0, \App\Service\Billing\InvoiceTotals::outstanding($this->invoice));
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_facture_checkout_success', ['entite' => $this->entite->getId(), 'session_id' => 'cs_settled']));
        self::assertSame(1, $this->em->getRepository(Paiement::class)->count([]));
    }

    public function testFullyCreditedInvoiceCannotCreateAnotherPayment(): void
    {
        $this->enableCheckout();
        $credit = (new \App\Entity\Avoir())->setDateEmission(new \DateTimeImmutable())->setNumero('AV-FULL')->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(12000)->setFactureOrigine($this->invoice);
        $this->invoice->addAvoir($credit); $this->em->persist($credit); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::never())->method('request');
        \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client();
        foreach (['checkout_start', 'paiement_checkout'] as $route) {
            $client->request('GET', $this->url('show'));
            $client->request('POST', $this->url($route), ['_token' => $this->token($client, 'facture_checkout_' . $this->invoice->getId())]);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame($this->url('show'), $client->getResponse()->headers->get('Location'));
        }
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
        self::assertSame('due', $this->em->getConnection()->fetchOne('SELECT status FROM facture WHERE id = ?', [$this->invoice->getId()]), 'Un avoir intégral ne constitue pas un paiement acquitté.');
    }

    public function testInvoiceCheckoutRetryAfterLostResponseKeepsTheSameDurableOperation(): void
    {
        $this->enableCheckout();
        $requests = [];
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$requests) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            self::assertStringEndsWith('/checkout/sessions', $url);
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_facture_checkout'));
            self::assertFalse($this->em->getConnection()->isTransactionActive(), 'Reservation must commit before the provider side effect.');
            $key = array_values(array_filter($headers, static fn ($value) => str_starts_with($value, 'Idempotency-Key:')));
            self::assertCount(1, $key);
            $account = array_values(array_filter($headers, static fn ($value) => str_starts_with($value, 'Stripe-Account:')));
            $requests[] = [$key, $account, $params];
            if (count($requests) === 1) throw new \RuntimeException('Response lost after Stripe created the checkout');
            return ['{"id":"cs_recovered","object":"checkout.session","url":"https://checkout.example.test/cs_recovered"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $manager = self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class);
        try { $manager->createCheckoutForFacture($this->invoice); self::fail('The first response must be lost.'); }
        catch (\RuntimeException $error) { self::assertSame('Response lost after Stripe created the checkout', $error->getMessage()); }
        $this->entite->getConnect()->setStripeAccountId('acct_changed')->setFeeFixedCents(999);
        $this->invoice->setDevise('USD');
        $this->em->flush();
        $checkout = $manager->createCheckoutForFacture($this->invoice);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame('cs_recovered', $checkout->getStripeCheckoutSessionId());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
    }

    public function testOldUncertainInvoiceCheckoutCannotBeRecreatedAfterIdempotencyWindow(): void
    {
        $this->enableCheckout();
        $checkout = (new \App\Entity\Billing\FactureCheckout())->setFacture($this->invoice)->setEntite($this->entite)->setFactureAmountCents(12000)->setAmountTotalCents(12000);
        (new \ReflectionProperty($checkout, 'createdAt'))->setValue($checkout, new \DateTimeImmutable('-25 hours'));
        $this->em->persist($checkout); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::never())->method('request'); \Stripe\ApiRequestor::setHttpClient($http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rapprocher');
        self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
    }

    public function testKnownInvoiceCheckoutOlderThanTwoHoursIsStillReused(): void
    {
        $this->enableCheckout();
        $checkout = (new \App\Entity\Billing\FactureCheckout())->setFacture($this->invoice)->setEntite($this->entite)->setFactureAmountCents(12000)->setAmountTotalCents(12000)
            ->setStripeCheckoutSessionId('cs_existing')->setCheckoutUrl('https://checkout.example.test/cs_existing');
        (new \ReflectionProperty($checkout, 'createdAt'))->setValue($checkout, new \DateTimeImmutable('-3 hours'));
        $this->em->persist($checkout); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(['{"id":"cs_existing","object":"checkout.session","status":"open","url":"https://checkout.example.test/cs_existing"}', 200, []]); \Stripe\ApiRequestor::setHttpClient($http);
        self::assertSame($checkout, self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice));
    }

    public function testExpiredProviderCheckoutCanBeReplacedWithoutItsMissingWebhook(): void
    {
        $this->enableCheckout();
        $old = (new \App\Entity\Billing\FactureCheckout())->setFacture($this->invoice)->setEntite($this->entite)->setFactureAmountCents(12000)->setAmountTotalCents(12000)
            ->setStripeCheckoutSessionId('cs_expired')->setCheckoutUrl('https://checkout.example.test/cs_expired');
        $this->em->persist($old); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method, $url) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            if (strtoupper($method) === 'GET') return ['{"id":"cs_expired","object":"checkout.session","status":"expired"}', 200, []];
            self::assertStringEndsWith('/checkout/sessions', $url);
            return ['{"id":"cs_new","object":"checkout.session","url":"https://checkout.example.test/cs_new"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $new = self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
        self::assertSame('expired', $old->getStatus());
        self::assertSame('cs_new', $new->getStripeCheckoutSessionId());
    }

    public function testCompletedButUnpaidProviderCheckoutCannotCreateAnotherCharge(): void
    {
        $this->enableCheckout();
        $old = (new \App\Entity\Billing\FactureCheckout())->setFacture($this->invoice)->setEntite($this->entite)->setFactureAmountCents(12000)->setAmountTotalCents(12000)
            ->setStripeCheckoutSessionId('cs_processing')->setCheckoutUrl('https://checkout.example.test/cs_processing');
        $this->em->persist($old); $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(['{"id":"cs_processing","object":"checkout.session","status":"complete","payment_status":"unpaid"}', 200, []]);
        \Stripe\ApiRequestor::setHttpClient($http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('en cours de traitement');
        self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
    }

    public function testInvoiceCheckoutRejectsAnOuterTransactionBeforeCallingStripe(): void
    {
        $this->enableCheckout();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::never())->method('request'); \Stripe\ApiRequestor::setHttpClient($http);
        $this->em->beginTransaction();
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('transaction externe');
            self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
        } finally { $this->em->rollback(); }
    }

    public function testNewInvoiceCheckoutRefusesPlatformPaidFeesWithoutReservingOrCharging(): void
    {
        $this->enableCheckout();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url) {
            self::assertSame('get', strtolower($method));
            self::assertStringEndsWith('/accounts/acct_fixture', $url);
            return $this->connectedAccountResponse('application');
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        try {
            self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
            self::fail('Unconfirmed fee allocation must block a new charge.');
        } catch (\App\Exception\StripeFeeConfigurationUnavailable $error) {
            self::assertStringContainsString('frais d’encaissement', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
        self::assertSame(12000, $this->invoice->getMontantTtcCents());
    }

    public function testNewInvoiceCheckoutFreezesMerchantFeePolicyWithoutClientSurcharge(): void
    {
        $this->enableCheckout();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            if (str_ends_with($url, '/accounts/acct_fixture')) return $this->connectedAccountResponse();
            self::assertStringEndsWith('/checkout/sessions', $url);
            self::assertCount(1, $params['line_items']);
            self::assertSame(12000, $params['line_items'][0]['price_data']['unit_amount']);
            self::assertSame(430, $params['payment_intent_data']['application_fee_amount']);
            return ['{"id":"cs_fee_policy","object":"checkout.session","url":"https://checkout.example.test/cs_fee_policy"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        $checkout = self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
        $operation = $this->invoice->getMeta()['stripe_checkout_requests'][(string) $checkout->getId()];
        self::assertSame('acct_fixture', $operation['account']);
        self::assertSame('connected_account', $operation['feePolicy']['standardProcessingFeesPaidBy']);
        self::assertSame(0, $operation['feePolicy']['clientSurchargeCents']);
        self::assertSame(12000, $checkout->getAmountTotalCents());
    }

    private function connectedAccountResponse(string $payer = 'application_express'): array
    {
        return [json_encode(['id' => 'acct_fixture', 'object' => 'account', 'charges_enabled' => true, 'details_submitted' => true,
            'metadata' => ['entite_id' => (string) $this->entite->getId()], 'controller' => ['fees' => ['payer' => $payer]]], JSON_THROW_ON_ERROR), 200, []];
    }

    public function testStripeFeeVerificationFailureDoesNotExposeProviderResponseOrReservePayment(): void
    {
        $this->enableCheckout();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new \RuntimeException('secret-provider-response'));
        \Stripe\ApiRequestor::setHttpClient($http);
        try {
            self::getContainer()->get(\App\Service\Stripe\FactureCheckoutManager::class)->createCheckoutForFacture($this->invoice);
            self::fail('Provider errors must block new payment requests.');
        } catch (\App\Exception\StripeFeeConfigurationUnavailable $error) {
            self::assertStringNotContainsString('secret-provider-response', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\FactureCheckout::class)->count([]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testStripeConnectConfigurationRequiresBillingPermission(): void
    {
        $learner = (new Utilisateur())->setEmail('learner-connect@example.test')->setPassword('unused')->setNom('Learner')->setPrenom('Access');
        $this->em->persist($learner);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($learner)->setEntite($this->entite)->setRoles(['TENANT_STAGIAIRE'])->setStatus('active'));
        $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::never())->method('request'); \Stripe\ApiRequestor::setHttpClient($http);
        $client = $this->client(); $client->loginUser($learner); $client->catchExceptions(true);
        foreach (['index' => 'GET', 'start' => 'POST', 'refresh' => 'GET', 'save' => 'POST'] as $action => $method) {
            $url = self::getContainer()->get('router')->generate('app_administrateur_billing_connect_'.$action, ['entite' => $this->entite->getId()]);
            $client->request($method, $url);
            self::assertSame(403, $client->getResponse()->getStatusCode(), $action);
        }
        self::assertSame(0, $this->em->getRepository(\App\Entity\Billing\EntiteConnect::class)->count([]));
    }

    private function enableCheckout(): void
    {
        $connect = (new \App\Entity\Billing\EntiteConnect())->setStripeAccountId('acct_fixture')->setOnlinePaymentEnabled(true)->setDetailsSubmitted(true)->setChargesEnabled(true)->setFeeFixedCents(130)->setFeePercentBp(250);
        $this->entite->setConnect($connect); $this->em->persist($connect); $this->em->flush();
    }

    private function token(KernelBrowser $client, string $id): string
    {
        $stack = self::getContainer()->get('request_stack');
        $stack->push($client->getRequest());
        try { return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue(); }
        finally { $client->getRequest()->getSession()->save(); $stack->pop(); }
    }

    private function newInvoice(Entite $entite, string $number): Facture
    {
        $invoice = (new Facture())->setEntite($entite)->setCreateur($this->admin)->setNumero($number)->setDateEmission(new \DateTimeImmutable('2026-09-15'))->setDestinataire($this->admin)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $invoice->addLigne((new LigneFacture())->setEntite($entite)->setCreateur($this->admin)->setLabel('Prestation test')->setQte(1)->setPuHtCents(10000)->setTva(20));
        return $invoice;
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $action, ?Facture $invoice = null): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_facture_' . $action, ['entite' => $this->entite->getId(), 'id' => ($invoice ?? $this->invoice)->getId()]);
    }
}
