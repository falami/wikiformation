<?php

// src/Service/EmailVerifier.php
namespace App\Service;

use App\Entity\Utilisateur;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

final class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
    ) {}

    public function sendEmailConfirmation(Utilisateur $user): void
    {
        $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),          // ← string attendu
            $user->getEmail(),
            ['id' => $user->getId()]          // param de la route
        );

        $from = new Address('no-reply@wikiformation.fr', 'Wikiformation');

        $email = (new TemplatedEmail())
            ->from($from)
            ->sender($from)                   // header Sender
            ->returnPath('no-reply@wikiformation.fr')
            ->to($user->getEmail())
            ->subject('Confirmez votre adresse e-mail')
            ->htmlTemplate('emails/verifieMail.html.twig')
            ->context([
                'verifyUrl'             => $signature->getSignedUrl(),
                'expiresAtMessageKey'   => $signature->getExpirationMessageKey(),
                'expiresAtMessageData'  => $signature->getExpirationMessageData(),
                'user'                  => $user,
            ]);

        $this->mailer->send($email);
    }
}
