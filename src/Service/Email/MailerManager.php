<?php

namespace App\Service\Email;

use App\Entity\Utilisateur;
use App\Entity\Entite;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

final class MailerManager
{
    private MailerInterface $mailer;
    private Environment $twig;
    private UrlGeneratorInterface $url;

    public function __construct(MailerInterface $mailer, Environment $twig, UrlGeneratorInterface $url)
    {
        $this->mailer = $mailer;
        $this->twig   = $twig;
        $this->url    = $url;
    }

    /* =========================================================================
     * ✅ TES FONCTIONS (inchangées)
     * ========================================================================= */

    public function sendMailContext(string $from, string $to, string $subject, string $template, array $context)
    {
        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        $this->mailer->send($email);

        return null;
    }

    public function sendMailContextWithText(string $from, string $to, string $subject, string $template, array $context, string $text)
    {
        $email = (new TemplatedEmail())
            ->from(new Address($from, 'Wiki Formation'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context)
            ->text($text);

        $this->mailer->send($email);

        return null;
    }

    public function sendMail(string $from, string $to, string $subject, string $template)
    {
        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template);

        $this->mailer->send($email);

        return null;
    }

    /**
     * Mail dédié à la création de compte (stagiaire ou formateur)
     * (conserve ta logique, mais From = par entité si possible)
     */
    public function sendNewAccountEmail(
        Utilisateur $user,
        string $plainPassword,
        Entite $entite,
        bool $isFormateur = false
    ): void {
        $subject = ($isFormateur ? 'Votre compte formateur' : 'Votre compte stagiaire')
            . ' sur ' . $entite->getNom();

        $from = $this->resolveFromAddress($entite);

        $this->sendMailContext(
            $from->getAddress(),
            (string) $user->getEmail(),
            $subject,
            'emails/compte_nouveau.html.twig',
            [
                'user'          => $user,
                'plainPassword' => $plainPassword,
                'entite'        => $entite,
                'isFormateur'   => $isFormateur,
            ]
        );
    }

    /* =========================================================================
     * ✅ AJOUTS nécessaires pour TON ProspectEmailController
     * ========================================================================= */

    /**
     * Rend un contenu twig depuis une STRING (subject / body stockés en DB)
     * Exemple: "Bonjour {{ prospect.prenom }}"
     */
    public function renderTwigString(string $twigString, array $context = []): string
    {
        // createTemplate compile la string twig en template à la volée
        return $this->twig->createTemplate($twigString)->render($context);
    }

    /**
     * Envoi “HTML direct” (pas un fichier twig), avec From/Reply-To basés sur l'Entité.
     * ✅ Correspond à ton appel:
     *   $this->mailer->sendHtml($entite, $to, $subject, $html, strip_tags($html));
     */
    public function sendHtml(
        Entite $entite,
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        ?string $fromOverride = null
    ): void {
        $from = $fromOverride
            ? new Address($fromOverride, $this->resolveFromAddress($entite)->getName() ?: 'Wiki Formation')
            : $this->resolveFromAddress($entite);

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->html($html);

        // Reply-To = même expéditeur (pratique pour répondre depuis Gmail/Outlook)
        $email->replyTo($from);

        if ($text !== null && trim($text) !== '') {
            $email->text($text);
        }

        $this->mailer->send($email);
    }

    /* =========================================================================
     * Helpers internes “par entité”
     * ========================================================================= */

    /**
     * Détermine l'adresse expéditeur par entité.
     * 👉 Tu peux adapter ici au champ EXACT de ton Entite.
     */
    private function resolveFromAddress(?Entite $entite): Address
    {
        $fallbackEmail = 'contact@wikiformation.fr';
        $fallbackName  = 'Wiki Formation';

        $name  = $fallbackName;
        $email = $fallbackEmail;

        if ($entite) {
            if (method_exists($entite, 'getNom') && $entite->getNom()) {
                $name = (string) $entite->getNom();
            }

            // ⚠️ adapte ces getters à TON Entite (je laisse plusieurs possibilités)
            if (method_exists($entite, 'getEmail') && $entite->getEmail()) {
                $email = (string) $entite->getEmail();
            } /*elseif (method_exists($entite, 'getEmailContact') && $entite->getEmailContact()) {
                $email = (string) $entite->getEmailContact();
            } elseif (method_exists($entite, 'getEmailCommercial') && $entite->getEmailCommercial()) {
                $email = (string) $entite->getEmailCommercial();
            } elseif (method_exists($entite, 'getEmailFacturation') && $entite->getEmailFacturation()) {
                $email = (string) $entite->getEmailFacturation();
            }*/
        }

        return new Address($email, $name);
    }


    /**
     * Envoie l'email de réinitialisation mot de passe
     * Prérequis : $user->getResetToken() et $user->getResetTokenExpiresAt() doivent être remplis.
     */
    public function sendResetPassword(Utilisateur $user, Entite $entite): void
    {
        $to = (string) $user->getEmail();
        if ($to === '') {
            // pas d'email => on ne fait rien (évite 500)
            return;
        }

        $token = (string) $user->getResetToken();
        if ($token === '') {
            return;
        }

        $from = $this->resolveFromAddress($entite);

        // ⚠️ adapte le nom de route si besoin (voir plus bas)
        $resetUrl = $this->buildResetPasswordUrl($token);

        $subject = 'Réinitialisation de votre mot de passe - ' . $entite->getNom();

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->replyTo($from)
            ->subject($subject)
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'user' => $user,
                'entite' => $entite,
                'resetUrl' => $resetUrl,
                'expiresAt' => $user->getResetTokenExpiresAt(),
            ]);

        $this->mailer->send($email);
    }

    /**
     * Construit l'URL absolue de reset.
     * 👉 Adapte simplement la route + param si ton système diffère.
     */
    private function buildResetPasswordUrl(string $token): string
    {
        // Ex: route /reset-password/{token}
        // IMPORTANT : remplace 'app_reset_password' et le param 'token'
        // par le nom exact de ta route.
        return $this->url->generate(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }


    /**
     * Envoi HTML avec pièce jointe (PDF devis).
     * - $attachmentContent: contenu binaire (string) du PDF
     * - $filename: nom affiché
     */
    public function sendHtmlWithAttachment(
        Entite $entite,
        string $to,
        string $subject,
        string $html,
        ?string $text,
        string $attachmentContent,
        string $filename = 'devis.pdf',
        string $mime = 'application/pdf',
        ?string $fromOverride = null
    ): void {
        $from = $fromOverride
            ? new Address($fromOverride, $this->resolveFromAddress($entite)->getName() ?: 'Wiki Formation')
            : $this->resolveFromAddress($entite);

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->html($html)
            ->replyTo($from);

        if ($text !== null && trim($text) !== '') {
            $email->text($text);
        }

        // ✅ pièce jointe “en mémoire”
        $email->attach($attachmentContent, $filename, $mime);

        $this->mailer->send($email);
    }

    /**
     * Variante: attacher un fichier depuis un chemin (si tu stockes déjà le PDF sur disque).
     */
    public function sendHtmlWithFile(
        Entite $entite,
        string $to,
        string $subject,
        string $html,
        ?string $text,
        string $filePath,
        string $filename = 'devis.pdf',
        string $mime = 'application/pdf',
        ?string $fromOverride = null
    ): void {
        $from = $fromOverride
            ? new Address($fromOverride, $this->resolveFromAddress($entite)->getName() ?: 'Wiki Formation')
            : $this->resolveFromAddress($entite);

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->html($html)
            ->replyTo($from);

        if ($text !== null && trim($text) !== '') {
            $email->text($text);
        }

        $email->attachFromPath($filePath, $filename, $mime);

        $this->mailer->send($email);
    }


    /**
     * Envoie l'email "Compte créé" + lien de validation (utilise resetToken).
     * Prérequis : $user->getResetToken() et $user->getResetTokenExpiresAt() remplis.
     */
    public function sendAccountCreatedValidation(Utilisateur $user, Entite $entite): void
    {
        $to = (string) $user->getEmail();
        if ($to === '') return;

        $token = (string) $user->getResetToken();
        if ($token === '') return;

        $from = $this->resolveFromAddress($entite);

        $resetUrl = $this->buildResetPasswordUrl($token);

        $subject = 'Votre compte vient d’être créé - ' . ($entite->getNom() ?? 'Wiki Formation');

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->replyTo($from)
            ->subject($subject)
            ->htmlTemplate('emails/account_created_validate.html.twig')
            ->context([
                'user'      => $user,
                'entite'    => $entite,
                'resetUrl'  => $resetUrl,
                'expiresAt' => $user->getResetTokenExpiresAt(),
            ]);

        $this->mailer->send($email);
    }
}
