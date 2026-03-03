<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, Devis, EmailLog, ProspectInteraction, UtilisateurEntite};
use App\Enum\{InteractionChannel, DevisStatus};
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/utilisateur/{id}/devis', name: 'app_administrateur_utilisateur_devis_', requirements: ['entite' => '\d+', 'id' => '\d+'])]
#[IsGranted(TenantPermission::UTILISATEUR_DEVIS_MANAGE, subject: 'entite')]
final class UtilisateurDevisController extends AbstractController
{
  public function __construct(
    private readonly EM $em,
    private readonly MailerManager $mailer,
    private readonly PdfManager $pdf,
  ) {}

  private function guardEntite(Entite $entite, Utilisateur $u): void
  {
    // ✅ Vérifie que l’utilisateur appartient à l’entité
    $ok = (int)$this->em->createQueryBuilder()
      ->select('COUNT(ue.id)')
      ->from(UtilisateurEntite::class, 'ue')
      ->andWhere('ue.entite = :e')
      ->andWhere('ue.utilisateur = :u')
      ->setParameter('e', $entite)
      ->setParameter('u', $u)
      ->getQuery()
      ->getSingleScalarResult();

    if ($ok <= 0) {
      throw $this->createNotFoundException();
    }
  }

  private function assertDevisForUser(Entite $entite, Utilisateur $u, Devis $devis): void
  {
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    // ✅ Adapte ici selon TON modèle : devis->getUtilisateur() OU devis->getStagiaire() OU via entreprise etc.
    // Hypothèse : $devis->getUtilisateur() existe
    if (method_exists($devis, 'getUtilisateur') && $devis->getDestinataire()?->getId() !== $u->getId()) {
      throw $this->createAccessDeniedException('Ce devis n’est pas rattaché à cet utilisateur.');
    }
  }

  private function idemKey(): string
  {
    return bin2hex(random_bytes(16));
  }

  private function defaultSubject(Entite $entite, Devis $devis): string
  {
    $num = $devis->getNumero() ?: ('#' . $devis->getId());
    return sprintf('Votre devis %s — %s', $num, $entite->getNom() ?? 'Wiki Formation');
  }

  #[Route('/{devis}/modal', name: 'modal', methods: ['GET'], requirements: ['devis' => '\d+'])]
  public function modal(Entite $entite, Utilisateur $u, Devis $devis): Response
  {
    $this->guardEntite($entite, $u);
    $this->assertDevisForUser($entite, $u, $devis);

    return $this->render('administrateur/utilisateur/_doc_email_modal.html.twig', [
      'entite'   => $entite,
      'u'        => $u,
      'docType'  => 'devis',
      'docId'    => $devis->getId(),
      'docLabel' => 'Devis',
      'docNumber' => $devis->getNumero() ?: ('#' . $devis->getId()),
      'to'       => $u->getEmail() ?? '',
      'subject'  => $this->defaultSubject($entite, $devis),
      'idemKey'  => $this->idemKey(),
      'defaultMessage' => "Bonjour {$u->getPrenom()},\n\nVeuillez trouver votre devis en pièce jointe.\n\nCordialement,\n" . ($entite->getNom() ?? ''),
      'attachPdfDefault' => true,
      'previewUrl' => $this->generateUrl('app_administrateur_utilisateur_devis_preview', [
        'entite' => $entite->getId(),
        'id'     => $u->getId(),
        'devis'  => $devis->getId(),
      ]),
      'sendUrl' => $this->generateUrl('app_administrateur_utilisateur_devis_send', [
        'entite' => $entite->getId(),
        'id'     => $u->getId(),
        'devis'  => $devis->getId(),
      ]),
    ]);
  }

  #[Route('/{devis}/preview', name: 'preview', methods: ['POST'], requirements: ['devis' => '\d+'])]
  public function preview(Entite $entite, Utilisateur $u, Devis $devis, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);
    $this->assertDevisForUser($entite, $u, $devis);

    $to      = trim((string)$request->request->get('to', $u->getEmail() ?? ''));
    $subject = trim((string)$request->request->get('subject', $this->defaultSubject($entite, $devis)));
    $message = (string)$request->request->get('message', '');

    $html = $this->renderView('emails/user_devis_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'devis'  => $devis,
      'subject' => $subject ?: $this->defaultSubject($entite, $devis),
      'message' => $message,
      'to'     => $to,
    ]);

    // mini-hardening (évite scripts si un template injecte du JS)
    $html = preg_replace('#<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>#i', '', (string)$html);

    return $this->json([
      'ok' => true,
      'subject' => $subject ?: $this->defaultSubject($entite, $devis),
      'html' => $this->renderView('administrateur/utilisateur/_doc_email_preview.html.twig', [
        'to' => $to ?: null,
        'subject' => $subject ?: $this->defaultSubject($entite, $devis),
        'html' => $html,
      ]),
    ]);
  }

  #[Route('/{devis}/send', name: 'send', methods: ['POST'], requirements: ['devis' => '\d+'])]
  public function send(Entite $entite, Utilisateur $u, Devis $devis, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);
    $this->assertDevisForUser($entite, $u, $devis);
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $to        = trim((string)$request->request->get('to', ''));
    $subject   = trim((string)$request->request->get('subject', '')) ?: $this->defaultSubject($entite, $devis);
    $message   = (string)$request->request->get('message', '');
    $attachPdf = (bool)$request->request->get('attachPdf', true);
    $idemKey   = trim((string)$request->request->get('idemKey', ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return $this->json(['ok' => false, 'error' => 'Email destinataire invalide.'], 400);
    }
    if ($idemKey === '' || mb_strlen($idemKey) > 64) {
      return $this->json(['ok' => false, 'error' => 'Clé d’envoi invalide.'], 400);
    }

    // ✅ Idempotence (scopée entite + toUser)
    $existing = $this->em->getRepository(EmailLog::class)->findOneBy([
      'entite' => $entite,
      'toUser' => $u,
      'idemKey' => $idemKey,
    ]);
    if ($existing) {
      return $this->json([
        'ok' => true,
        'idempotent' => true,
        'logId' => $existing->getId(),
        'viewUrl' => $this->generateUrl('app_administrateur_utilisateur_email_log_show', [
          'entite' => $entite->getId(),
          'id' => $u->getId(),
          'log' => $existing->getId(),
        ]),
      ]);
    }

    $html = $this->renderView('emails/user_devis_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'devis'  => $devis,
      'subject' => $subject,
      'message' => $message,
      'to'     => $to,
    ]);
    $html = preg_replace('#<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>#i', '', (string)$html);
    $text = trim(strip_tags($html));

    $log = (new EmailLog())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setToUser($u)
      ->setProspect(null)
      ->setTemplate(null)
      ->setToEmail($to)
      ->setSubject($subject)
      ->setBodyHtmlSnapshot($html)
      ->setIdemKey($idemKey)
      ->setStatus('PENDING');

    $this->em->persist($log);

    $pdfPath = null;
    $pdfFilename = null;

    try {
      if ($attachPdf) {
        $pdfFilename = sprintf(
          'DEVIS-%s-%s.pdf',
          ($devis->getNumero() ?: $devis->getId()),
          (new \DateTimeImmutable())->format('YmdHis')
        );

        $pdfPath = $this->pdf->renderToFile('pdf/devis.html.twig', [
          'entite' => $entite,
          'devis'  => $devis,
        ], $pdfFilename);
      }

      if ($attachPdf && $pdfPath) {
        $this->mailer->sendHtmlWithFile(
          $entite,
          $to,
          $subject,
          $html,
          $text,
          $pdfPath,
          $pdfFilename,
          'application/pdf'
        );
      } else {
        $this->mailer->sendHtml($entite, $to, $subject, $html, $text);
      }

      $log->setStatus('SENT')->setSentAt(new \DateTimeImmutable());
    } catch (\Throwable $e) {
      $log->setStatus('FAILED')->setErrorMessage($e->getMessage());
      $this->em->flush();
      return $this->json(['ok' => false, 'error' => 'Envoi échoué : ' . $e->getMessage()], 500);
    }

    // ✅ Interaction
    $interaction = (new ProspectInteraction())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setUtilisateur($u)
      ->setActor($this->getUser())
      ->setOccurredAt(new \DateTimeImmutable())
      ->setChannel(InteractionChannel::QUOTE)
      ->setTitle('Devis envoyé : ' . ($devis->getNumero() ?: ('#' . $devis->getId())))
      ->setContent(
        "À : {$to}\n" .
          "Devis : " . ($devis->getNumero() ?: ('#' . $devis->getId())) . "\n" .
          ($attachPdf ? ("Pièce jointe : " . ($pdfFilename ?? 'devis.pdf')) : "Pièce jointe : non")
      )
      ->setEmailLog($log)
      ->setDevis($devis);

    $this->em->persist($interaction);

    // Option : status devis DRAFT -> SENT
    try {
      if (method_exists($devis, 'getStatus') && method_exists($devis, 'setStatus')) {
        if ($devis->getStatus() === DevisStatus::DRAFT) {
          $devis->setStatus(DevisStatus::SENT);
        }
      }
    } catch (\Throwable) {
    }

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
}
