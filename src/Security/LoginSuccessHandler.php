<?php

namespace App\Security;


use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\Utilisateur;
use App\Security\RedirectAfterLogin;

final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly RedirectAfterLogin $redirectAfterLogin,
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $user = $token->getUser();
        if (!$user instanceof Utilisateur) {
            return new RedirectResponse('/'); // ou app_public_home
        }

        return $this->redirectAfterLogin->redirect($request, $user);
    }
}
