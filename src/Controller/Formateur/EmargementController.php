<?php

namespace App\Controller\Formateur;

use App\Entity\{Emargement, Entite, Utilisateur, SessionJour, Session, Inscription};
use App\Enum\DemiJournee;
use App\Repository\EmargementRepository;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;



#[Route('/formateur/{entite}/emargement', name: 'app_formateur_emargement_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_EMARGEMENT_MANAGE, subject: 'entite')]
class EmargementController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}

    // =========================================================
    // FEED pour le modal d’émargements (AM/PM)
    // =========================================================
    #[Route('/emargements/feed', name: 'feed', methods: ['GET'])]
    public function emargementsFeed(
        Entite $entite,
        Request $request,
        SessionRepository $sessions,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $sessionId = (int) $request->query->get('session');
        $dateStr   = (string) ($request->query->get('date') ?: (new \DateTimeImmutable('today'))->format('Y-m-d'));
        $date      = $this->dayFromYmd($dateStr);

        /** @var Session|null $session */
        $session = $sessions->createQueryBuilder('s')
            ->leftJoin('s.formateur', 'f')->addSelect('f')
            ->leftJoin('f.utilisateur', 'u')->addSelect('u')
            ->leftJoin('s.formation', 'fo')->addSelect('fo')
            ->andWhere('s.id = :sid')->setParameter('sid', $sessionId)
            ->getQuery()->getOneOrNullResult();

        if (!$session) {
            return new JsonResponse(['error' => 'Session introuvable'], 404);
        }

        // Accès : admin OU formateur propriétaire
        if (
            !$this->isEntiteAdmin($entite) &&
            $session->getFormateur()?->getUtilisateur()?->getId() !== $user->getId()
        ) {
            return new JsonResponse(['error' => 'Accès refusé'], 403);
        }

        // Liste des jours autorisés (YYYY-MM-DD) pour le datepicker
        $allowedDays = [];
        foreach ($session->getJours() as $jj) {
            $allowedDays[] = $jj->getDateDebut()->setTime(0, 0)->format('Y-m-d');
        }
        $allowedDays = array_values(array_unique($allowedDays));
        sort($allowedDays);

        // Si la date ne fait pas partie des jours de session
        if (!$this->dayBelongsToSession($session, $date)) {
            return new JsonResponse([
                'date'         => $date->format('Y-m-d'),
                'sessionLabel' => trim(($session->getCode() ?: '') . ' — ' . ($session->getFormation()?->getTitre() ?: 'Session')),
                'outOfSession' => true,
                'allowedDays'  => $allowedDays,
                'trainer'      => null,
                'trainees'     => [],
                'message'      => "Aucun émargement à faire : cette date n'est pas un jour de formation.",
            ]);
        }

        // ✅ Perf : 1 seule requête pour toutes les signatures du jour
        $rows = $em->getRepository(Emargement::class)->createQueryBuilder('e')
            ->select('IDENTITY(e.utilisateur) AS uid, e.periode AS periode, e.role AS role, e.signaturePath AS path, e.signedAt AS signedAt')
            ->andWhere('e.session = :s')->setParameter('s', $session)
            ->andWhere('e.dateJour = :d')->setParameter('d', $date)
            ->andWhere('e.signaturePath IS NOT NULL')
            ->getQuery()->getArrayResult();



        // map: signed[role][uid][AM|PM] = true
        $signed = []; // signed[role][uid][AM|PM] = ['signed'=>bool,'url'=>string|null,'at'=>string|null]

        foreach ($rows as $r) {
            $uid  = (int) ($r['uid'] ?? 0);
            $role = (string) ($r['role'] ?? '');
            $per  = $r['periode'] instanceof \BackedEnum ? $r['periode']->value : (string) $r['periode'];
            $per  = strtoupper($per);

            if ($uid <= 0 || !$role || !\in_array($per, ['AM', 'PM'], true)) continue;

            $path = (string) ($r['path'] ?? '');
            // ✅ IMPORTANT : forcer une URL web qui commence par "/"
            if ($path && $path[0] !== '/' && !str_starts_with($path, 'http')) {
                $path = '/' . $path;
            }

            $at = null;
            if (!empty($r['signedAt']) && $r['signedAt'] instanceof \DateTimeInterface) {
                $at = $r['signedAt']->format('d/m/Y H:i');
            } elseif (is_string($r['signedAt']) && $r['signedAt']) {
                // au cas où Doctrine te renvoie une string
                try {
                    $at = (new \DateTimeImmutable($r['signedAt']))->format('d/m/Y H:i');
                } catch (\Throwable) {
                }
            }

            $signed[$role][$uid][$per] = [
                'signed' => true,
                'url'    => $path ?: null,
                'at'     => $at,
            ];
        }

        $info = static fn(array $signed, string $role, int $uid, string $per): array =>
        $signed[$role][$uid][strtoupper($per)] ?? ['signed' => false, 'url' => null, 'at' => null];


        $has = static fn(array $signed, string $role, int $uid, string $per): bool
        => !empty($signed[$role][$uid][strtoupper($per)]);

        $formateurUser = $session->getFormateur()?->getUtilisateur();

        $trainer = null;
        if ($formateurUser) {
            $am = $info($signed, 'trainer', $formateurUser->getId(), 'AM');
            $pm = $info($signed, 'trainer', $formateurUser->getId(), 'PM');

            $trainer = [
                'id'        => $formateurUser->getId(),
                'name'      => trim($formateurUser->getPrenom() . ' ' . $formateurUser->getNom()),
                'signed_am' => (bool) $am['signed'],
                'signed_pm' => (bool) $pm['signed'],

                // ✅ pour l’aperçu
                'signature_am_url' => $am['url'],
                'signature_pm_url' => $pm['url'],
                'signed_at_am'     => $am['at'],
                'signed_at_pm'     => $pm['at'],
            ];
        }


        $trainees = [];
        foreach ($session->getInscriptions() as $inscription) {
            $u = $inscription->getStagiaire();
            if (!$u) continue;
            if ($formateurUser && $u->getId() === $formateurUser->getId()) continue;

            $am = $info($signed, 'stagiaire', $u->getId(), 'AM');
            $pm = $info($signed, 'stagiaire', $u->getId(), 'PM');

            $trainees[] = [
                'id'        => $u->getId(),
                'name'      => trim($u->getPrenom() . ' ' . $u->getNom()),

                'signed_am' => (bool) $am['signed'],
                'signed_pm' => (bool) $pm['signed'],

                // ✅ pour l’aperçu (si tu veux afficher l’œil aussi pour eux)
                'signature_am_url' => $am['url'],
                'signature_pm_url' => $pm['url'],
                'signed_at_am'     => $am['at'],
                'signed_at_pm'     => $pm['at'],
            ];
        }


        return new JsonResponse([
            'date'         => $date->format('Y-m-d'),
            'sessionLabel' => trim(($session->getCode() ?: '') . ' — ' . ($session->getFormation()?->getTitre() ?: 'Session')),
            'outOfSession' => false,
            'allowedDays'  => $allowedDays,
            'trainer'      => $trainer,
            'trainees'     => $trainees,
        ]);
    }

    // =========================================================
    // Page liste jours + stats
    // =========================================================
    #[Route('/session/{id}', name: 'liste', methods: ['GET'])]
    public function liste(
        Entite $entite,
        Session $id,
        EmargementRepository $repo
    ): Response {
        $this->assertCanManageSession($entite, $id);

        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // --- calc participants attendus ---
        $formateurUser = $id->getFormateur()?->getUtilisateur();
        $trainerCount  = $formateurUser ? 1 : 0;

        // stagiaires inscrits (on exclut le formateur si jamais il est aussi inscrit)
        $traineeCount = 0;
        foreach ($id->getInscriptions() as $inscription) {
            $u = $inscription->getStagiaire();
            if (!$u) continue;
            if ($formateurUser && $u->getId() === $formateurUser->getId()) continue;
            $traineeCount++;
        }

        // attendu = (stagiaires + formateur) * 2 (AM + PM)
        $expectedPerDay = ($traineeCount + $trainerCount) * 2;

        $jours = [];
        foreach ($id->getJours() as $j) {
            $day = (clone $j->getDateDebut())->setTime(0, 0);

            $countSigned = (int) $repo->countSignedByDay($id, $day);

            $isPast = $day < $today;

            // manquant = jour passé + attendu défini + pas assez de signatures
            $isMissing = $isPast && $expectedPerDay > 0 && $countSigned < $expectedPerDay;

            $jours[] = [
                'date'     => $day,
                'count'    => $countSigned,
                'expected' => $expectedPerDay,
                'isPast'   => $isPast,
                'isMissing' => $isMissing,
            ];
        }

        return $this->render('formateur/emargements.html.twig', [
            'entite'  => $entite,
            'session' => $id,
            'jours'   => $jours,
        ]);
    }

    // =========================================================
    // Signature d’un stagiaire par le formateur (modal)
    // =========================================================
    #[Route('/session/{id}/sign-user/{user}', name: 'sign_user', methods: ['POST'])]
    public function signUser(
        Entite $entite,
        Session $id,
        Utilisateur $user,
        Request $request,
        EmargementRepository $repo,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->assertCanManageSession($entite, $id);

        // Vérifier que $user est inscrit à la session
        $isTrainee = (bool) $em->getRepository(Inscription::class)->findOneBy([
            'session'   => $id,
            'stagiaire' => $user,
        ]);

        if (!$isTrainee) {
            return new JsonResponse(['success' => false, 'message' => 'Utilisateur non inscrit'], 400);
        }

        $dataUrl    = (string) $request->request->get('signatureData', '');
        $periodeStr = strtoupper((string) $request->request->get('periode', '')); // AM | PM
        $dateStr    = (string) $request->request->get('date', '');               // d/m/Y

        if (!\in_array($periodeStr, ['AM', 'PM'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Période invalide'], 400);
        }

        if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return new JsonResponse(['success' => false, 'message' => 'Signature manquante'], 400);
        }

        $day = $this->dayFromFr($dateStr);
        if (!$day) {
            return new JsonResponse(['success' => false, 'message' => 'Format de date invalide (attendu d/m/Y)'], 400);
        }

        // Bloquer hors session
        if (!$this->dayBelongsToSession($id, $day)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Aucun émargement à faire : cette date n'est pas un jour de formation."
            ], 400);
        }

        $periode = DemiJournee::from($periodeStr);

        // Unicité (stagiaire)
        $existing = $repo->findOneBy([
            'session'     => $id,
            'utilisateur' => $user,
            'dateJour'    => $day,
            'periode'     => $periode,
            'role'        => 'stagiaire',
        ]) ?? (new Emargement())
            ->setSession($id)
            ->setUtilisateur($user)
            ->setCreateur($user)
            ->setEntite($entite)
            ->setDateJour($day)
            ->setPeriode($periode)
            ->setRole('stagiaire');

        // Stockage fichier (chemin unifié)
        $oldPath = $existing->getSignaturePath();
        try {
            $path = $this->storeSignaturePngForUser($dataUrl, $id->getId(), $day, $periodeStr, 'stagiaire', $user->getId());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => 'store_failed: ' . $e->getMessage()], 500);
        }
        if ($oldPath && $oldPath !== $path) {
            $this->deleteFileSilently($oldPath);
        }

        $now = new \DateTimeImmutable('now');
        $existing
            ->setSignaturePath($path)
            ->setSignatureDataUrl(null)
            ->setSignedAt($now)
            ->setIp($request->getClientIp())
            ->setUserAgent(substr((string)$request->headers->get('User-Agent'), 0, 255))
            ->setUpdatedAt($now);

        $em->persist($existing);
        $em->flush();

        return new JsonResponse(['success' => true, 'path' => $path]);
    }

    // =========================================================
    // Signature du formateur (lui-même)
    // =========================================================
    #[Route('/session/{id}/sign', name: 'sign', methods: ['POST'])]
    public function sign(
        Entite $entite,
        Session $id,
        Request $request,
        EmargementRepository $repo,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->assertCanManageSession($entite, $id);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        $periodeStr = strtoupper((string) $request->request->get('periode', ''));
        $dateFr     = (string) $request->request->get('date', '');
        $dataUrl    = (string) $request->request->get('signatureData', '');

        if (!\in_array($periodeStr, ['AM', 'PM'], true)) {
            return new JsonResponse(['success' => false, 'error' => 'periode_invalid'], 400);
        }

        $date = $this->dayFromFr($dateFr);
        if (!$date) {
            return new JsonResponse(['success' => false, 'error' => 'date_invalid'], 400);
        }

        if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return new JsonResponse(['success' => false, 'error' => 'signature_invalid'], 400);
        }

        // Bloquer hors session
        if (!$this->dayBelongsToSession($id, $date)) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'day_out_of_session',
                'message' => "Aucun émargement à faire : cette date n'est pas un jour de formation."
            ], 400);
        }

        $periode = DemiJournee::from($periodeStr);

        // Upsert : une ligne par (session, user, jour, période, role)
        $ema = $repo->findOneBy([
            'session'     => $id,
            'utilisateur' => $user,
            'dateJour'    => $date,
            'periode'     => $periode,
            'role'        => 'trainer',
        ]) ?? (new Emargement())
            ->setSession($id)
            ->setUtilisateur($user)
            ->setCreateur($user)
            ->setEntite($entite)
            ->setDateJour($date)
            ->setPeriode($periode)
            ->setRole('trainer');

        $oldPath = $ema->getSignaturePath();

        try {
            $path = $this->storeSignaturePngForUser($dataUrl, $id->getId(), $date, $periodeStr, 'trainer', $user->getId());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => 'store_failed: ' . $e->getMessage()], 500);
        }

        if ($oldPath && $oldPath !== $path) {
            $this->deleteFileSilently($oldPath);
        }

        $now = new \DateTimeImmutable('now');
        $ema->setSignaturePath($path);
        $ema->setSignatureDataUrl(null);
        $ema->setSignedAt($now);
        $ema->setIp($request->getClientIp());
        $ema->setUserAgent(substr((string)$request->headers->get('User-Agent'), 0, 255));
        $ema->setUpdatedAt($now);

        $em->persist($ema);
        $em->flush();

        return new JsonResponse(['success' => true, 'path' => $path]);
    }

    // =========================================================
    // Retrait signature formateur
    // =========================================================
    #[Route('/session/{id}/unsign', name: 'unsign', methods: ['POST'])]
    public function unsign(
        Entite $entite,
        Session $id,
        Request $request,
        EmargementRepository $repo,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->assertCanManageSession($entite, $id);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        $periodeStr = strtoupper((string) $request->request->get('periode', ''));
        $dateFr     = (string) $request->request->get('date', '');

        if (!\in_array($periodeStr, ['AM', 'PM'], true)) {
            return new JsonResponse(['success' => false, 'error' => 'periode_invalid'], 400);
        }

        $date = $this->dayFromFr($dateFr);
        if (!$date) {
            return new JsonResponse(['success' => false, 'error' => 'date_invalid'], 400);
        }

        $periode = DemiJournee::from($periodeStr);

        $ema = $repo->findOneBy([
            'session'     => $id,
            'utilisateur' => $user,
            'dateJour'    => $date,
            'periode'     => $periode,
            'role'        => 'trainer',
        ]);

        if (!$ema) {
            return new JsonResponse(['success' => false, 'error' => 'not_found'], 404);
        }

        if ($ema->getSignaturePath()) {
            $this->deleteFileSilently($ema->getSignaturePath());
        }

        $ema->setSignaturePath(null);
        $ema->setSignatureDataUrl(null);
        $ema->setSignedAt(null);
        $ema->setUpdatedAt(new \DateTimeImmutable('now'));

        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function assertCanManageSession(Entite $entite, Session $session): void
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($this->isEntiteAdmin($entite)) return;

        $ownerId = $session->getFormateur()?->getUtilisateur()?->getId();
        if (!$ownerId || $ownerId !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function dayFromYmd(string $ymd): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) ?: new \DateTimeImmutable('today');
        return $d->setTime(0, 0);
    }

    private function dayFromFr(string $fr): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!d/m/Y', $fr);
        return $d?->setTime(0, 0);
    }

    /**
     * Retourne le SessionJour correspondant à la date (YYYY-mm-dd, heure ignorée).
     */
    private function findJourForDay(Session $session, \DateTimeImmutable $day): ?SessionJour
    {
        $target = $day->setTime(0, 0);
        foreach ($session->getJours() as $j) {
            $d = $j->getDateDebut()?->setTime(0, 0);
            if ($d && $d->format('Y-m-d') === $target->format('Y-m-d')) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Vrai si $day (Y-m-d) existe dans les jours de la session.
     */
    private function dayBelongsToSession(Session $session, \DateTimeImmutable $day): bool
    {
        return (bool) $this->findJourForDay($session, $day);
    }

    /**
     * Enregistre le PNG sur disque, retourne le chemin relatif web
     * ex: uploads/emargements/{session}/{YYYY-MM-DD}/{role}-{AM|PM}-{userId}.png
     */
    /**
     * Enregistre le PNG sur disque, retourne le chemin web (avec / au début)
     * ex: /uploads/emargements/{session}/{YYYY-MM-DD}/{role}-{AM|PM}-{userId}.png
     */
    private function storeSignaturePngForUser(
        string $dataUrl,
        int $sessionId,
        \DateTimeImmutable $date,
        string $periode,
        string $role,
        int $userId
    ): string {
        // Décoder
        $parts = explode(',', $dataUrl, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('invalid dataUrl');
        }
        $bin = base64_decode($parts[1], true);
        if ($bin === false) {
            throw new \RuntimeException('base64_decode failed');
        }

        // Dossier public/uploads/...
        $baseWeb   = sprintf('uploads/emargements/%d/%s', $sessionId, $date->format('Y-m-d')); // SANS "/" au début
        $publicDir = rtrim((string) $this->getParameter('kernel.project_dir'), '/') . '/public';
        $dirAbs    = $publicDir . '/' . $baseWeb;

        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new \RuntimeException('mkdir failed: ' . $dirAbs);
        }

        // Nom du fichier
        $fileName = sprintf('%s-%s-%d.png', $role, strtoupper($periode), $userId);
        $fileAbs  = $dirAbs . '/' . $fileName;

        if (file_put_contents($fileAbs, $bin) === false) {
            throw new \RuntimeException('write failed: ' . $fileAbs);
        }

        // ✅ Chemin web final (AVEC "/" pour que l’aperçu marche)
        return '/' . $baseWeb . '/' . $fileName;
    }


    private function deleteFileSilently(?string $relativeWebPath): void
    {
        if (!$relativeWebPath) return;
        $abs = rtrim((string) $this->getParameter('kernel.project_dir'), '/') . '/public/' . ltrim($relativeWebPath, '/');
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
