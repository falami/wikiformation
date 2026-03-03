<?php
// src/Controller/Administrateur/BpfController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Service\Bpf\BpfCalculator;
use App\Service\Pdf\PdfManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/bpf')]
#[IsGranted(TenantPermission::BPF_MANAGE, subject: 'entite')]
final class BpfController extends AbstractController
{
  public function __construct(
    private BpfCalculator $bpf,
    private PdfManager $pdfManager,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'app_administrateur_bpf_index', methods: ['GET'])]
  public function index(Entite $entite, Request $request): Response
  {

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $year = (int)($request->query->get('year') ?? (new \DateTimeImmutable('now'))->format('Y'));
    $data = $this->bpf->computeForYear($entite, $year);

    return $this->render('administrateur/bpf/index.html.twig', [

      'year' => $year,
      'bpf' => $data,
      'entite' => $entite,
    ]);
  }

  #[Route('/pdf', name: 'app_administrateur_bpf_pdf', methods: ['GET'])]
  public function pdf(Entite $entite, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $year = (int)($request->query->get('year') ?? (new \DateTimeImmutable('now'))->format('Y'));
    $data = $this->bpf->computeForYear($entite, $year);

    $html = $this->renderView('administrateur/bpf/pdf.html.twig', [
      'entite' => $entite,
      'year' => $year,
      'bpf' => $data,
    ]);

    // ✅ Ton PdfManager attend un nameFile SANS .pdf
    return $this->pdfManager->createPortrait(
      $html,
      sprintf('BPF-%s-%d', (string)$entite->getId(), $year)
    );
  }
}
