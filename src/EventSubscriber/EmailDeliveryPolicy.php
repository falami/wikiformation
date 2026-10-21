<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Applies to every MailerInterface caller, including authentication emails. */
final class EmailDeliveryPolicy implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(default:mail_delivery_from:MAIL_DELIVERY_FROM)%')]
        private readonly string $from,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Symfony renders Twig at priority 0; normalize before transport signing.
        return [MessageEvent::class => ['onMessage', -32]];
    }

    public function onMessage(MessageEvent $event): void
    {
        $email = $event->getMessage();
        if (!$email instanceof Email) return;
        $original = $email->getFrom()[0] ?? null;
        $sender = new Address($this->from, $original?->getName() ?: 'Wiki Formation');
        if (!$email->getReplyTo() && $original && strcasecmp($original->getAddress(), $this->from) !== 0) {
            $email->replyTo($original);
        }
        $email->from($sender)->sender($sender)->returnPath($this->from);
        $event->getEnvelope()->setSender(new Address($this->from));

        if ($email->getTextBody() === null && is_string($html = $email->getHtmlBody())) {
            $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
            // Preserve actionable URLs in the plain-text alternative.
            $html = preg_replace_callback('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', static fn(array $m): string => strip_tags($m[2]).' ('.$m[1].')', $html);
            $html = preg_replace('~<br\s*/?>|</(?:p|div|tr|h[1-6]|li)>~i', "\n", $html);
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $email->text(trim(preg_replace('/\n[\t ]*\n(?:[\t ]*\n)+/', "\n\n", $text)));
        }
    }
}
