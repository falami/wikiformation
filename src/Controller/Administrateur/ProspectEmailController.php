<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Prospect, EmailLog, ProspectInteraction, Utilisateur};
use App\Enum\InteractionChannel;
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
    if (!$tpl || $tpl->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite'   => $entite,
      'prospect' => $prospect,
      'message'  => $message,
    ];

    // ✅ subject dynamique depuis DB (Twig string)
    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl->getSubject(), $ctx));
    $finalSubject = trim($subject) !== '' ? trim($subject) : $renderedSubject;
    if ($finalSubject === '') $finalSubject = 'Message';

    // ✅ body dynamique depuis DB (Twig string) si tu stockes body dans ta table
    $renderedBody = $tpl->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl->getBodyHtml(), $ctx)
      : $message;

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
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $to      = trim((string)$request->request->get('to', ''));
    $tplId   = (int)$request->request->get('templateId', 0);
    $subjectOverride = trim((string)$request->request->get('subject', ''));
    $message = (string)$request->request->get('message', '');
    $idemKey = trim((string)$request->request->get('idemKey', ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return $this->json(['ok' => false, 'error' => 'Email destinataire invalide.'], 400);
    }
    if ($tplId <= 0) {
      return $this->json(['ok' => false, 'error' => 'Template manquant.'], 400);
    }
    if ($idemKey === '' || mb_strlen($idemKey) > 64) {
      return $this->json(['ok' => false, 'error' => 'Clé d’envoi invalide.'], 400);
    }

    // ✅ Idempotence
    $existing = $this->logs->findOneBy([
      'entite' => $entite,
      'prospect' => $prospect,
      'idemKey' => $idemKey,
    ]);
    if ($existing) {
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

    $tpl = $this->templates->find($tplId);
    if (!$tpl || $tpl->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite'   => $entite,
      'prospect' => $prospect,
      'message'  => $message,
    ];

    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl->getSubject(), $ctx));
    $subject = $subjectOverride !== '' ? $subjectOverride : $renderedSubject;
    if ($subject === '') $subject = 'Message';

    $renderedBody = $tpl->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl->getBodyHtml(), $ctx)
      : $message;

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

    try {
      $this->mailer->sendHtml($entite, $to, $subject, $html, $text);
      $log->setStatus('SENT');
      $log->setSentAt(new \DateTimeImmutable());
    } catch (\Throwable $e) {
      $log->setStatus('FAILED');
      $log->setErrorMessage($e->getMessage());
      $this->em->flush();
      return $this->json(['ok' => false, 'error' => 'Envoi échoué : ' . $e->getMessage()], 500);
    }

    $interaction = (new ProspectInteraction())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setProspect($prospect)
      ->setActor($this->getUser())
      ->setOccurredAt(new \DateTimeImmutable())
      ->setChannel(InteractionChannel::EMAIL)
      ->setTitle('Email envoyé : ' . $subject)
      ->setContent("À : {$to}\nModèle : " . ($tpl->getName() ?? $tpl->getCode() ?? '—'))
      ->setEmailLog($log);

    $this->em->persist($interaction);

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
