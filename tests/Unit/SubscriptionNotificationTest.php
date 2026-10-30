<?php
namespace App\Tests\Unit;

use App\Entity\Billing\EntiteSubscription;
use App\Service\Billing\SubscriptionNotification;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SubscriptionNotificationTest extends TestCase
{
    private function service(MailerInterface $transport): SubscriptionNotification
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn ($callback) => $callback());
        return new SubscriptionNotification($em, new MailerManager($transport,
            new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates')),
            $this->createMock(UrlGeneratorInterface::class)));
    }

    public function testConfirmedSubscriptionIsSentOnce(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->with(self::callback(static function ($email): bool {
            return $email->getTo()[0]->getAddress() === 'contact@wikiformation.fr'
                && $email->getHtmlTemplate() === 'emails/billing/subscription_notification.html.twig'
                && str_contains($email->getTextBody(), 'sub_confirmed');
        }));
        $service = $this->service($transport);
        $sub = (new EntiteSubscription())->setStripeSubscriptionId('sub_confirmed')->setStatus('active');
        $service->send($sub);
        $service->send($sub);
        self::assertNotNull($sub->getSubscriptionNotifiedAt());
    }

    public function testIncompleteSubscriptionDoesNotSend(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::never())->method('send');
        $sub = (new EntiteSubscription())->setStripeSubscriptionId('sub_pending');
        $this->service($transport)->send($sub);
        self::assertNull($sub->getSubscriptionNotifiedAt());
    }

    public function testFailedDeliveryRemainsRetryable(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->method('send')->willThrowException(new \RuntimeException('Transport unavailable'));
        $sub = (new EntiteSubscription())->setStripeSubscriptionId('sub_retry')->setStatus('active');
        try {
            $this->service($transport)->send($sub);
            self::fail('Delivery failure must propagate to the webhook.');
        } catch (\RuntimeException $error) {
            self::assertSame('Transport unavailable', $error->getMessage());
            self::assertNull($sub->getSubscriptionNotifiedAt());
        }
    }
}
