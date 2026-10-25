<?php
declare(strict_types=1);
namespace App\Tests\Service\Marketplace;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Purchase, Seller};
use App\Service\Email\MailerManager;
use App\Service\Marketplace\Confirmations;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ConfirmationsTest extends TestCase
{
    public function testDurableConfirmationUsesOnlyFrozenSellerDetailsAndEscapesHtml(): void
    {
        $purchase = new Purchase();$purchase->id = 'order-test';$purchase->paidAt = new \DateTimeImmutable();
        $purchase->buyer = (new Utilisateur())->setEmail('buyer@example.test');
        $purchase->seller = new Seller();$purchase->seller->legalName = 'Current profile must not appear';
        $purchase->seller->address = 'Current address must not appear';
        $purchase->items = [['course' => 1, 'title' => 'Cours <script>alert(1)</script>', 'gross' => 1200]];
        $snapshot = ['name' => 'Vendeur & Associés', 'registration' => '12345678900012', 'address' => "12 rue <img src=x onerror=alert(1)>\n75001 Paris", 'email' => 'seller@example.test', 'taxNumber' => 'FR123456789', 'taxExemption' => 'Mention fiscale archivée'];
        $purchase->consent = ['seller' => $snapshot, 'acceptedAt' => '2026-10-25T10:00:00+01:00', 'version' => 'v1', 'terms' => 'Conditions au jour de la commande'];
        $course = (new ElearningCourse())->setEntite((new Entite())->setNom('Organisme'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn(callable $callback) => $callback());
        $em->expects(self::once())->method('find')->with(ElearningCourse::class, 1)->willReturn($course);
        $em->expects(self::once())->method('flush');
        $router = $this->createMock(UrlGeneratorInterface::class);$router->method('generate')->willReturn('https://example.test/achat/order-test');
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(function (Email $email) use ($snapshot): void {
            $text = $email->getTextBody();$html = $email->getHtmlBody();
            foreach ($snapshot as $value) self::assertStringContainsString($value, $text);
            self::assertStringContainsString('Coordonnées du vendeur au moment de la commande', $text);
            self::assertStringContainsString('Conditions au jour de la commande', $text);
            self::assertStringNotContainsString('Current profile', $text);self::assertStringNotContainsString('Current address', $text);
            self::assertStringNotContainsString('<script>', $html);self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString('&lt;img src=x', $html);self::assertStringContainsString('Vendeur &amp; Associés', $html);
        });
        $mailer = new MailerManager($transport, new Environment(new ArrayLoader()), $router);
        $confirmations = new Confirmations($em, $mailer, $router);
        $confirmations->send($purchase);self::assertNotNull($purchase->confirmationSentAt);
        $confirmations->send($purchase);
    }
}
