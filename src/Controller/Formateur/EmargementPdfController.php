<?php
// src/Controller/Formateur/EmargementPdfController.php
declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{Emargement, Entite, Session, Utilisateur};
use App\Enum\DemiJournee;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;


#[IsGranted(TenantPermission::FORMATEUR_EMARGEMENT_PDF_MANAGE, subject: 'entite')]
class EmargementPdfController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}
    #[Route(
        '/formateur/{entite}/emargement/pdf',
        name: 'app_formateur_emargement_pdf',
        methods: ['GET'],
        requirements: ['entite' => '\d+']
    )]
    public function pdf(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        PdfManager $pdf
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // --- Paramètres : session=ID
        $sessionId = (int) $request->query->get('session', 0);
        if ($sessionId <= 0) {
            throw $this->createNotFoundException('Paramètre "session" manquant ou invalide.');
        }

        /** @var Session|null $session */
        $session = $em->getRepository(Session::class)->find($sessionId);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        // --- Récupérer le formateur de la session
        $formateur     = $session->getFormateur();
        $trainerUser   = $formateur?->getUtilisateur();
        $trainerUserId = $trainerUser?->getId();
        $trainerName   = $trainerUser
            ? trim(($trainerUser->getPrenom() ?? '') . ' ' . ($trainerUser->getNom() ?? ''))
            : null;

        // Vérification d’accès (le formateur de la session ou un admin)
        $isOwner = $trainerUserId === $user->getId();
        if (!$isOwner && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException('Accès refusé à cette session.');
        }

        // --- Liste des jours de formation
        $joursCol  = $session->getJours(); // Collection<SessionJour>
        $joursList = [];
        $dateIndex = [];

        foreach ($joursCol as $j) {
            $dYmd = $j->getDateDebut()->format('Y-m-d');
            $joursList[] = [
                'ymd'   => $dYmd,
                'label' => $j->getDateDebut()->format('d/m/Y'),
                'debut' => $j->getDateDebut()->format('H:i'),
                'fin'   => $j->getDateFin()->format('H:i'),
            ];
            $dateIndex[$dYmd] = end($joursList);
        }

        // --- Bornes globales pour l’en-tête
        [$minDebut, $maxFin] = $this->computeGlobalBounds($joursCol);

        // --- Charger les émargements de toutes les dates
        $allDates = array_keys($dateIndex);
        if (empty($allDates)) {
            throw $this->createNotFoundException('Aucun jour associé à cette session.');
        }

        $emargements = $em->getRepository(Emargement::class)
            ->createQueryBuilder('e')
            ->andWhere('e.session = :s')
            ->andWhere('e.dateJour IN (:ds)')
            ->setParameter('s', $session)
            ->setParameter('ds', $allDates)
            ->getQuery()
            ->getResult();

        // --- Regrouper par jour + utilisateur
        $linesByDate = [];
        foreach ($allDates as $d) {
            $linesByDate[$d] = [];
        }

        foreach ($emargements as $e) {
            $u   = $e->getUtilisateur();
            $uid = $u->getId();
            $dYmd = $e->getDateJour()->format('Y-m-d');

            if (!isset($linesByDate[$dYmd][$uid])) {
                $linesByDate[$dYmd][$uid] = [
                    'id'        => $uid,
                    // ✅ on marque le formateur via Session->formateur, pas via le rôle Emargement
                    'isTrainer'     => $trainerUserId !== null && $uid === $trainerUserId,
                    'name'          => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')),
                    'raisonSociale' => $u?->getEntreprise()->getRaisonSociale() ?? '—',
                    'naissance'     => $u->getDateNaissance()?->format('d/m/Y') ?: '—',
                    'am'            => ['signed' => false, 'img' => null, 'at' => null],
                    'pm'            => ['signed' => false, 'img' => null, 'at' => null],
                ];
            }

            $col = ($e->getPeriode() === DemiJournee::PM) ? 'pm' : 'am';

            $img = null;
            if ($e->getSignatureDataUrl()) {
                $img = $e->getSignatureDataUrl();
            } elseif ($e->getSignaturePath()) {
                $img = $this->signaturePathToRenderable($request, (string) $e->getSignaturePath());
            }

            if ($img) {
                $linesByDate[$dYmd][$uid][$col] = [
                    'signed' => true,
                    'img'    => $img,
                    'at'     => $e->getSignedAt()?->format('d/m/Y H:i'),
                ];
            }
        }

        // --- Tri (stagiaires alphabétiques, formateur en dernier)
        foreach ($linesByDate as $d => $map) {
            $arr = array_values($map);

            usort($arr, static function (array $a, array $b): int {
                // Si les deux sont formateur ou les deux ne le sont pas → tri alpha sur le nom
                if ($a['isTrainer'] === $b['isTrainer']) {
                    return strcasecmp($a['name'], $b['name']);
                }

                // Sinon : le formateur va en dernier
                return $a['isTrainer'] ? 1 : -1;
            });

            $linesByDate[$d] = $arr;
        }


        $site = $session->getSite();
        $adresse = trim(
            ($site?->getNom() ?: '') . ' - ' .
                ($site?->getAdresse() ?: '') . ' ' .
                ($site?->getComplement() ?: '') . ' - ' .
                ($site?->getCodePostal() ?: '') . ' ' .
                ($site?->getVille() ?: '') . ' - ' .
                ($site?->getPays() ?: '')
        );


        // --- Métadonnées pour le template
        $meta = [
            'entite'        => $entite,
            'titre'         => $session->getFormation()?->getTitre() ?? 'Formation',
            'code'          => (string) ($session->getCode() ?? ''),
            'site'          => $session->getSite()?->getNom() ?? null,
            'adresse'       => $adresse,
            'dateDebut'     => $minDebut?->format('d/m/Y'),
            'dateFin'       => $maxFin?->format('d/m/Y'),
            'jours'         => count($joursList),
            'joursList'     => $joursList,
            // ✅ infos formateur pour affichage en clair dans le PDF
            'formateurName' => $trainerName,
            'formateurId'   => $trainerUserId,
        ];

        // --- Rendu HTML
        $html = $this->renderView('pdf/emargement_sheet.html.twig', [
            'meta'        => $meta,
            'linesByDate' => $linesByDate,
            'preferences' => $entite->getPreferences(),
        ]);

        $filename = sprintf(
            'EMARGEMENT_%s_%s',
            $meta['code'] ?: 'session',
            date('Ymd')
        );

        return $pdf->createLandscape($html, $filename);
    }


    private function computeGlobalBounds($jours): array
    {
        $min = null;
        $max = null;
        foreach ($jours as $j) {
            $d1 = $j->getDateDebut();
            $d2 = $j->getDateFin();
            if ($d1 && (!$min || $d1 < $min)) $min = $d1;
            if ($d2 && (!$max || $d2 > $max)) $max = $d2;
        }
        return [$min, $max];
    }

    private function signaturePathToRenderable(Request $request, string $path): ?string
    {
        $trim = trim($path);
        if ($trim === '') return null;

        if (preg_match('~^https?://~i', $trim)) {
            return $trim;
        }

        $publicRoot = $this->getParameter('kernel.project_dir') . '/public';
        $full = $publicRoot . '/' . ltrim($trim, '/');

        if (is_file($full) && is_readable($full)) {
            $mime = $this->guessImageMime($full);
            $data = @file_get_contents($full);
            if ($data !== false) {
                return 'data:' . $mime . ';base64,' . base64_encode($data);
            }
        }

        return $request->getSchemeAndHttpHost() . '/' . ltrim($trim, '/');
    }

    private function guessImageMime(string $fullPath): string
    {
        return match (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/png'
        };
    }

    private function isEntiteAdmin(Entite $entite): bool
    {
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
        return $ue?->isTenantAdmin() ?? false; // TENANT_ADMIN ou TENANT_DIRIGEANT
    }
}
