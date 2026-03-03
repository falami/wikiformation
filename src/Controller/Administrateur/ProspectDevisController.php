<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Prospect, Devis, EmailLog, ProspectInteraction, Utilisateur};
use App\Enum\{InteractionChannel, DevisStatus};
use App\Form\Administrateur\DevisSendEmailType;
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/prospection/{prospect}/devis', name: 'app_administrateur_prospect_devis_', requirements: ['entite' => '\d+', 'prospect' => '\d+'])]
#[IsGranted(TenantPermission::PROSPECT_DEVIS_MANAGE, subject: 'entite')]
final class ProspectDevisController extends AbstractController
{
  public function __construct(
    private EM $em,
    private MailerManager $mailer,
    private PdfManager $pdf,
  ) {}

  private function assertScope(Entite $entite, Prospect $prospect): void
  {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
  }

  private function assertDevisForProspect(Entite $entite, Prospect $prospect, Devis $devis): void
  {
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }
    if (!$devis->getProspect() || $devis->getProspect()?->getId() !== $prospect->getId()) {
      throw $this->createAccessDeniedException('Ce devis n’est pas rattaché à ce prospect.');
    }
  }

  private function defaultSubject(Entite $entite, Devis $devis): string
  {
    $num = $devis->getNumero() ?: ('#' . $devis->getId());
    return sprintf('Votre devis %s — %s', $num, $entite->getNom() ?? 'Wiki Formation');
  }

  #[Route('/{devis}/modal', name: 'modal', methods: ['GET'], requirements: ['devis' => '\d+'])]
  public function modal(Entite $entite, Prospect $prospect, Devis $devis): Response
  {
    $this->assertScope($entite, $prospect);
    $this->assertDevisForProspect($entite, $prospect, $devis);

    $idemKey = bin2hex(random_bytes(16));

    $form = $this->createForm(DevisSendEmailType::class);
    $form->get('to')->setData($prospect->getEmail() ?? '');
    $form->get('subject')->setData($this->defaultSubject($entite, $devis));
    $form->get('idemKey')->setData($idemKey);

    return $this->render('administrateur/prospects/_devis_email_modal.html.twig', [
      'entite' => $entite,
      'prospect' => $prospect,
      'devis' => $devis,
      'form' => $form->createView(),
    ]);
  }

  #[Route('/{devis}/preview', name: 'preview', methods: ['POST'], requirements: ['devis' => '\d+'])]
  public function preview(Entite $entite, Prospect $prospect, Devis $devis, Request $request): JsonResponse
  {
    $this->assertScope($entite, $prospect);
    $this->assertDevisForProspect($entite, $prospect, $devis);

    $subject = trim((string)$request->request->get('subject', $this->defaultSubject($entite, $devis)));
    $message = (string)$request->request->get('message', '');

    $html = $this->renderView('emails/prospect_devis_send.html.twig', [
      'entite' => $entite,
      'prospect' => $prospect,
      'devis' => $devis,
      'message' => $message,
    ]);

    return $this->json([
      'ok' => true,
      'subject' => $subject ?: $this->defaultSubject($entite, $devis),
      'html' => $this->renderView('administrateur/prospects/_email_preview.html.twig', [
        'html' => $html,
      ]),
    ]);
  }

  #[Route('/{devis}/send', name: 'send', methods: ['POST'], requirements: ['devis' => '\d+'])]
  public function send(Entite $entite, Prospect $prospect, Devis $devis, Request $request): JsonResponse
  {
    $this->assertScope($entite, $prospect);
    $this->assertDevisForProspect($entite, $prospect, $devis);
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $to = trim((string)$request->request->get('to', ''));
    $subject = trim((string)$request->request->get('subject', ''));
    $message = (string)$request->request->get('message', '');
    $attachPdf = (bool)$request->request->get('attachPdf', true);
    $idemKey = trim((string)$request->request->get('idemKey', ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return $this->json(['ok' => false, 'error' => 'Email destinataire invalide.'], 400);
    }
    if ($subject === '') $subject = $this->defaultSubject($entite, $devis);
    if ($idemKey === '' || mb_strlen($idemKey) > 64) {
      return $this->json(['ok' => false, 'error' => 'Clé d’envoi invalide.'], 400);
    }

    // ✅ Idempotence : si déjà envoyé avec la même clé, on renvoie OK
    $existing = $this->em->getRepository(EmailLog::class)->findOneBy([
      'entite' => $entite,
      'prospect' => $prospect,
      'idemKey' => $idemKey,
    ]);
    if ($existing) {
      return $this->json([
        'ok' => true,
        'idempotent' => true,
        'logId' => $existing->getId(),
        'viewUrl' => $this->generateUrl('app_administrateur_prospect_email_log_show', [
          'entite' => $entite->getId(),
          'prospect' => $prospect->getId(),
          'id' => $existing->getId(),
        ]),
      ]);
    }

    $html = $this->renderView('emails/prospect_devis_send.html.twig', [
      'entite' => $entite,
      'prospect' => $prospect,
      'devis' => $devis,
      'message' => $message,
    ]);
    $text = trim(strip_tags($html));

    $log = (new EmailLog())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setProspect($prospect)
      ->setTemplate(null)
      ->setToEmail($to)
      ->setSubject($subject)
      ->setBodyHtmlSnapshot($html)
      ->setIdemKey($idemKey)
      ->setStatus('PENDING');

    $this->em->persist($log);

    // ✅ PDF : on génère un fichier dans ton pdfOutputDir
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

    // ✅ Interaction “Devis envoyé” (track dans historique)
    $interaction = (new ProspectInteraction())
      ->setProspect($prospect)
      ->setCreateur($user)
      ->setEntite($entite)
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

    // ✅ Option premium: passer le devis en “SENT” si pas déjà accepté/facturé
    try {
      if (method_exists($devis, 'getStatus') && method_exists($devis, 'setStatus')) {
        $st = $devis->getStatus();
        if ($st === DevisStatus::DRAFT) {
          $devis->setStatus(DevisStatus::SENT);
        }
      }
    } catch (\Throwable) {
      // on ignore : pas bloquant
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
  }
}
