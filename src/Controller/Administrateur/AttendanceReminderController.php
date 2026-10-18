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
        $period = $request->request->get('period');
        $target = $request->request->get('inscription');
        if ($target !== null && (!is_string($target) || !ctype_digit($target) || (int) $target < 1)) throw $this->createNotFoundException();
        $inscriptionId = $target === null ? null : (int) $target;
        $selectedIds=null;
        if ($request->request->get('scope')==='selected') {
            $selectedIds=$request->request->all('inscriptions');
            if (!$selectedIds || count($selectedIds)>500) throw $this->createNotFoundException();
            foreach ($selectedIds as $value) if (!is_string($value) || !ctype_digit($value) || (int)$value<1) throw $this->createNotFoundException();
            $selectedIds=array_values(array_unique(array_map('intval',$selectedIds)));sort($selectedIds);
        }
        $key = ($period ?? 'day').'_'.$kind.'_'.($selectedIds !== null ? hash('sha256',implode(',',$selectedIds)) : ($inscriptionId ?? 'all')).'_attendance_reminder_'.$session->getId().'_'.$date;
        $feedback = ['sent'=>0, 'skipped'=>0, 'failed'=>0];
        if ($request->getSession()->get($key, 0) > time() - 60) {
            $feedback['skipped'] = 1;
            $this->addFlash('warning', 'Une relance vient d’être demandée pour cette journée. Patientez une minute avant un nouvel envoi.');
        } else {
            $request->getSession()->set($key, time());
            try {
                $reasons = []; $deliveries = [];
                $result = $reminder->send($session, $date, $inscriptionId, $kind, $period, $reasons, $selectedIds, $deliveries);
                $feedback = $result + ['lastReminderAt' => $deliveries ? end($deliveries)['at'] : null];
                $em->persist((new AuditLog())->setEntite($entite)->setActor($this->getUser())->setCreateur($this->getUser())->setEvent('ATTENDANCE_REMINDER_SENT')->setCreatedAt(new \DateTimeImmutable())->setPayload(['session' => $session->getId(), 'date' => $date, 'kind' => $kind, 'period' => $period, 'inscription' => $inscriptionId, 'inscriptions'=>$selectedIds, 'deliveries'=>$deliveries] + $result));
                $em->flush();
                $message = sprintf('%d mail(s) confié(s) au service d’envoi, %d échec(s).', $result['sent'], $result['failed']);
                foreach (['complete'=>'déjà complété(s)', 'email'=>'adresse(s) email absente(s) ou invalide(s)', 'access'=>'accès personnel(s) indisponible(s)', 'inactive'=>'inscription(s) inactive(s) ou sans stagiaire'] as $reason=>$label) {
                    if ($reasons[$reason] ?? 0) $message .= sprintf(' %d %s.', $reasons[$reason], $label);
                }
                $this->addFlash(($result['failed'] || !$result['sent']) ? 'warning' : 'success', $message);
            } catch (\DomainException $e) { $feedback['skipped'] = 1; $this->addFlash('warning', $e->getMessage()); }
        }
        if ($request->isXmlHttpRequest()) {
            $messages = $request->getSession()->getFlashBag()->all();
            return $this->json($feedback + ['messages'=>$messages]);
        }
        if ($request->request->get('return') === 'dashboard') return $this->redirectToRoute('app_administrateur_dashboard_index', ['entite' => $entite->getId()]);
        return $this->redirectToRoute('app_administrateur_session_show', ['entite' => $entite->getId(), 'id' => $session->getId()]);
    }
}
