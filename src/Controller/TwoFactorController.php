<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\TwoFactor\EmailChallenge;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class TwoFactorController extends AbstractController
{
    #[Route('/2fa/resend', name: 'app_2fa_resend', methods: ['POST'])]
    public function resend(Request $request, TokenStorageInterface $tokens, EmailChallenge $challenge): Response
    {
        $token = $tokens->getToken();
        if (!$token instanceof TwoFactorTokenInterface || !$token->getUser() instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('2fa_resend', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Formulaire expiré.');
        }
        $message = $challenge->send($token->getUser());
        $this->addFlash($message === '' ? 'success' : 'warning', $message ?: 'Un nouveau code a été envoyé. Seul ce dernier code est valable.');
        return $this->redirectToRoute('2fa_login');
    }
}
