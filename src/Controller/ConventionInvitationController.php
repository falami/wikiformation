<?php

declare(strict_types=1);
namespace App\Controller;
use App\Service\Convention\{ConventionInvitation, ConventionPortalSignature};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/signature-convention/{token}', name: 'app_convention_invitation_', requirements: ['token' => '[a-f0-9]{64}'])]
final class ConventionInvitationController extends AbstractController
{
    public function __construct(private ConventionInvitation $invitations, private ConventionPortalSignature $signatures) {}
    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }
    #[Route('', name: 'review', methods: ['GET', 'POST'])]
    public function review(string $token, Request $request): Response
    {
        $invitation = $this->invitations->resolve($token);
        if (!$invitation) return $this->secure($this->render('convention_invitation/review.html.twig', ['invitation' => null], new Response(status: 410)));
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invitation_'.$invitation->getId(), (string)$request->request->get('_token'))) {
                return $this->secure($this->render('convention_invitation/review.html.twig', ['invitation' => $invitation, 'c' => $invitation->getConvention(), 'token' => $token, 'error' => 'Le formulaire a expiré. Veuillez réessayer depuis cette page.'], new Response(status: 403)));
            }
            try {
                if (!$request->request->getBoolean('consent')) throw new \DomainException('Confirmez votre habilitation et votre accord avant de signer.');
                $this->signatures->sign($invitation->getConvention(), $invitation->getInvitedBy(), 'entreprise', (string)$request->request->get('signatureData'),
                    $request->getSession()->get('convention_reviewed_'.$invitation->getConvention()->getId()), $invitation,
                    ['ip' => $request->getClientIp(), 'userAgent' => mb_substr((string)$request->headers->get('User-Agent'), 0, 500)]);
                return $this->secure($this->render('convention_invitation/review.html.twig', ['invitation' => null, 'success' => true]));
            } catch (\DomainException $exception) { $error = $exception->getMessage(); }
        }
        return $this->secure($this->render('convention_invitation/review.html.twig', ['invitation' => $invitation, 'c' => $invitation->getConvention(), 'token' => $token, 'error' => $error]));
    }
    #[Route('/document', name: 'pdf', methods: ['GET'])]
    public function pdf(string $token, Request $request): Response
    {
        $invitation = $this->invitations->resolve($token);
        if (!$invitation) return $this->secure(new Response('Ce lien n’est plus disponible.', 410));
        return $this->secure($this->signatures->preview($invitation->getConvention(), $request));
    }
}
