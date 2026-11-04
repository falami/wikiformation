<?php

declare(strict_types=1);
namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class FlashMessageTest extends TestCase
{
    public function testBillingAlertIsPreservedForModal(): void
    {
        $bag = new FlashBag();
        $payload = ['title' => 'Limite atteinte', 'message' => 'Limite des comptes entreprise atteinte.'];
        $bag->add('billing_limit_modal', $payload);
        $bag->add('success', 'Modification enregistrée.');
        $app = new class($bag) {
            public object $session;
            public function __construct(private FlashBag $bag) { $this->session = (object) ['flashbag' => $bag]; }
            public function flashes(string $type): array { return $this->bag->get($type); }
        };
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]);
        $html = $twig->render('flagMessage.html.twig', ['app' => $app]);
        self::assertStringContainsString('Modification enregistrée.', $html);
        self::assertStringNotContainsString('Array', $html);
        self::assertStringNotContainsString('alert-billing_limit_modal', $html);
        self::assertSame([$payload], $bag->get('billing_limit_modal'));
        self::assertSame([], $bag->get('success'));
    }
}
