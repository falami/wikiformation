<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Session, AuditLog};
use App\Security\Permission\TenantPermission;
use App\Service\Session\AttendanceReminder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
final class AttendanceReminderController extends AbstractController
{
    #[Route('/administrateur/{entite}/session/{id}/relance-emargement', name: 'app_administrateur_session_attendance_reminder', methods: ['POST'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    public function send(Entite $entite, Session $session, Request $request, AttendanceReminder $reminder, EntityManagerInterface $em): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('attendance_reminder_'.$session->getId(), $request->request->get('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.');
        $date = (string) $request->request->get('date', '');
        $kind = (string) $request->request->get('kind', 'attendance');
        $target = $request->request->get('inscription');
        if ($target !== null && (!is_string($target) || !ctype_digit($target) || (int) $target < 1)) throw $this->createNotFoundException();
        $inscriptionId = $target === null ? null : (int) $target;
        $key = $kind.'_'.($inscriptionId ?? 'all').'_attendance_reminder_'.$session->getId().'_'.$date;
        if ($request->getSession()->get($key, 0) > time() - 60) {
            $this->addFlash('warning', 'Une relance vient d’être demandée pour cette journée. Patientez une minute avant un nouvel envoi.');
        } else {
            $request->getSession()->set($key, time());
            try {
                $result = $reminder->send($session, $date, $inscriptionId, $kind);
                $em->persist((new AuditLog())->setEntite($entite)->setActor($this->getUser())->setCreateur($this->getUser())->setEvent('ATTENDANCE_REMINDER_SENT')->setCreatedAt(new \DateTimeImmutable())->setPayload(['session' => $session->getId(), 'date' => $date, 'kind' => $kind, 'inscription' => $inscriptionId] + $result));
                $em->flush();
                $this->addFlash($result['failed'] ? 'warning' : 'success', sprintf('%d mail(s) confié(s) au service d’envoi, %d stagiaire(s) sans relance nécessaire ou sans accès/e-mail utilisable, %d échec(s).', $result['sent'], $result['skipped'], $result['failed']));
            } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
        }
        return $this->redirectToRoute('app_administrateur_session_show', ['entite' => $entite->getId(), 'id' => $session->getId()]);
    }
}
