<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Prospect, EmailLog, ProspectInteraction, Utilisateur};
use App\Enum\InteractionChannel;
use App\Enum\ProspectStatus;
use App\Service\Prospect\ProspectFollowUp;
use Symfony\Component\Lock\LockFactory;
use App\Form\Administrateur\ProspectSendEmailType;
use App\Repository\EmailLogRepository;
use App\Repository\EmailTemplateRepository;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/prospection/{prospect}/email', name: 'app_administrateur_prospect_email_', requirements: ['entite' => '\d+', 'prospect' => '\d+'])]
#[IsGranted(TenantPermission::PROSPECT_EMAIL_MANAGE, subject: 'entite')]
final class ProspectEmailController extends AbstractController
{
  public function __construct(
    private EM $em,
    private EmailTemplateRepository $templates,
    private EmailLogRepository $logs,
    private MailerManager $mailer,
    private LockFactory $locks,
  ) {}

  private function guardOwnership(Entite $entite, Prospect $prospect): void
  {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
  }

  private function idemKey(): string
  {
    return bin2hex(random_bytes(16));
  }

  #[Route('/modal', name: 'modal', methods: ['GET'])]
  public function modal(Entite $entite, Prospect $prospect): Response
  {
    $this->guardOwnership($entite, $prospect);

    $tpls = $this->templates->findActiveForProspects($entite);

    $form = $this->createForm(ProspectSendEmailType::class, null, [
      'templates' => $tpls,
    ]);

    $email = $prospect->getEmail()
      ?: $prospect->getLinkedUser()?->getEmail()
      ?: '';

    $form->get('toEmail')->setData($email);

    $form->get('subject')->setData(($entite->getNom() ?? 'Wiki Formation') . ' — ' . ($prospect->getPrenom() ?? ''));
    $form->get('idemKey')->setData($this->idemKey());

    return $this->render('administrateur/prospects/_email_modal.html.twig', [
      'entite' => $entite,
      'p' => $prospect,
      'emailForm' => $form->createView(),
    ]);
  }

  #[Route('/preview', name: 'preview', methods: ['POST'])]
  public function preview(Entite $entite, Prospect $prospect, Request $request): JsonResponse
  {
    $this->guardOwnership($entite, $prospect);

    $to      = trim((string)$request->request->get('to', ''));
    $tplId   = (int)$request->request->get('templateId', 0);
    $subject = (string)$request->request->get('subject', '');
    $message = (string)$request->request->get('message', '');

    $tpl = $tplId ? $this->templates->find($tplId) : null;
    if ($tplId && (!$tpl || !$tpl->isActive() || $tpl->getEntite()?->getId() !== $entite->getId())) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite'   => $entite,
      'prospect' => $prospect,
      'message'  => $message,
    ];

    // ✅ subject dynamique depuis DB (Twig string)
    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl?->getSubject(), $ctx));
    $finalSubject = trim($subject) !== '' ? trim($subject) : $renderedSubject;
    if ($finalSubject === '') $finalSubject = 'Message';

    // ✅ body dynamique depuis DB (Twig string) si tu stockes body dans ta table
    $renderedBody = $tpl?->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl?->getBodyHtml(), $ctx)
      : nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    // ✅ Template email générique prospect
    $html = $this->renderView('emails/prospect_generic_send.html.twig', [
      'entite'   => $entite,
      'prospect' => $prospect,
      'subject'  => $finalSubject,
      'message'  => $renderedBody, // peut contenir HTML
      'to'       => $to,
    ]);

    return $this->json([
      'ok' => true,
      'subject' => $finalSubject,
      'html' => $this->renderView('administrateur/prospects/_email_preview.html.twig', [
        'html' => $html,
        'to' => $to ?: null,
        'subject' => $finalSubject,
      ]),
    ]);
  }

  #[Route('/send', name: 'send', methods: ['POST'])]
  public function send(Entite $entite, Prospect $prospect, Request $request): JsonResponse
  {
    $this->guardOwnership($entite, $prospect);
    if (!$this->isCsrfTokenValid('send_email_prospect_' . $entite->getId() . '_' . $prospect->getId(), $request->request->get('_token'))) {
      return $this->json(['ok' => false, 'error' => 'Votre page a expiré. Actualisez-la avant de réessayer.'], 403);
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $to      = trim((string)$request->request->get('to', ''));
    $tplId   = (int)$request->request->get('templateId', 0);
    $subjectOverride = trim((string)$request->request->get('subject', ''));
    $message = (string)$request->request->get('message', '');
    $idemKey = trim((string)$request->request->get('idemKey', ''));

    if ($to === '' || mb_strlen($to) > 180 || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return $this->json(['ok' => false, 'error' => 'Email destinataire invalide.'], 400);
    }
    if (mb_strlen($subjectOverride) > 200 || preg_match('/[\r\n]/', $subjectOverride) || mb_strlen($message) > 50000) {
      return $this->json(['ok' => false, 'error' => 'Objet limité à 200 caractères sur une ligne, message à 50 000 caractères.'], 400);
    }
    if (!$tplId && ($subjectOverride === '' || trim($message) === '')) {
      return $this->json(['ok' => false, 'error' => 'Renseignez un objet et un message.'], 400);
    }
    $followupDays = $request->request->getInt('followupDays', 0);
    if (!in_array($followupDays, [0, 3, 7, 14, 30], true)) {
      return $this->json(['ok' => false, 'error' => 'Délai de relance invalide.'], 400);
    }
    if ($idemKey === '' || mb_strlen($idemKey) > 64) {
      return $this->json(['ok' => false, 'error' => 'Clé d’envoi invalide.'], 400);
    }

    $lock = $this->locks->createLock('prospect-mail-'.$entite->getId().'-'.$prospect->getId().'-'.$idemKey, 300);
    if (!$lock->acquire()) return $this->json(['ok' => false, 'error' => 'Cet envoi est déjà en cours.'], 409);
    try {
    // A persisted pending log prevents a second send after a transport timeout.

    $existing = $this->logs->findOneBy([
      'entite' => $entite,
      'prospect' => $prospect,
      'idemKey' => $idemKey,
    ]);
    if ($existing) {
      if ($existing->getStatus() !== 'SENT') return $this->json(['ok' => false, 'error' => 'Cet envoi a échoué ou son résultat est incertain. Vérifiez l’historique et votre messagerie avant de préparer un nouvel envoi.'], 409);
      return $this->json([
        'ok' => true,
        'logId' => $existing->getId(),
        'viewUrl' => $this->generateUrl('app_administrateur_prospect_email_log_show', [
          'entite' => $entite->getId(),
          'prospect' => $prospect->getId(),
          'id' => $existing->getId(),
        ]),
        'idempotent' => true,
      ]);
    }

    $tpl = $tplId ? $this->templates->find($tplId) : null;
    if ($tplId && (!$tpl || !$tpl->isActive() || $tpl->getEntite()?->getId() !== $entite->getId())) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite'   => $entite,
      'prospect' => $prospect,
      'message'  => $message,
    ];

    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl?->getSubject(), $ctx));
    $subject = $subjectOverride !== '' ? $subjectOverride : $renderedSubject;
    if ($subject === '') $subject = 'Message';
    if (mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) return $this->json(['ok' => false, 'error' => 'L’objet du modèle doit tenir sur une ligne de 200 caractères maximum.'], 400);

    $renderedBody = $tpl?->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl?->getBodyHtml(), $ctx)
      : nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    $html = $this->renderView('emails/prospect_generic_send.html.twig', [
      'entite'   => $entite,
      'prospect' => $prospect,
      'subject'  => $subject,
      'message'  => $renderedBody,
      'to'       => $to,
    ]);

    $text = trim(strip_tags($html));

    $log = (new EmailLog())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setProspect($prospect)
      ->setTemplate($tpl)
      ->setToEmail($to)
      ->setSubject($subject)
      ->setBodyHtmlSnapshot($html)
      ->setIdemKey($idemKey)
      ->setStatus('PENDING');

    $this->em->persist($log);
    $this->em->flush();

    try {
      $this->mailer->sendHtml($entite, $to, $subject, $html, $text);
      $log->setStatus('SENT');
      $log->setSentAt(new \DateTimeImmutable());
    } catch (\Throwable $e) {
      $log->setStatus('FAILED');
      $log->setErrorMessage('Échec de l’envoi (' . $e::class . '). Vérifiez la configuration de messagerie.');
      $this->em->flush();
      return $this->json(['ok' => false, 'error' => 'L’envoi a échoué. Vérifiez la configuration de messagerie ou contactez le support.'], 500);
    }

    $interaction = (new ProspectInteraction())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setProspect($prospect)
      ->setActor($this->getUser())
      ->setOccurredAt(new \DateTimeImmutable())
      ->setChannel(InteractionChannel::EMAIL)
      ->setTitle(mb_substr('Email envoyé : ' . $subject, 0, 120))
      ->setContent("À : {$to}\nModèle : " . ($tpl?->getName() ?? $tpl?->getCode() ?? 'Message personnalisé'))
      ->setEmailLog($log);

    $this->em->persist($interaction);

    if (ProspectFollowUp::isOpen($prospect)) {
      if ($prospect->getStatus() === ProspectStatus::NEW) $prospect->setStatus(ProspectStatus::CONTACTED);
      if ($followupDays > 0) $prospect->setNextActionAt((new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify('+'.$followupDays.' days')->setTime(9, 0));
    }
    $prospect->touch();
    $this->em->flush();

    return $this->json([
      'ok' => true,
      'logId' => $log->getId(),
      'viewUrl' => $this->generateUrl('app_administrateur_prospect_email_log_show', [
        'entite' => $entite->getId(),
        'prospect' => $prospect->getId(),
        'id' => $log->getId(),
      ]),
    ]);
    } finally {
      $lock->release();
    }
  }

  #[Route('/log/{id}', name: 'log_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function logShow(Entite $entite, Prospect $prospect, EmailLog $log): Response
  {
    $this->guardOwnership($entite, $prospect);

    if ($log->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($log->getProspect()?->getId() !== $prospect->getId()) throw $this->createNotFoundException();

    return $this->render('administrateur/prospects/email_log_show.html.twig', [
      'entite' => $entite,
      'p' => $prospect,
      'log' => $log,
    ]);
  }
}
