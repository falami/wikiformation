<?php
namespace App\Service\Session;

use App\Entity\SessionParticipantAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ParticipantQrPresenter
{
    public function __construct(private ParticipantQrAccess $access, private UrlGeneratorInterface $router, private ParticipantQrSatisfactionTemplate $templates) {}

    public function card(SessionParticipantAccess $participant): array
    {
        $this->templates->forSession($participant->getSession());
        return [
            'name' => $participant->getDisplayName(),
            'guest' => $participant->isGuest(),
            'attendanceUrl' => $participant->getSession()->isEmargementRequis()
                ? $this->router->generate('app_participant_qr_attendance', ['token' => $this->access->token($participant, 'attendance')], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'satisfactionUrl' => $this->router->generate('app_participant_qr_satisfaction', ['token' => $this->access->token($participant, 'satisfaction')], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
