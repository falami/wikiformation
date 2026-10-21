<?php

declare(strict_types=1);
namespace App\Tests\Unit;

use App\EventSubscriber\EmailDeliveryPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\{Address, Email};

final class EmailDeliveryPolicyTest extends TestCase
{
    public function testExternalSenderIsAlignedWithoutChangingRecipients(): void
    {
        $mail = (new Email())->from(new Address('rh@example.org', 'Mon organisme'))->to('client@example.net')->bcc('archive@example.net')->subject('Convention')->html('<style>.x{color:red}</style><p>Bonjour &amp; bienvenue</p><a href="https://example.org/document">Consulter</a>');
        $event = new MessageEvent($mail, Envelope::create($mail), 'test');
        $policy = new EmailDeliveryPolicy('contact@wikiformation.fr');
        $policy->onMessage($event);
        $policy->onMessage($event);
        self::assertSame('contact@wikiformation.fr', $mail->getFrom()[0]->getAddress());
        self::assertSame('Mon organisme', $mail->getFrom()[0]->getName());
        self::assertSame('rh@example.org', $mail->getReplyTo()[0]->getAddress());
        self::assertCount(1, $mail->getReplyTo());
        self::assertSame('contact@wikiformation.fr', $event->getEnvelope()->getSender()->getAddress());
        self::assertCount(2, $event->getEnvelope()->getRecipients());
        self::assertStringContainsString('Bonjour & bienvenue', $mail->getTextBody());
        self::assertStringContainsString('https://example.org/document', $mail->getTextBody());
        self::assertStringNotContainsString('color:red', $mail->getTextBody());
    }

    public function testExplicitReplyAndTextAndAttachmentArePreserved(): void
    {
        $mail = (new Email())->from('old@example.org')->replyTo('billing@example.org')->to('client@example.net')->text('Texte métier')->attach('document', 'facture.pdf', 'application/pdf');
        $policy = new EmailDeliveryPolicy('contact@wikiformation.fr');
        $policy->onMessage(new MessageEvent($mail, Envelope::create($mail), 'test'));
        self::assertSame('billing@example.org', $mail->getReplyTo()[0]->getAddress());
        self::assertSame('Texte métier', $mail->getTextBody());
        self::assertCount(1, $mail->getAttachments());
        self::assertSame('contact@wikiformation.fr', $mail->getReturnPath()->getAddress());
    }
}
