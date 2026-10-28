<?php
declare(strict_types=1);
namespace App\Tests\Service\Billing;

use App\Entity\{Entite,Facture,Utilisateur,UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription,FactureCheckout};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentReturnStatusTest extends KernelTestCase
{
    public function testOnlyConfirmedPaymentIsPresentedAsPaid(): void
    {
        $database=[$_ENV['DATABASE_URL']??null,$_SERVER['DATABASE_URL']??null];
        $_ENV['DATABASE_URL']=$_SERVER['DATABASE_URL']='sqlite:///:memory:';
        try {
            self::bootKernel();$em=self::getContainer()->get('doctrine')->getManager();
            self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $user=(new Utilisateur())->setEmail('return-status@test.example')->setPassword('unused')->setPrenom('Billing')->setNom('Test');
            $entity=(new Entite())->setNom('Test')->setCreateur($user)->setPublic(false);
            $em->persist($user);$em->persist($entity);$em->flush();$user->setEntite($entity);
            $em->persist((new UtilisateurEntite())->setUtilisateur($user)->setEntite($entity)->setCreateur($user)->setRoles(['TENANT_ADMIN']));
            $em->persist((new EntiteSubscription())->setEntite($entity)->setStatus('active'));
            $invoice=(new Facture())->setEntite($entity)->setCreateur($user)->setNumero('FAC-RETURN')->setDateEmission(new \DateTimeImmutable())->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
            $checkout=(new FactureCheckout())->setEntite($entity)->setFacture($invoice)->setStripeCheckoutSessionId('cs_return_status');
            $em->persist($invoice);$em->persist($checkout);$em->flush();$checkoutId=$checkout->getId();
            $client=self::getContainer()->get('test.client');$client->disableReboot();$client->loginUser($user);
            foreach (['created'=>'Confirmation en attente','expired'=>'Paiement non finalisé','canceled'=>'Paiement non finalisé','completed'=>'Paiement confirmé'] as $status=>$heading) {
                $em->find(FactureCheckout::class,$checkoutId)->setStatus($status);$em->flush();
                $client->request('GET','/fr/paiement/success?session_id=cs_return_status');
                self::assertSame(200,$client->getResponse()->getStatusCode());
                self::assertSame($heading,$client->getCrawler()->filter('h3')->text());
                $body=$client->getResponse()->getContent();
                if ($status==='completed') self::assertStringContainsString('Votre paiement a été confirmé et enregistré sur la facture.',$body);
                else {
                    self::assertStringNotContainsString('Votre paiement a été confirmé et enregistré sur la facture.',$body);
                    self::assertStringNotContainsString('Paiement effectué',$body);
                    self::assertStringNotContainsString('Ton paiement a été enregistré.',$body);
                }
            }
        } finally {
            self::ensureKernelShutdown();
            foreach(['ENV','SERVER'] as $i=>$name){if($database[$i]===null)unset($GLOBALS['_'.$name]['DATABASE_URL']);else $GLOBALS['_'.$name]['DATABASE_URL']=$database[$i];}
        }
    }
}
