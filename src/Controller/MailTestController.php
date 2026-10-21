<?php
// src/Controller/MailTestController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Response;

class MailTestController extends AbstractController
{
    #[Route('/_mail-test', name: 'app_mail_test', methods: ['POST'], env: 'dev')]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_SUPER_ADMIN')]
    #[\Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid('mail_test', tokenKey: '_token')]
    public function __invoke(MailerInterface $mailer): Response
    {
        try {
            $email = (new Email())
                ->from('contact@wikiformation.fr')   // même adresse que le compte SMTP
                ->to('jeroen30@hotmail.fr')      // ou ton adresse perso
                ->subject('Test Symfony Mailer')
                ->text('Hello, ceci est un test Symfony Mailer (sync).')
                ->html('<p>Hello, ceci est un <strong>test</strong> Symfony Mailer (sync).</p>');

            $mailer->send($email);

            return new Response('OK: email envoyé (sync)');
        } catch (\Throwable $e) {
            return new Response('Le transport de messagerie a refusé le test.', 502);
        }
    }
}
