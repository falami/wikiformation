<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\Utilisateur;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/** Codes are bound to the password-verified browser session, never stored in plaintext. */
final class EmailChallenge
{
    public const KEY = '_login_email_challenge';
    public const LIFETIME = 600;
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly RateLimiterFactory $twoFactorSendLimiter,
        private readonly RateLimiterFactory $twoFactorVerifyLimiter,
    ) {}

    public function begin(Utilisateur $user): void
    {
        $this->requests->getSession()->set(self::KEY, [
            'identity' => $this->identity($user), 'started' => $this->now(),
            'sent' => 0, 'expires' => 0, 'attempts' => 0, 'hash' => '', 'nonce' => '',
        ]);
    }

    public function send(Utilisateur $user): string
    {
        $session = $this->requests->getSession();
        $state = $session->get(self::KEY, []);
        if (!$this->isCurrent($state, $user)) {
            return 'Cette demande a expiré. Revenez à la connexion pour recommencer.';
        }
        if ($state['sent'] > $this->now() - 60) {
            return 'Veuillez patienter une minute entre deux envois.';
        }
        $key = hash_hmac('sha256', (string) $user->getId(), $this->secret);
        if (!$this->twoFactorSendLimiter->create($key)->consume()->isAccepted()) {
            return 'La limite de cinq envois en quinze minutes est atteinte. Réessayez plus tard.';
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $state['nonce'] = bin2hex(random_bytes(32));
        $state['hash'] = hash_hmac('sha256', $state['nonce'] . $code, $this->secret);
        $state['sent'] = $this->now();
        $state['expires'] = $this->now() + self::LIFETIME;
        $state['attempts'] = 0;
        $session->set(self::KEY, $state); // A resend immediately invalidates the previous code.
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('no-reply@wikiformation.fr', 'Wikiformation'))
                ->to((string) $user->getEmail())
                ->subject('Votre code de connexion Wikiformation')
                ->htmlTemplate('security/email/login_code.html.twig')
                ->textTemplate('security/email/login_code.txt.twig')
                ->context(['code' => $code]));
        } catch (TransportExceptionInterface) {
            $state['hash'] = '';
            $session->set(self::KEY, $state);
            // Deliberately omit transport exceptions: they may contain the message/code.
            $this->logger->error('Two-factor email delivery failed.', ['user_id' => $user->getId()]);
            return 'L’e-mail n’a pas pu être envoyé. Réessayez dans une minute. Votre accès reste protégé.';
        }
        return '';
    }

    public function verify(Utilisateur $user, string $code): bool
    {
        $session = $this->requests->getSession();
        $state = $session->get(self::KEY, []);
        $key = hash_hmac('sha256', (string) $user->getId(), $this->secret);
        if (!$this->twoFactorVerifyLimiter->create($key)->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('Trop de tentatives. Veuillez patienter quinze minutes avant de réessayer.');
        }
        if (!$this->isCurrent($state, $user) || !$state['hash'] || $state['expires'] <= $this->now()) {
            throw new CustomUserMessageAuthenticationException('Ce code a expiré ou n’est plus disponible. Demandez un nouveau code.');
        }
        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            throw new CustomUserMessageAuthenticationException('Ce code est bloqué après cinq essais. Demandez un nouveau code.');
        }
        ++$state['attempts'];
        $session->set(self::KEY, $state);
        $code = trim($code);
        if (!preg_match('/^[0-9]{6}$/D', $code) || !hash_equals($state['hash'], hash_hmac('sha256', $state['nonce'] . $code, $this->secret))) {
            throw new CustomUserMessageAuthenticationException('Le code saisi est incorrect. Vérifiez les six chiffres reçus par e-mail.');
        }
        $session->remove(self::KEY); // One use, including concurrent submissions in the same locked PHP session.
        return true;
    }

    public function maskedEmail(Utilisateur $user): string
    {
        [$local, $domain] = explode('@', (string) $user->getEmail(), 2);
        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    private function isCurrent(array $state, Utilisateur $user): bool
    {
        return isset($state['identity'], $state['started'])
            && hash_equals($state['identity'], $this->identity($user))
            && $state['started'] > $this->now() - 1800;
    }

    private function identity(Utilisateur $user): string
    {
        return hash_hmac('sha256', $user->getId() . ':' . $user->getEmail() . ':' . $user->getPassword(), $this->secret);
    }

    private function now(): int { return $this->clock->now()->getTimestamp(); }
}
