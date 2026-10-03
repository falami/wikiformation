<?php
namespace App\Controller;

use App\Entity\{Entite, Session, Inscription, Utilisateur};
use App\Enum\{StatusInscription, StatusSession};
use App\Security\Permission\TenantPermission;
use App\Service\Session\{ParticipantQrAccess, ParticipantQrPresenter};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ParticipantQrController extends AbstractController
{
    #[Route('/formateur/{entite}/session/{id}/qr-participants', name: 'app_formateur_session_qr', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    #[IsGranted(TenantPermission::FORMATEUR_DASHBOARD_MANAGE, subject: 'entite')]
    public function trainer(Entite $entite, Session $session, ParticipantQrAccess $access, ParticipantQrPresenter $presenter): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $trainer = $user?->getFormateur();
        if ($session->getEntite()?->getId() !== $entite->getId() || !$trainer
            || $trainer->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateur($trainer)) {
            throw $this->createNotFoundException();
        }
        return $this->roster($entite, $session, $access, $presenter, 'app_formateur_dashboard');
    }

    #[Route('/administrateur/{entite}/session/{id}/qr-participants', name: 'app_administrateur_session_qr', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    #[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
    public function admin(Entite $entite, Session $session, ParticipantQrAccess $access, ParticipantQrPresenter $presenter): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        return $this->roster($entite, $session, $access, $presenter, 'app_administrateur_session_index');
    }

    #[Route('/stagiaire/{entite}/session/{id}/mes-qr-codes', name: 'app_stagiaire_session_qr', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    #[IsGranted(TenantPermission::STAGIAIRE_DASHBOARD_MANAGE, subject: 'entite')]
    public function learner(Entite $entite, Session $session, ParticipantQrAccess $access, ParticipantQrPresenter $presenter, EntityManagerInterface $em): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $inscription = $em->getRepository(Inscription::class)->findOneBy(['session' => $session, 'entite' => $entite, 'stagiaire' => $this->getUser()]);
        if (!$inscription || in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) throw $this->createNotFoundException();
        $cards = $session->getStatus() === StatusSession::CANCELED ? [] : [$presenter->card($access->forInscription($inscription))];
        return $this->page($entite, $session, $cards, 'app_stagiaire_dashboard', true);
    }

    private function roster(Entite $entite, Session $session, ParticipantQrAccess $access, ParticipantQrPresenter $presenter, string $back): Response
    {
        $cards = $session->getStatus() === StatusSession::CANCELED ? [] : array_map($presenter->card(...), $access->roster($session));
        return $this->page($entite, $session, $cards, $back, false);
    }

    private function page(Entite $entite, Session $session, array $cards, string $back, bool $personal): Response
    {
        $response = $this->render('participant_qr/roster.html.twig', compact('entite', 'session', 'cards', 'back', 'personal'));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
