<?php

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\SessionRepository;
use App\Service\PasseportPrevention\PasseportPreventionExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/passeport-prevention', name: 'app_administrateur_passeport_prevention_')]
#[IsGranted(TenantPermission::PASSEPORT_PREVENTION_MANAGE, subject: 'entite')]
final class PasseportPreventionController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}


  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, SessionRepository $sessionRepo): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ charge les sessions (tu peux filtrer si tu veux)
    $sessions = $sessionRepo->createQueryBuilder('s')
      ->leftJoin('s.jours', 'j')->addSelect('j')
      ->leftJoin('s.inscriptions', 'i')->addSelect('i')
      ->leftJoin('s.formation', 'f')->addSelect('f')
      ->andWhere('s.entite = :e')->setParameter('e', $entite)
      // ->andWhere('s.status IN (:st)')->setParameter('st', [StatusSession::PUBLISHED, StatusSession::DONE]) // optionnel
      ->orderBy('j.dateDebut', 'DESC')
      ->getQuery()->getResult();

    return $this->render('administrateur/passeport/index.html.twig', [
      'entite' => $entite,
      'sessions' => $sessions, // ✅ IMPORTANT
      'utilisateurEntite' => $this->utilisateurEntiteManager->getRepository()
        ->findOneBy(['entite' => $entite, 'utilisateur' => $user]),
    ]);
  }


  #[Route('/export', name: 'export', methods: ['POST'])]
  public function export(
    Entite $entite,
    Request $request,
    SessionRepository $sessionRepo,
    PasseportPreventionExporter $exporter,
  ): Response {


    // mode = manual | period
    $mode = (string) $request->request->get('mode', 'period');

    $dateFrom = (string) $request->request->get('dateFrom', '');
    $dateTo   = (string) $request->request->get('dateTo', '');

    $idsRaw = (string) $request->request->get('sessionIds', '[]');
    $ids = json_decode($idsRaw, true);
    if (!is_array($ids)) $ids = [];

    if ($mode === 'manual') {
      $sessions = $sessionRepo->findByEntiteAndIds($entite, array_map('intval', $ids));
    } else {
      // période (max 3 mois, validée côté serveur)
      $from = $dateFrom ? new \DateTimeImmutable($dateFrom . ' 00:00:00') : null;
      $to   = $dateTo   ? new \DateTimeImmutable($dateTo   . ' 23:59:59') : null;

      if (!$from || !$to) {
        $this->addFlash('danger', 'Veuillez choisir une période.');
        return $this->redirectToRoute('app_administrateur_passeport_prevention_index', [
          'entite' => $entite->getId()
        ]);
      }
      if ($to < $from) {
        $this->addFlash('danger', 'La date de fin doit être après la date de début.');
        return $this->redirectToRoute('app_administrateur_passeport_prevention_index', [
          'entite' => $entite->getId()
        ]);
      }

      $months = ((int)$from->diff($to)->days);
      if ($months > 93) { // ~ 3 mois
        $this->addFlash('danger', 'La période ne doit pas dépasser 3 mois.');
        return $this->redirectToRoute('app_administrateur_passeport_prevention_index', [
          'entite' => $entite->getId()
        ]);
      }

      $sessions = $sessionRepo->findByEntiteAndPeriodViaJours($entite, $from, $to);
    }

    if (!$sessions) {
      $this->addFlash('warning', 'Aucune session à exporter.');
      return $this->redirectToRoute('app_administrateur_passeport_prevention_index', [
        'entite' => $entite->getId()
      ]);
    }

    $file = $exporter->exportAttestationsXlsx($entite, $sessions);

    return new BinaryFileResponse($file, 200, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ], true);
  }
}
