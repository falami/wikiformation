<?php
declare(strict_types=1);
namespace App\Service\Attendance;
use App\Entity\{Session, Utilisateur, Emargement};
use App\Enum\DemiJournee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
final class AttendancePdfData
{
    public function __construct(private EntityManagerInterface $em, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}
    public function build(Session $session, Request $request): array
    {
        $em = $this->em;
        $entite = $session->getEntite();
        $trainerUsers = [];
        foreach ($session->getFormateursEffectifs() as $f) {
            if ($u = $f->getUtilisateur()) $trainerUsers[$u->getId()] = $u;
        }
        $trainerName = implode(', ', array_map(static fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()), $trainerUsers));
        $joursCol = $session->getJours();
        $dateIndex = [];
        foreach ($joursCol as $j) {
            if (!$j->getDateDebut() || !$j->getDateFin()) continue;
            $key = $j->getDateDebut()->format('Y-m-d');
            $dateIndex[$key] ??= ['ymd' => $key, 'label' => $j->getDateDebut()->format('d/m/Y'), 'horaires' => []];
            $dateIndex[$key]['horaires'][] = $j->getDateDebut()->format('H:i') . ' à ' . $j->getDateFin()->format('H:i');
        }
        ksort($dateIndex);
        $joursList = array_values($dateIndex);

        // --- Bornes globales pour l’en-tête
        [$minDebut, $maxFin] = $this->computeGlobalBounds($joursCol);

        // --- Charger les émargements de toutes les dates
        $allDates = array_keys($dateIndex);
        if (empty($allDates)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Aucun jour associé à cette session.');
        }

        $emargements = $em->getRepository(Emargement::class)
            ->createQueryBuilder('e')
            ->andWhere('e.session = :s')
            ->andWhere('e.entite = :tenant')->setParameter('tenant', $entite)
            ->andWhere('e.dateJour IN (:ds)')
            ->setParameter('s', $session)
            ->setParameter('ds', $allDates)
            ->getQuery()
            ->getResult();

        // --- Regrouper par jour + identité (compte ou participant sans compte)
        $linesByDate = [];
        foreach ($allDates as $d) {
            $linesByDate[$d] = [];
        }

        foreach ($emargements as $e) {
            $u   = $e->getUtilisateur();
            $access = $e->getParticipantAccess();
            $uid = $u ? 'user:' . $u->getId() : 'guest:' . $access?->getId();
            if (!$u && !$access) continue;
            $dYmd = $e->getDateJour()->format('Y-m-d');

            if (!isset($linesByDate[$dYmd][$uid])) {
                $linesByDate[$dYmd][$uid] = [
                    'id'        => $uid,
                    // Les intervenants sont déduits des affectations du planning.
                    'isTrainer'     => $u && isset($trainerUsers[$u->getId()]),
                    'name'          => $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : $access->getDisplayName(),
                    'raisonSociale' => $u?->getEntreprise()?->getRaisonSociale() ?? $access?->getConvention()?->getEntreprise()?->getRaisonSociale() ?? '—',
                    'naissance'     => $u?->getDateNaissance()?->format('d/m/Y') ?: '—',
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
            'titre'         => $session->getFormation()?->getTitre() ?: ($session->getFormationIntituleLibre() ?: 'Formation'),
            'code'          => (string) ($session->getCode() ?? ''),
            'site'          => $session->getSite()?->getNom() ?? null,
            'adresse'       => $adresse,
            'dateDebut'     => $minDebut?->format('d/m/Y'),
            'dateFin'       => $maxFin?->format('d/m/Y'),
            'jours'         => count($joursList),
            'joursList'     => $joursList,
            // ✅ infos formateur pour affichage en clair dans le PDF
            'formateurName' => $trainerName,
            'formateurId'   => null,
        ];

        return ['meta'=>$meta, 'linesByDate'=>$linesByDate, 'preferences'=>$entite->getPreferences()];
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

        $publicRoot = $this->projectDir . '/public';
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

}
