<?php
// src/Controller/Stagiaire/StagiaireEspaceController.php
declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Entite, Utilisateur, Session, Inscription};
use App\Repository\EmargementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use App\Security\SupportLibraryAccess;


#[Route('/stagiaire/{entite}', name: 'app_stagiaire_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_ESPACE_MANAGE, subject: 'entite')]
class StagiaireEspaceController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
        private SupportLibraryAccess $supportAccess,
    ) {}

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(Entite $entite, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $qb = $em->createQueryBuilder()
            ->select('i')
            ->addSelect('s')
            ->addSelect('f')
            ->from(Inscription::class, 'i')
            ->join('i.session', 's')
            ->join('s.formation', 'f')
            ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('i.stagiaire = :me')
            ->setParameter('me', $user);

        // ✅ Empêche d’apparaître comme stagiaire si je suis formateur référent de la session
        $qb->leftJoin('s.formateur', 'sf')
            ->leftJoin('sf.utilisateur', 'sfu')
            ->andWhere('(sfu.id IS NULL OR sfu.id <> :meId)')
            ->setParameter('meId', $user->getId());

        // ✅ Empêche d’apparaître comme stagiaire si je suis formateur sur un SessionJour
        $qb->andWhere('NOT EXISTS (
        SELECT 1
        FROM App\Entity\SessionJour j2
        JOIN j2.formateur jf2
        WHERE j2.session = s AND jf2.utilisateur = :me
    )');

        /** @var Inscription[] $inscriptions */
        $inscriptions = $qb->getQuery()->getResult();

        return $this->render('stagiaire/sessions.html.twig', [
            'sessions' => $inscriptions, // ici "sessions" = liste d'Inscription
            'entite' => $entite,

        ]);
    }



    #[Route('/session/{id}', name: 'session_show', methods: ['GET'])]
    public function sessionShow(
        Entite $entite,
        Session $id,
        EntityManagerInterface $em,
        Request $request,
        EmargementRepository $emargementRepo
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($id->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

        // Sécu : je dois être inscrit
        $isTrainer = false;

        // formateur principal
        if ($id->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) {
            $isTrainer = true;
        }

        // formateur par jour (si utilisé)
        if (!$isTrainer) {
            foreach ($id->getJours() as $j) {
                if ($j->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) {
                    $isTrainer = true;
                    break;
                }
            }
        }


        // ✅ Sécu : je dois avoir une inscription
        $isTrainee = (bool) $em->getRepository(Inscription::class)->findOneBy([
            'session' => $id,
            'stagiaire' => $user,
        ]);

        if (!$isTrainee && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException();
        }

        $assets = $this->supportAccess->forLearner($entite, $user, $id);

        $basePath = rtrim($request->getBasePath(), '/');
        $supports = [];
        foreach ($assets as $a) {
            $supports[] = [
                'id'           => $a->getId(),
                'titre'        => $a->getTitre(),
                'originalName' => $a->getOriginalName(),
                'mimeType'     => $a->getMimeType(),
                'url'          => $basePath . '/uploads/supports/library/' . $a->getFilename(),
            ];
        }

        // ===== Timeline + états signés =====
        // Jours "uniques" (YYYY-mm-dd) à partir des SessionJour
        $days = [];
        foreach ($id->getJours() as $j) {
            $dayKey = $j->getDateDebut()->format('Y-m-d');
            $days[$dayKey] = [
                'date' => $dayKey,
                'start' => isset($days[$dayKey]) ? min($days[$dayKey]['start'], $j->getDateDebut()) : $j->getDateDebut(),
                'end' => isset($days[$dayKey]) ? max($days[$dayKey]['end'], $j->getDateFin()) : $j->getDateFin(),
            ];
        }
        ksort($days);
        $days = array_values($days);

        // Etats signés : [ 'YYYY-mm-dd' => ['AM' => true/false, 'PM' => true/false] ]
        $signedMap = $emargementRepo->signedPeriodsForUser($id, $user);

        // Normalise pour être sûr d'avoir AM/PM partout
        $signStates = [];
        foreach ($days as $d) {
            $k = $d['date'];
            $signStates[$k] = [
                'AM' => (bool)($signedMap[$k]['AM'] ?? false),
                'PM' => (bool)($signedMap[$k]['PM'] ?? false),
            ];
        }

        $signatureImages = [];
        foreach ($emargementRepo->findBy(['session' => $id, 'utilisateur' => $user]) as $signature) {
            if (!$signature->getSignedAt()) continue;
            $url = $signature->getSignatureDataUrl() ?: ($signature->getSignaturePath() ? $basePath . '/' . ltrim($signature->getSignaturePath(), '/') : null);
            if ($url) $signatureImages[$signature->getDateJour()->format('Y-m-d')][$signature->getPeriode()->value] = $url;
        }

        return $this->render('stagiaire/session_show.html.twig', [
            'session' => $id,
            'entite'  => $entite,
            'supports' => $supports,
            'days' => $days,
            'signStates' => $signStates,
            'signatureImages' => $signatureImages,

        ]);
    }



    /** Supports visibles pour une session précise (et mes supports persos) */
    #[Route('/session/{id}/supports', name: 'session_supports', methods: ['GET'])]
    public function sessionSupports(Entite $entite, Session $id, EntityManagerInterface $em, Request $req): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($id->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

        // Sécu : inscrit ?
        $isTrainer = false;
        if ($id->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) $isTrainer = true;

        if (!$isTrainer) {
            foreach ($id->getJours() as $j) {
                if ($j->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) {
                    $isTrainer = true;
                    break;
                }
            }
        }
        if ($isTrainer && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException();
        }

        $isTrainee = (bool) $em->getRepository(Inscription::class)->findOneBy([
            'session' => $id,
            'stagiaire' => $user,
        ]);

        if (!$isTrainee && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException();
        }

        $assets = $this->supportAccess->forLearner($entite, $user, $id);

        return $this->render('stagiaire/supports.html.twig', [
            'entite'  => $entite,
            'session' => $id,
            'assets'  => array_values($assets),

        ]);
    }

    /** Version JSON si tu veux alimenter un tableau en JS */
    #[Route('/session/{id}/supports/feed', name: 'session_supports_feed', methods: ['GET'])]
    public function sessionSupportsFeed(Entite $entite, Session $id, EntityManagerInterface $em, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($id->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

        $isTrainer = false;
        if ($id->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) $isTrainer = true;

        if (!$isTrainer) {
            foreach ($id->getJours() as $j) {
                if ($j->getFormateur()?->getUtilisateur()?->getId() === $user->getId()) {
                    $isTrainer = true;
                    break;
                }
            }
        }
        if ($isTrainer && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException();
        }

        $isTrainee = (bool) $em->getRepository(Inscription::class)->findOneBy([
            'session' => $id,
            'stagiaire' => $user,
        ]);

        if (!$isTrainee && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['data' => []], 403);
        }

        $basePath = rtrim($request->getBasePath(), '/');

        $rows = [];

        $assets = $this->supportAccess->forLearner($entite, $user, $id);

        foreach ($assets as $a) {
            $rows[] = [
                'id'           => $a->getId(),
                'titre'        => $a->getTitre(),
                'filename'     => $a->getFilename(),
                'originalName' => $a->getOriginalName(),
                'mimeType'     => $a->getMimeType(),
                'uploadedAt'   => $a->getUploadedAt()?->format('d/m/Y H:i'),
                'size'         => $a->getSizeBytes(),
                'url'          => $basePath . '/uploads/supports/library/' . $a->getFilename(),
            ];
        }


        return new JsonResponse(['data' => $rows]);
    }
    private function isEntiteAdmin(Entite $entite): bool
    {
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
        return $ue?->isTenantAdmin() ?? false; // TENANT_ADMIN ou TENANT_DIRIGEANT
    }
}
