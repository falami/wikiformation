<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\Utilisateur;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => 'login_email'])]
final class EmailCodeProvider implements TwoFactorProviderInterface, TwoFactorFormRendererInterface
{
    public function __construct(
        private readonly EmailChallenge $challenge,
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
        private readonly Environment $twig,
    ) {}

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();
        if (!$user instanceof Utilisateur) return false;
        $this->challenge->begin($user);
        return true;
    }

    public function prepareAuthentication(object $user): void
    {
        if (!$user instanceof Utilisateur) throw new \LogicException('Unsupported account.');
        $message = $this->challenge->send($user);
        if ($message !== '') $this->requests->getSession()->getFlashBag()->add('warning', $message);
    }

    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        return $user instanceof Utilisateur && $this->challenge->verify($user, $authenticationCode);
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface { return $this; }

    public function renderForm(Request $request, array $templateVars): Response
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof Utilisateur) throw new \LogicException('Missing account.');
        return new Response($this->twig->render('security/two_factor.html.twig', $templateVars + [
            'maskedEmail' => $this->challenge->maskedEmail($user),
        ]), 200, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
