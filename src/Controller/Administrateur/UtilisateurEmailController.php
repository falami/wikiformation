<?php

// src/Controller/Administrateur/UtilisateurEmailController.php
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, EmailLog, ProspectInteraction, UtilisateurEntite};
use App\Enum\InteractionChannel;
use App\Form\Administrateur\UserSendEmailType;
use App\Repository\EmailLogRepository;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Repository\EmailTemplateRepository;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/utilisateur/{id}/email', name: 'app_administrateur_utilisateur_email_', requirements: ['entite' => '\d+', 'id' => '\d+'])]
#[IsGranted(TenantPermission::UTILISATEUR_EMAIL_MANAGE, subject: 'entite')]
final class UtilisateurEmailController extends AbstractController
{
  public function __construct(
    private EM $em,
    private EmailTemplateRepository $templates,
    private EmailLogRepository $logs,
    private MailerManager $mailer,
    private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  private function guardEntite(Entite $entite, Utilisateur $u): void
  {
    // IMPORTANT : adapte si ton lien est utilisateurEntites
    $ok = $this->em->createQueryBuilder()
      ->select('COUNT(ue.id)')
      ->from(UtilisateurEntite::class, 'ue')
      ->andWhere('ue.entite = :e')
      ->andWhere('ue.utilisateur = :u')
      ->setParameter('e', $entite)
      ->setParameter('u', $u)
      ->getQuery()
      ->getSingleScalarResult();

    if ((int)$ok <= 0) throw $this->createNotFoundException();
  }

  private function idemKey(): string
  {
    return bin2hex(random_bytes(16));
  }

  #[Route('/modal', name: 'modal', methods: ['GET'])]
  public function modal(Entite $entite, Utilisateur $u): Response
  {
    $this->guardEntite($entite, $u);
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $tpls = $this->templates->findActiveForProspects($entite); // ✅ tu peux garder la même méthode
    $form = $this->createForm(UserSendEmailType::class, null, ['templates' => $tpls]);

    $form->get('toEmail')->setData($u->getEmail() ?? '');
    $form->get('subject')->setData(($entite->getNom() ?? 'Wiki Formation') . ' — ' . ($u->getPrenom() ?? ''));
    $form->get('idemKey')->setData($this->idemKey());

    return $this->render('administrateur/utilisateur/_email_modal.html.twig', [
      'entite' => $entite,
      'u' => $u,
      'emailForm' => $form->createView(),


    ]);
  }

  #[Route('/preview', name: 'preview', methods: ['POST'])]
  public function preview(Entite $entite, Utilisateur $u, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);

    $to      = trim((string)$request->request->get('to', ''));
    $tplId   = (int)$request->request->get('templateId', 0);
    $subject = (string)$request->request->get('subject', '');
    $message = (string)$request->request->get('message', '');

    $tpl = $tplId ? $this->templates->find($tplId) : null;
    if (!$tpl || $tpl->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite' => $entite,
      'utilisateur' => $u,
      'message' => $message,
    ];

    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl->getSubject(), $ctx));
    $finalSubject = trim($subject) !== '' ? trim($subject) : $renderedSubject;
    if ($finalSubject === '') $finalSubject = 'Message';

    $renderedBody = $tpl->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl->getBodyHtml(), $ctx)
      : $message;

    // ✅ email wrapper dédié user (ou réutilise ton wrapper prospect si tu veux)
    $html = $this->renderView('emails/user_generic_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'subject' => $finalSubject,
      'message' => $renderedBody,
      'to' => $to,
    ]);

    $html = preg_replace('#<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>#i', '', $html);


    $iframeDoc = $this->renderView('administrateur/utilisateur/_iframe_preview.html.twig', [
      'html' => $html,
    ]);

    return $this->json([
      'ok' => true,
      'subject' => $finalSubject,
      'html' => $iframeDoc,
    ]);
  }

  #[Route('/send', name: 'send', methods: ['POST'])]
  public function send(Entite $entite, Utilisateur $u, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);
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

    // ✅ Idempotence (scopée utilisateur)
    $existing = $this->logs->findOneBy([
      'entite' => $entite,
      'toUser' => $u,
      'idemKey' => $idemKey,
    ]);
    if ($existing) {
      return $this->json([
        'ok' => true,
        'logId' => $existing->getId(),
        'viewUrl' => $this->generateUrl('app_administrateur_utilisateur_email_log_show', [
          'entite' => $entite->getId(),
          'id' => $u->getId(),
          'log' => $existing->getId(),
        ]),
        'idempotent' => true,
      ]);
    }

    $tpl = $this->templates->find($tplId);
    if (!$tpl || $tpl->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Template invalide.'], 400);
    }

    $ctx = [
      'entite' => $entite,
      'utilisateur' => $u,
      'message' => $message,
    ];

    $renderedSubject = trim($this->mailer->renderTwigString((string)$tpl->getSubject(), $ctx));
    $subject = $subjectOverride !== '' ? $subjectOverride : $renderedSubject;
    if ($subject === '') $subject = 'Message';

    $renderedBody = $tpl->getBodyHtml()
      ? $this->mailer->renderTwigString((string)$tpl->getBodyHtml(), $ctx)
      : $message;

    $html = $this->renderView('emails/user_generic_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'subject' => $subject,
      'message' => $renderedBody,
      'to' => $to,
    ]);
    $text = trim(strip_tags($html));

    $log = (new EmailLog())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setToUser($u)
      ->setProspect(null)
      ->setTemplate($tpl)
      ->setToEmail($to)
      ->setSubject($subject)
      ->setBodyHtmlSnapshot($html)
      ->setIdemKey($idemKey)
      ->setStatus('PENDING');

    $this->em->persist($log);

    try {
      $this->mailer->sendHtml($entite, $to, $subject, $html, $text);
      $log->setStatus('SENT')->setSentAt(new \DateTimeImmutable());
    } catch (\Throwable $e) {
      $log->setStatus('FAILED')->setErrorMessage($e->getMessage());
      $this->em->flush();
      return $this->json(['ok' => false, 'error' => 'Envoi échoué : ' . $e->getMessage()], 500);
    }

    // ✅ Historique (rattaché utilisateur)
    $interaction = (new ProspectInteraction())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setUtilisateur($u)
      ->setActor($this->getUser())
      ->setOccurredAt(new \DateTimeImmutable())
      ->setChannel(InteractionChannel::EMAIL)
      ->setTitle('Email envoyé : ' . $subject)
      ->setContent("À : {$to}\nModèle : " . ($tpl->getName() ?? $tpl->getCode() ?? '—'))
      ->setEmailLog($log);

    $this->em->persist($interaction);
    $this->em->flush();

    return $this->json([
      'ok' => true,
      'logId' => $log->getId(),
      'viewUrl' => $this->generateUrl('app_administrateur_utilisateur_email_log_show', [
        'entite' => $entite->getId(),
        'id' => $u->getId(),
        'log' => $log->getId(),
      ]),
    ]);
  }

  #[Route('/log/{log}', name: 'log_show', methods: ['GET'], requirements: ['log' => '\d+'])]
  public function logShow(Entite $entite, Utilisateur $u, EmailLog $log): Response
  {
    $this->guardEntite($entite, $u);
    /** @var Utilisateur $user */
    $user = $this->getUser();
    if ($log->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($log->getToUser()?->getId() !== $u->getId()) throw $this->createNotFoundException();

    // tu peux réutiliser le template d’affichage prospect si tu veux
    return $this->render('administrateur/utilisateur/email_log_show.html.twig', [
      'entite' => $entite,
      'u' => $u,
      'log' => $log,


    ]);
  }
}
