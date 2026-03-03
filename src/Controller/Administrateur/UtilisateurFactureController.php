<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, Facture, EmailLog, ProspectInteraction, UtilisateurEntite};
use App\Enum\InteractionChannel;
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/utilisateur/{id}/facture', name: 'app_administrateur_utilisateur_facture_', requirements: ['entite' => '\d+', 'id' => '\d+'])]
#[IsGranted(TenantPermission::UTILISATEUR_FACTURE_MANAGE, subject: 'entite')]
final class UtilisateurFactureController extends AbstractController
{
  public function __construct(
    private readonly EM $em,
    private readonly MailerManager $mailer,
    private readonly PdfManager $pdf,
  ) {}

  private function guardEntite(Entite $entite, Utilisateur $u): void
  {
    $ok = (int)$this->em->createQueryBuilder()
      ->select('COUNT(ue.id)')
      ->from(UtilisateurEntite::class, 'ue')
      ->andWhere('ue.entite = :e')
      ->andWhere('ue.utilisateur = :u')
      ->setParameter('e', $entite)
      ->setParameter('u', $u)
      ->getQuery()
      ->getSingleScalarResult();

    if ($ok <= 0) throw $this->createNotFoundException();
  }

  private function assertFactureForUser(Entite $entite, Utilisateur $u, Facture $facture): void
  {
    if ($facture->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }

    // ✅ Adapte selon ton modèle : facture->getUtilisateur() / getDestinataireUser() etc.
    if (method_exists($facture, 'getUtilisateur') && $facture->getDestinataire()?->getId() !== $u->getId()) {
      throw $this->createAccessDeniedException('Cette facture n’est pas rattachée à cet utilisateur.');
    }
  }

  private function idemKey(): string
  {
    return bin2hex(random_bytes(16));
  }

  private function defaultSubject(Entite $entite, Facture $facture): string
  {
    $num = $facture->getNumero() ?: ('#' . $facture->getId());
    return sprintf('Votre facture %s — %s', $num, $entite->getNom() ?? 'Wiki Formation');
  }

  #[Route('/{facture}/modal', name: 'modal', methods: ['GET'], requirements: ['facture' => '\d+'])]
  public function modal(Entite $entite, Utilisateur $u, Facture $facture): Response
  {
    $this->guardEntite($entite, $u);
    $this->assertFactureForUser($entite, $u, $facture);

    return $this->render('administrateur/utilisateur/_doc_email_modal.html.twig', [
      'entite'   => $entite,
      'u'        => $u,
      'docType'  => 'facture',
      'docId'    => $facture->getId(),
      'docLabel' => 'Facture',
      'docNumber' => $facture->getNumero() ?: ('#' . $facture->getId()),
      'to'       => $u->getEmail() ?? '',
      'subject'  => $this->defaultSubject($entite, $facture),
      'idemKey'  => $this->idemKey(),
      'defaultMessage' => "Bonjour {$u->getPrenom()},\n\nVeuillez trouver votre facture en pièce jointe.\n\nCordialement,\n" . ($entite->getNom() ?? ''),
      'attachPdfDefault' => true,
      'previewUrl' => $this->generateUrl('app_administrateur_utilisateur_facture_preview', [
        'entite'  => $entite->getId(),
        'id'      => $u->getId(),
        'facture' => $facture->getId(),
      ]),
      'sendUrl' => $this->generateUrl('app_administrateur_utilisateur_facture_send', [
        'entite'  => $entite->getId(),
        'id'      => $u->getId(),
        'facture' => $facture->getId(),
      ]),
    ]);
  }

  #[Route('/{facture}/preview', name: 'preview', methods: ['POST'], requirements: ['facture' => '\d+'])]
  public function preview(Entite $entite, Utilisateur $u, Facture $facture, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);
    $this->assertFactureForUser($entite, $u, $facture);

    $to      = trim((string)$request->request->get('to', $u->getEmail() ?? ''));
    $subject = trim((string)$request->request->get('subject', $this->defaultSubject($entite, $facture)));
    $message = (string)$request->request->get('message', '');

    $html = $this->renderView('emails/user_facture_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'facture' => $facture,
      'subject' => $subject ?: $this->defaultSubject($entite, $facture),
      'message' => $message,
      'to'     => $to,
    ]);

    $html = preg_replace('#<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>#i', '', (string)$html);

    return $this->json([
      'ok' => true,
      'subject' => $subject ?: $this->defaultSubject($entite, $facture),
      'html' => $this->renderView('administrateur/utilisateur/_doc_email_preview.html.twig', [
        'to' => $to ?: null,
        'subject' => $subject ?: $this->defaultSubject($entite, $facture),
        'html' => $html,
      ]),
    ]);
  }

  #[Route('/{facture}/send', name: 'send', methods: ['POST'], requirements: ['facture' => '\d+'])]
  public function send(Entite $entite, Utilisateur $u, Facture $facture, Request $request): JsonResponse
  {
    $this->guardEntite($entite, $u);
    $this->assertFactureForUser($entite, $u, $facture);
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $to        = trim((string)$request->request->get('to', ''));
    $subject   = trim((string)$request->request->get('subject', '')) ?: $this->defaultSubject($entite, $facture);
    $message   = (string)$request->request->get('message', '');
    $attachPdf = (bool)$request->request->get('attachPdf', true);
    $idemKey   = trim((string)$request->request->get('idemKey', ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return $this->json(['ok' => false, 'error' => 'Email destinataire invalide.'], 400);
    }
    if ($idemKey === '' || mb_strlen($idemKey) > 64) {
      return $this->json(['ok' => false, 'error' => 'Clé d’envoi invalide.'], 400);
    }

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

    $html = $this->renderView('emails/user_facture_send.html.twig', [
      'entite' => $entite,
      'utilisateur' => $u,
      'facture' => $facture,
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
          'FACTURE_%s-%s.pdf',
          ($facture->getNumero() ?: $facture->getId()),
          (new \DateTimeImmutable())->format('YmdHis')
        );

        $pdfPath = $this->pdf->renderToFile('pdf/facture.html.twig', [
          'entite'  => $entite,
          'facture' => $facture,
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

    $interaction = (new ProspectInteraction())
      ->setCreateur($user)
      ->setUtilisateur($u)
      ->setEntite($entite)
      ->setActor($this->getUser())
      ->setOccurredAt(new \DateTimeImmutable())
      ->setChannel(InteractionChannel::INVOICE)
      ->setTitle('Facture envoyée : ' . ($facture->getNumero() ?: ('#' . $facture->getId())))
      ->setContent(
        "À : {$to}\n" .
          "Facture : " . ($facture->getNumero() ?: ('#' . $facture->getId())) . "\n" .
          ($attachPdf ? ("Pièce jointe : " . ($pdfFilename ?? 'facture.pdf')) : "Pièce jointe : non")
      )
      ->setEmailLog($log)
      ->setFacture($facture);

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
}
