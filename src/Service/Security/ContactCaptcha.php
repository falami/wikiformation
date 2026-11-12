<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/** Session-bound, expiring, single-use challenges; no answer is sent to the browser. */
final class ContactCaptcha
{
    private const KEY = 'public_contact_captcha';

    public function __construct(private readonly RequestStack $requests) {}

    public function create(): array
    {
        $session = $this->requests->getSession();
        $challenges = array_filter($session->get(self::KEY, []), static fn(array $c): bool => $c['expires'] >= time());
        $challenges = array_slice($challenges, -49, null, true);
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $id = bin2hex(random_bytes(24));
        $challenges[$id] = ['answer' => (string) ($a + $b), 'expires' => time() + 1800];
        $session->set(self::KEY, $challenges);

        return ['id' => $id, 'question' => sprintf('Vérification anti-spam : combien font %d + %d ?', $a, $b)];
    }

    public function verify(string $id, string $answer): bool
    {
        $session = $this->requests->getSession();
        $challenges = $session->get(self::KEY, []);
        $challenge = $challenges[$id] ?? null;
        unset($challenges[$id]);
        $session->set(self::KEY, $challenges);

        return $challenge !== null && $challenge['expires'] >= time()
            && hash_equals($challenge['answer'], trim($answer));
    }
}
