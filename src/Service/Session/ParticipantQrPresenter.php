<?php
namespace App\Service\Session;

use App\Entity\SessionParticipantAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ParticipantQrPresenter
{
    public function __construct(private ParticipantQrAccess $access, private UrlGeneratorInterface $router, private ParticipantQrSatisfactionTemplate $templates) {}

    public function card(SessionParticipantAccess $participant): array
    {
        $started = ParticipantQrAvailability::hasStarted($participant->getSession());
        $this->templates->forSession($participant->getSession());
        return [
            'available' => $started,
            'name' => $participant->getDisplayName(),
            'guest' => $participant->isGuest(),
            'attendanceUrl' => $started && $participant->getSession()->isEmargementRequis()
                ? $this->router->generate('app_participant_qr_attendance', ['token' => $this->access->token($participant, 'attendance')], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'satisfactionUrl' => $started ? $this->router->generate('app_participant_qr_satisfaction', ['token' => $this->access->token($participant, 'satisfaction')], UrlGeneratorInterface::ABSOLUTE_URL) : null,
        ];
    }
}
