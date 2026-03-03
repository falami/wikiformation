<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use Symfony\Component\Mime\Address;
use App\Form\ResetPasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use App\Form\ResetPasswordRequestFormType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResetPasswordHelperInterface $resetPasswordHelper,
    ) {}

    /**
     * Display & process form to request a password reset.
     */
    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            return $this->processSendingPasswordResetEmail(
                $email,
                $mailer
            );
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    /**
     * Confirmation page after a user has requested a password reset.
     */
    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Generate a fake token if the user does not exist or someone hit this page directly.
        // This prevents exposing whether or not a user was found with the given email address or not
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    /**
     * Validates and process the reset URL that the user clicked in their email.
     */
    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, ?string $token = null): Response
    {
        $user = $this->entityManager->getRepository(Utilisateur::class)->findOneBy(['resetToken' => $token]);

        if (!$user || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Token invalide ou expiré.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        // Création du formulaire
        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('password')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $user->setResetToken(null);
            $user->setIsVerified(true);
            $user->setResetTokenExpiresAt(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe a été réinitialisé avec succès.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData, MailerInterface $mailer): RedirectResponse
    {
        $user = $this->entityManager->getRepository(Utilisateur::class)->findOneBy([
            'email' => $emailFormData,
        ]);

        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }


        $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        $expiryMessageKey = $resetToken->getExpirationMessageKey();
        $expiryMessageData = $resetToken->getExpirationMessageData();

        // Générer un token aléatoire
        $resetToken = bin2hex(random_bytes(32));

        // Définir une expiration pour le token (1 heure)
        $expirationDate = new \DateTimeImmutable('+1 hour');

        // Enregistrer le token et l'expiration en base de données
        $user->setResetToken($resetToken);
        $user->setResetTokenExpiresAt($expirationDate);
        $this->entityManager->flush();

        // Générer l'URL de réinitialisation
        $resetTokenUrl = $this->generateUrl('app_reset_password', [
            'token' => $resetToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        // Envoi du mail
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@wikiformation.fr', 'Wikiformation'))
            ->to($user->getEmail())
            ->subject('Demande de réinitialisation de votre mot de passe')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'resetTokenUrl' => $resetTokenUrl,
                'expiryMessageKey' => $expiryMessageKey, // Passe le message d'expiration
                'expiryMessageData' => $expiryMessageData, // Passe les données d'expiration
            ]);

        $mailer->send($email);

        return $this->redirectToRoute('app_check_email');
    }
}
