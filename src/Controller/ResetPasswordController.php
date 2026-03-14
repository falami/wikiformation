<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Affiche et traite le formulaire de demande de réinitialisation.
     */
    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $email */
            $email = $form->get('email')->getData();

            if ($email !== null && $email !== '') {
                return $this->processSendingPasswordResetEmail($email, $mailer);
            }

            $this->addFlash('danger', 'Veuillez renseigner une adresse e-mail valide.');
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    /**
     * Page de confirmation après demande de réinitialisation.
     * On reste volontairement vague pour ne pas révéler si l'email existe ou non.
     */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetimeHours' => 1,
        ]);
    }

    /**
     * Affiche et traite le formulaire de changement de mot de passe.
     */
    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        string $token
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->entityManager->getRepository(Utilisateur::class)->findOneBy([
            'resetToken' => $token,
        ]);

        if (!$user || !$this->isResetTokenValid($user, $token)) {
            $this->addFlash('danger', 'Le lien de réinitialisation est invalide ou expiré.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $plainPassword */
            $plainPassword = $form->get('password')->getData();

            if ($plainPassword === null || trim($plainPassword) === '') {
                $this->addFlash('danger', 'Le mot de passe ne peut pas être vide.');

                return $this->render('reset_password/reset.html.twig', [
                    'resetForm' => $form->createView(),
                ]);
            }

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);

            // À conserver uniquement si tu veux valider automatiquement le compte
            // lors d'une réinitialisation de mot de passe.
            if (method_exists($user, 'setIsVerified')) {
                $user->setIsVerified(true);
            }

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
        /** @var Utilisateur|null $user */
        $user = $this->entityManager->getRepository(Utilisateur::class)->findOneBy([
            'email' => $emailFormData,
        ]);

        // Ne jamais révéler si l'utilisateur existe ou non
        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }

        // Génération d'un token sécurisé
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $user->setResetToken($resetToken);
        $user->setResetTokenExpiresAt($expiresAt);

        $this->entityManager->flush();

        $resetUrl = $this->generateUrl(
            'app_reset_password',
            ['token' => $resetToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $entite = null;
        if (method_exists($user, 'getEntite')) {
            $entite = $user->getEntite();
        }

        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@wikiformation.fr', 'Wikiformation'))
            ->to((string) $user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'user' => $user,
                'entite' => $entite,
                'resetUrl' => $resetUrl,
                'expiresAt' => $expiresAt,
            ]);

        $mailer->send($email);

        return $this->redirectToRoute('app_check_email');
    }

    private function isResetTokenValid(Utilisateur $user, string $token): bool
    {
        if ($user->getResetToken() === null || $user->getResetToken() !== $token) {
            return false;
        }

        if ($user->getResetTokenExpiresAt() === null) {
            return false;
        }

        return $user->getResetTokenExpiresAt() > new \DateTimeImmutable();
    }
}