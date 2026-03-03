<?php

// src/Controller/VerifyEmailController.php
namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use App\Repository\UtilisateurRepository;

final class VerifyEmailController extends AbstractController
{
    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(
        Request $request,
        EntityManagerInterface $em,
        VerifyEmailHelperInterface $verifyEmailHelper,
        UtilisateurRepository $users
    ): Response {
        $userId = $request->query->get('id'); // id vient de l’URL signée

        if (!$userId) {
            throw $this->createNotFoundException('Aucun identifiant utilisateur fourni.');
        }

        $user = $users->find($userId);
        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé.');
        }

        try {
            // ✅ Nouvelle API: valide directement la requête signée
            $verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                (string) $user->getId(),
                $user->getEmail()
            );

            $user->setIsVerified(true);
            $em->flush();

            $this->addFlash('success', 'Votre adresse e-mail a été confirmée.');
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou expiré.');
        }

        return $this->redirectToRoute('app_login');
    }
}
