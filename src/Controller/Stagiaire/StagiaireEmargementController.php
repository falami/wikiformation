<?php
// src/Controller/Stagiaire/StagiaireEmargementController.php
declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Session, Entite, Utilisateur, Emargement};
use App\Enum\DemiJournee;
use App\Repository\EmargementRepository;
use App\Repository\InscriptionRepository;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;



#[Route('/stagiaire/{entite}/emargement', name: 'app_stagiaire_emargement_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_EMARGEMENT_MANAGE, subject: 'entite')]
class StagiaireEmargementController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}
    /**
     * FEED pour le modal (MOI + formateur)
     * - Sécu: le stagiaire doit avoir une Inscription sur la Session (plus de Reservation)
     * - Date en entrée: Y-m-d (ex: 2025-12-20)
     */
    #[Route('/feed', name: 'feed', methods: ['GET'])]
    public function feed(
        Entite $entite,
        Request $request,
        SessionRepository $sessions,
        InscriptionRepository $inscriptions,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $sessionId = (int) $request->query->get('session');
        if ($sessionId <= 0) {
            return new JsonResponse(['error' => 'Session manquante'], 400);
        }

        // date en entrée attendue: Y-m-d
        $dateStr = (string)($request->query->get('date') ?: (new \DateTimeImmutable('today'))->format('Y-m-d'));

        /** @var Session|null $session */
        $qb = $sessions->createQueryBuilder('s')
            ->leftJoin('s.formation', 'fo')->addSelect('fo')
            ->leftJoin('s.formateur', 'f')->addSelect('f')
            ->leftJoin('f.utilisateur', 'u')->addSelect('u')
            ->andWhere('s.id = :sid')->setParameter('sid', $sessionId);

        // ✅ conseillé: filtrer par entité si Session a bien un champ/rel "entite"
        // (si ta Session n’a pas entite, commente ces 2 lignes)
        $qb->andWhere('s.entite = :entite')->setParameter('entite', $entite);

        $session = $qb->getQuery()->getOneOrNullResult();
        if (!$session) {
            return new JsonResponse(['error' => 'Session introuvable'], 404);
        }

        // ✅ Sécu : je dois être inscrit (Inscription) ou admin
        $insc = $inscriptions->findOneBy([
            'session'   => $session,
            'stagiaire' => $user,
        ]);


        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr) ?: new \DateTimeImmutable('today');
        $date = $date->setTime(0, 0);

        // ✅ IMPORTANT: basePath pour générer une URL correcte si ton app n'est pas à la racine
        $basePath = rtrim($request->getBasePath(), '/');

        $signatureInfo = function (Utilisateur $u, DemiJournee $periode) use ($session, $date, $em, $basePath): array {
            $ema = $em->getRepository(Emargement::class)->createQueryBuilder('e')
                ->select('e.signaturePath, e.signedAt')
                ->andWhere('e.session = :s')->setParameter('s', $session)
                ->andWhere('e.utilisateur = :u')->setParameter('u', $u)
                ->andWhere('e.dateJour = :d')->setParameter('d', $date)
                ->andWhere('e.periode = :p')->setParameter('p', $periode)
                ->andWhere('e.signedAt IS NOT NULL')
                ->andWhere('(e.signaturePath IS NOT NULL OR e.signatureDataUrl IS NOT NULL)')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $path = $ema['signaturePath'] ?? null;

            return [
                'signed' => $ema !== null,
                // ✅ URL web correcte même si l’app a un basePath
                'url'    => $path ? ($basePath . '/' . ltrim((string)$path, '/')) : null,
                'at'     => isset($ema['signedAt']) && $ema['signedAt'] instanceof \DateTimeInterface
                    ? $ema['signedAt']->format('d/m/Y H:i')
                    : null,
            ];
        };

        // Bloc formateur (info)
        $formateurUser = $session->getFormateur()?->getUtilisateur();
        $trainer = $formateurUser ? [
            'id'   => $formateurUser->getId(),
            'name' => trim(($formateurUser->getPrenom() . ' ' . $formateurUser->getNom())),
            'am'   => $signatureInfo($formateurUser, DemiJournee::AM),
            'pm'   => $signatureInfo($formateurUser, DemiJournee::PM),
        ] : null;

        $meBlock = [
            'id'   => $user->getId(),
            'name' => trim(($user->getPrenom() . ' ' . $user->getNom())),
            'am'   => $signatureInfo($user, DemiJournee::AM),
            'pm'   => $signatureInfo($user, DemiJournee::PM),
        ];

        return new JsonResponse([
            'date'         => $date->format('Y-m-d'),
            'sessionLabel' => trim(($session->getCode() ?: '') . ' — ' . ($session->getFormation()?->getTitre() ?: 'Session')),
            'trainer'      => $trainer,
            'me'           => $meBlock,
        ]);
    }


    /**
     * SIGN (stagiaire)
     * - Sécu: Inscription requise (plus de Reservation)
     * - Date attendue: d/m/Y (comme ton JS actuel)
     */
    #[Route('/session/{id}/sign', name: 'sign', methods: ['POST'])]
    public function sign(
        Entite $entite,
        Session $id,
        Request $request,
        EmargementRepository $repo,
        InscriptionRepository $inscriptions,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // ✅ Sécu Inscription
        $insc = $inscriptions->findOneBy([
            'session'   => $id,
            'stagiaire' => $user,
        ]);


        // ✅ conseillé: bloquer si la session n'est pas dans l'entité de l'URL
        // (si Session n’a pas entite, commente)
        if (method_exists($id, 'getEntite') && $id->getEntite() && $id->getEntite()->getId() !== $entite->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        $periodeStr = strtoupper((string)$request->request->get('periode', ''));
        $dateFr     = (string)$request->request->get('date', ''); // d/m/Y
        $dataUrl    = (string)$request->request->get('signatureData', '');

        if (!\in_array($periodeStr, ['AM', 'PM'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Période invalide'], 400);
        }
        $periode = $periodeStr === 'AM' ? DemiJournee::AM : DemiJournee::PM;

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateFr) ?: null;
        if (!$date) {
            return new JsonResponse(['success' => false, 'message' => 'Date invalide (attendu d/m/Y)'], 400);
        }
        $date = $date->setTime(0, 0);

        if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return new JsonResponse(['success' => false, 'message' => 'Signature manquante'], 400);
        }

        // Upsert Emargement
        $ema = $repo->findOneBy([
            'session'     => $id,
            'utilisateur' => $user,
            'dateJour'    => $date,
            'periode'     => $periode,
            'role'        => 'stagiaire',
        ]) ?? (new Emargement())
            ->setSession($id)
            ->setUtilisateur($user)
            ->setCreateur($user)
            ->setEntite($entite)
            ->setDateJour($date)
            ->setPeriode($periode)
            ->setRole('stagiaire');

        // Écrire le PNG
        try {
            $path = $this->storeSignaturePng(
                $dataUrl,
                $id->getId(),
                $date,
                $periodeStr,
                'stagiaire-' . $user->getId()
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => 'Échec stockage: ' . $e->getMessage()], 500);
        }

        $now = new \DateTimeImmutable();
        $ema
            ->setSignaturePath($path)
            ->setSignatureDataUrl(null)
            ->setSignedAt($now)
            ->setIp($request->getClientIp())
            ->setUserAgent(substr((string)$request->headers->get('User-Agent'), 0, 255))
            ->setUpdatedAt($now);

        $em->persist($ema);
        $em->flush();

        return new JsonResponse(['success' => true, 'path' => $path]);
    }

    /**
     * UNSIGN (stagiaire)
     * - Sécu: Inscription requise
     * - Date attendue: d/m/Y
     */
    #[Route('/session/{id}/unsign', name: 'unsign', methods: ['POST'])]
    public function unsign(
        Entite $entite,
        Session $id,
        Request $request,
        EmargementRepository $repo,
        InscriptionRepository $inscriptions,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // ✅ Sécu Inscription
        $insc = $inscriptions->findOneBy([
            'session'   => $id,
            'stagiaire' => $user,
        ]);
        if (!$insc && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        // ✅ conseillé: bloquer si session pas dans l'entité URL
        if (method_exists($id, 'getEntite') && $id->getEntite() && $id->getEntite()->getId() !== $entite->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        $periodeStr = strtoupper((string)$request->request->get('periode', ''));
        $dateFr     = (string)$request->request->get('date', '');

        if (!\in_array($periodeStr, ['AM', 'PM'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Période invalide'], 400);
        }
        $periode = $periodeStr === 'AM' ? DemiJournee::AM : DemiJournee::PM;

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateFr) ?: null;
        if (!$date) {
            return new JsonResponse(['success' => false, 'message' => 'Date invalide'], 400);
        }
        $date = $date->setTime(0, 0);

        $ema = $repo->findOneBy([
            'session'     => $id,
            'utilisateur' => $user,
            'dateJour'    => $date,
            'periode'     => $periode,
            'role'        => 'stagiaire',
        ]);

        if (!$ema) {
            return new JsonResponse(['success' => false, 'message' => 'Non trouvé'], 404);
        }

        if ($ema->getSignaturePath()) {
            $this->deleteFileSilently($ema->getSignaturePath());
        }

        $ema
            ->setSignaturePath(null)
            ->setSignatureDataUrl(null)
            ->setSignedAt(null)
            ->setUpdatedAt(new \DateTimeImmutable('now'));

        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /** Stocke le PNG et retourne le chemin web relatif */
    private function storeSignaturePng(
        string $dataUrl,
        int $sessionId,
        \DateTimeImmutable $date,
        string $periode,
        string $prefix
    ): string {
        [$header, $b64] = explode(',', $dataUrl, 2);
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            throw new \RuntimeException('base64_decode failed');
        }

        $base = sprintf('uploads/emargements/%d/%s', $sessionId, $date->format('Y-m-d'));
        $publicDir = rtrim((string)$this->getParameter('kernel.project_dir'), '/') . '/public/';
        $dir = $publicDir . $base;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('mkdir failed: ' . $dir);
        }

        $fileName = sprintf('%s-%s.png', $prefix, strtoupper($periode)); // ex: stagiaire-42-AM.png
        $abs = $dir . '/' . $fileName;

        if (file_put_contents($abs, $bin) === false) {
            throw new \RuntimeException('write failed: ' . $abs);
        }

        return $base . '/' . $fileName;
    }

    private function deleteFileSilently(?string $relativeWebPath): void
    {
        if (!$relativeWebPath) return;

        $abs = rtrim((string)$this->getParameter('kernel.project_dir'), '/') . '/public/' . ltrim($relativeWebPath, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function isEntiteAdmin(Entite $entite): bool
    {
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
        return $ue?->isTenantAdmin() ?? false; // TENANT_ADMIN ou TENANT_DIRIGEANT
    }
}
