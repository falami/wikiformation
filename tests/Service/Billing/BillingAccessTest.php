<?php
namespace App\Tests\Service\Billing;

use App\Entity\{Entite,Utilisateur,UtilisateurEntite,Facture};
use App\Entity\Billing\{EntiteSubscription,FactureCheckout};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Stripe\{ApiRequestor,HttpClient\ClientInterface,HttpClient\CurlClient};

final class BillingAccessTest extends KernelTestCase
{
    private $em;
    private array $env;
    private Utilisateur $user;
    private Entite $mine;
    private Entite $other;
    protected function setUp(): void
    {
        $this->env=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        self::bootKernel();$this->em=self::getContainer()->get('doctrine')->getManager();
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user=(new Utilisateur())->setEmail('billing@example.test')->setPassword('unused')->setPrenom('Billing')->setNom('Test');
        $this->mine=(new Entite())->setNom('Mine')->setPublic(false)->setCreateur($this->user);
        $this->other=(new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user);
        foreach([$this->user,$this->mine,$this->other] as $row)$this->em->persist($row);$this->em->flush();
        $this->user->setEntite($this->mine);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setCreateur($this->user)->setEntite($this->mine)->setRoles(['TENANT_ADMIN']));
        foreach([$this->mine,$this->other] as $entity)$this->em->persist((new EntiteSubscription())->setEntite($entity)->setStatus('active')->setStripeCustomerId('cus_'.$entity->getId()));
        $this->em->flush();
    }
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());parent::tearDown();
        foreach(['ENV','SERVER'] as $i=>$key){if($this->env[$i]===null)unset($GLOBALS['_'.$key]['DATABASE_URL']);else $GLOBALS['_'.$key]['DATABASE_URL']=$this->env[$i];}
    }
    public function testForeignStripePortalAndInvoiceReturnAreDeniedBeforeStripeCall(): void
    {
        $invoice=(new Facture())->setEntite($this->other)->setCreateur($this->user)->setNumero('FAC-PRIVATE')->setDateEmission(new \DateTimeImmutable())->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $checkout=(new FactureCheckout())->setEntite($this->other)->setFacture($invoice)->setStripeCheckoutSessionId('cs_private');
        $this->em->persist($invoice);$this->em->persist($checkout);$this->em->flush();
        $http=$this->createMock(ClientInterface::class);$http->expects(self::never())->method('request');ApiRequestor::setHttpClient($http);
        $client=self::getContainer()->get('test.client');$client->disableReboot();$client->loginUser($this->user);
        foreach(['/fr/administrateur/'.$this->other->getId().'/billing/portal','/fr/paiement/success?session_id=cs_private'] as $url){
            $client->request('GET',$url);self::assertSame(403,$client->getResponse()->getStatusCode(),$url);
        }
    }
    public function testUnauthenticatedBillingReturnsCannotReadCheckout(): void
    {
        $http=$this->createMock(ClientInterface::class);$http->expects(self::never())->method('request');ApiRequestor::setHttpClient($http);
        $client=self::getContainer()->get('test.client');$client->disableReboot();
        $client->catchExceptions(true);$client->request('GET','/fr/billing/success?session_id=cs_private');self::assertSame(302,$client->getResponse()->getStatusCode());
    }
    private function checkoutReturn(bool $authorized, string $customer, string $status = 'complete'): \Symfony\Component\HttpFoundation\Response
    {
        if ($authorized) {
            $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setCreateur($this->user)->setEntite($this->other)->setRoles(['TENANT_ADMIN']));
            $this->em->flush();
        }
        $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $this->other]);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([json_encode([
            'id' => 'cs_return', 'object' => 'checkout.session',
            'client_reference_id' => (string) $sub->getId(), 'customer' => $customer,
            'status' => $status, 'payment_status' => $status === 'complete' ? 'paid' : 'unpaid',
        ]), 200, []]);
        ApiRequestor::setHttpClient($http);
        $client = self::getContainer()->get('test.client');
        $client->disableReboot(); $client->loginUser($this->user);
        $client->request('GET', '/fr/billing/success?session_id=cs_return');
        return $client->getResponse();
    }
    public function testCheckoutReturnUsesAuthorizedPaymentEntityInsteadOfSelectedEntity(): void
    {
        $response = $this->checkoutReturn(true, 'cus_'.$this->other->getId());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/administrateur/'.$this->other->getId().'/billing', $response->getContent());
    }
    public function testCheckoutReturnDeniesForeignEntity(): void
    {
        $response = $this->checkoutReturn(false, 'cus_'.$this->other->getId());
        self::assertSame(403, $response->getStatusCode());
    }
    public function testCheckoutReturnRejectsMismatchedCustomer(): void
    {
        $response = $this->checkoutReturn(true, 'cus_wrong');
        self::assertSame(404, $response->getStatusCode());
    }
    public function testIncompleteCheckoutReturnsToPaymentEntityBilling(): void
    {
        $response = $this->checkoutReturn(true, 'cus_'.$this->other->getId(), 'open');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/administrateur/'.$this->other->getId().'/billing', $response->headers->get('Location'));
    }
}
