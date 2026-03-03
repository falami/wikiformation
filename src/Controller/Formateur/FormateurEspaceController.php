<?php

namespace App\Controller\Formateur;

use App\Entity\{Emargement, Entite, Utilisateur, SupportDocument, Session};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, File\UploadedFile, StreamedResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/formateur/{entite}', name: 'app_formateur_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE, subject: 'entite')]
class FormateurEspaceController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}



    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('formateur/sessions.html.twig', [
            'entite' => $entite,
        ]);
    }


    #[Route('/session/{id}', name: 'session_show', methods: ['GET'])]
    public function sessionShow(Entite $entite, Session $session): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        // (optionnel) vérifier que le formateur courant == $session->getFormateur()

        /** @var Utilisateur $user */
        $user = $this->getUser();
        return $this->render('formateur/session_show.html.twig', [
            'session' => $session,
            'entite' => $entite,
        ]);
    }

    // Upload de supports
    #[Route('/session/{id}/supports/upload', name: 'supports_upload', methods: ['POST'])]
    public function upload(Entite $entite, Session $session, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file) return new JsonResponse(['ok' => false], 400);
        /** @var Utilisateur $user */
        $user = $this->getUser();


        $safeName = (new \Symfony\Component\String\Slugger\AsciiSlugger())->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $finalName = sprintf('%s-%s.%s', $safeName, uniqid(), $ext);
        $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/supports', $finalName);

        $doc = (new SupportDocument())
            ->setCreateur($user)
            ->setEntite($entite)
            ->setSession($session)
            ->setFormation($session->getFormation())
            ->setTitre($request->request->get('titre') ?: $file->getClientOriginalName())
            ->setFilename($finalName)
            ->setMimeType($file->getClientMimeType())
            ->setUploadedAt(new \DateTimeImmutable('now'))
            ->setUploadedBy($this->getUser());

        $em->persist($doc);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // Signature du formateur
    #[Route('/session/{id}/sign', name: 'session_sign', methods: ['POST'])]
    public function sign(Entite $entite, Session $session, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $dataUrl = $request->request->get('dataUrl');
        $dateStr = $request->request->get('date');
        if (!$dataUrl || !$dateStr) return new JsonResponse(['ok' => false], 400);

        $jour = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        $user = $this->getUser();
        $existing = $em->getRepository(Emargement::class)->findOneBy([
            'session' => $session,
            'utilisateur' => $user,
            'jour' => $jour
        ]);
        if ($existing) return new JsonResponse(['ok' => true, 'already' => true]);

        $emarg = (new Emargement())
            ->setCreateur($user)
            ->setEntite($entite)
            ->setSession($session)
            ->setUtilisateur($user)
            ->setRole('formateur')
            ->setDateJour($jour)
            ->setSignedAt(new \DateTimeImmutable())
            ->setSignatureDataUrl($dataUrl)
            ->setIp($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($emarg);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // Export CSV émargement d'une session (par jour)
    #[Route('/session/{id}/emargement/export', name: 'emargement_export', methods: ['GET'])]
    public function export(Session $session, EntityManagerInterface $em): StreamedResponse
    {
        $emargs = $em->getRepository(Emargement::class)->findBy(['session' => $session], ['jour' => 'ASC', 'signedAt' => 'ASC']);
        $response = new StreamedResponse(function () use ($emargs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Jour', 'Nom', 'Prénom', 'Rôle', 'Signé le']);
            foreach ($emargs as $e) {
                $u = $e->getUtilisateur();
                fputcsv($out, [
                    $u->getNom(),
                    $u->getPrenom(),
                    $e->getRole(),
                    $e->getSignedAt()->format('Y-m-d H:i:s')
                ]);
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="emargement-' . $session->getCode() . '.csv"');
        return $response;
    }


    /** Retourne le premier début de jour (ou null) */
    private function firstStart(Session $s): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateDebut();
            $first = $first ? min($first, $d) : $d;
        }
        return $first;
    }

    /** Retourne la dernière fin de jour (ou null) */
    private function lastEnd(Session $s): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateFin();
            $last = $last ? max($last, $d) : $d;
        }
        return $last;
    }

    private function sessionState(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): string
    {
        $now = new \DateTimeImmutable();
        if (!$first || !$last) return 'unknown';
        if ($now < $first) return 'upcoming';
        if ($now > $last)  return 'done';
        return 'ongoing';
    }

    private function formatSessionJoursShort(Session $s, int $max = 3): string
    {
        $items = [];
        $i = 0;
        foreach ($s->getJours() as $j) {
            $items[] = $j->getDateDebut()->format('d/m H:i') . '–' . $j->getDateFin()->format('H:i');
            $i++;
            if ($i >= $max) break;
        }
        if (\count($s->getJours()) > $max) $items[] = '…';
        return implode(', ', $items);
    }

    #[Route('/sessions/ajax', name: 'sessions_ajax', methods: ['POST'])]
    public function sessionsAjax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $formateur = $user->getFormateur();

        if (!$formateur) {
            return new JsonResponse([
                'draw' => $request->request->getInt('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }

        $draw   = $request->request->getInt('draw', 1);
        $start  = max(0, $request->request->getInt('start', 0));
        $length = $request->request->getInt('length', 10);
        if ($length <= 0) $length = 10;

        $searchV = (string)($request->request->all('search')['value'] ?? '');
        $order   = $request->request->all('order') ?? [];
        $stateFilter = (string)$request->request->get('stateFilter', 'all');

        $map = [
            0 => 's.code',
            1 => 'fo.titre',
            2 => 'firstStart',
            3 => 'si.nom',
            4 => 's.capacite',
            5 => 'firstStart',
            6 => 's.id',
        ];

        $qbBase = $em->getRepository(Session::class)->createQueryBuilder('s')
            ->join('s.formation', 'fo')->addSelect('fo')
            ->leftJoin('s.site', 'si')->addSelect('si')

            // ⬇️ On peut laisser le join jours pour éviter N+1 en affichage
            ->leftJoin('s.jours', 'j')->addSelect('j')

            ->andWhere('s.formateur = :f')->setParameter('f', $formateur)
            ->andWhere('s.entite = :e')->setParameter('e', $entite)

            // ✅ Sous-requêtes corrélées : pas de GROUP BY
            ->addSelect('(SELECT MIN(j2.dateDebut) FROM App\Entity\SessionJour j2 WHERE j2.session = s) AS HIDDEN firstStart')
            ->addSelect('(SELECT MAX(j3.dateFin)   FROM App\Entity\SessionJour j3 WHERE j3.session = s) AS HIDDEN lastEnd');




        // recordsTotal : on compte les IDs distincts sans essayer de forcer getSingleScalarResult()
        $recordsTotal = (int)(clone $qbBase)
            ->select('COUNT(DISTINCT s.id)')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();


        // QB final
        $qb = clone $qbBase;

        // Search
        if ($searchV !== '') {
            $qb->andWhere('
        s.code LIKE :s
        OR fo.titre LIKE :s
        OR si.nom LIKE :s
    ')->setParameter('s', '%' . $searchV . '%');
        }

        // Filtre état via HAVING sur agrégats
        $now = new \DateTimeImmutable();

        if ($stateFilter === 'upcoming') {
            $qb->andWhere('(SELECT MIN(j2.dateDebut) FROM App\Entity\SessionJour j2 WHERE j2.session = s) > :now');
            $qb->setParameter('now', $now);
        } elseif ($stateFilter === 'done') {
            $qb->andWhere('(SELECT MAX(j3.dateFin) FROM App\Entity\SessionJour j3 WHERE j3.session = s) < :now');
            $qb->setParameter('now', $now);
        } elseif ($stateFilter === 'ongoing') {
            $qb->andWhere('(SELECT MIN(j2.dateDebut) FROM App\Entity\SessionJour j2 WHERE j2.session = s) <= :now');
            $qb->andWhere('(SELECT MAX(j3.dateFin) FROM App\Entity\SessionJour j3 WHERE j3.session = s) >= :now');
            $qb->setParameter('now', $now);
        }


        // recordsFiltered : idem (IDs distincts puis count)
        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT s.id)')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();




        $colIdx  = isset($order[0]['column']) ? (int)$order[0]['column'] : 2;
        $dir     = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        $orderBy = $map[$colIdx] ?? 'firstStart';

        // ⚠️ firstStart/lastEnd sont des HIDDEN, on peut orderBy dessus
        $rows = $qb->orderBy($orderBy, $dir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = [];
        foreach ($rows as $s) {
            /** @var Session $s */
            $first = $this->firstStart($s);
            $last  = $this->lastEnd($s);
            $state = $this->sessionState($first, $last);

            $dates = $s->getJours()->isEmpty()
                ? '—'
                : $this->formatSessionJoursShort($s) . ' '
                . ($first && $last ? sprintf('(%s → %s, %d j)', $first->format('d/m'), $last->format('d/m'), \count($s->getJours())) : '');

            $data[] = [
                'sessionId' => $s->getId(),
                'code'      => '<span class="badge bg-secondary">' . htmlspecialchars($s->getCode() ?: '—') . '</span>',
                'formation' => $s->getFormation()?->getTitre() ?: '—',
                'dates'     => $dates,
                'site'      => $s->getSite()?->getNom() ?: '—',
                'capacite'  => (string)($s->getCapacite() ?? '—'),
                'status'    => $this->renderView('formateur/_session_status_badge.html.twig', [
                    'state' => $state,
                ]),
                'actions'   => $this->renderView('formateur/_session_actions.html.twig', [
                    's'      => $s,
                    'entite' => $entite,
                    'canExport' => true,
                ]),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    #[Route('/sessions/kpis', name: 'sessions_kpis', methods: ['GET'])]
    public function sessionsKpis(Entite $entite, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $formateur = $user->getFormateur();

        if (!$formateur) {
            return new JsonResponse(['count' => 0, 'upcoming' => 0, 'ongoing' => 0, 'done' => 0]);
        }

        $now = new \DateTimeImmutable();

        $rows = $em->createQueryBuilder()
            ->from(Session::class, 's')
            ->select('s.id AS id')
            ->addSelect('MIN(j.dateDebut) AS minStart')
            ->addSelect('MAX(j.dateFin)   AS maxEnd')
            ->leftJoin('s.jours', 'j')
            ->andWhere('s.formateur = :f')->setParameter('f', $formateur)
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult();

        $count = count($rows);
        $upcoming = 0;
        $ongoing = 0;
        $done = 0;

        foreach ($rows as $r) {
            $minStart = $r['minStart'] ?? null;
            $maxEnd   = $r['maxEnd'] ?? null;

            if (!$minStart || !$maxEnd) continue;

            $minStart = $minStart instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($minStart) : new \DateTimeImmutable($minStart);
            $maxEnd   = $maxEnd   instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($maxEnd)   : new \DateTimeImmutable($maxEnd);

            if ($now < $minStart) $upcoming++;
            elseif ($now > $maxEnd) $done++;
            else $ongoing++;
        }

        return new JsonResponse([
            'count'    => $count,
            'upcoming' => $upcoming,
            'ongoing'  => $ongoing,
            'done'     => $done,
        ]);
    }
}
