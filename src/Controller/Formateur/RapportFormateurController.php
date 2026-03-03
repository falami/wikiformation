<?php

namespace App\Controller\Formateur;

use App\Entity\{RapportFormateur, Entite, Utilisateur, Formateur, Session};
use App\Form\Formateur\RapportFormateurType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\QueryBuilder;

#[Route('/formateur/{entite}/rapport', name: 'app_formateur_rapport_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_RAPPORT_MANAGE, subject: 'entite')]
class RapportFormateurController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}

    #[Route('/liste', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        return $this->render('formateur/rapport/index.html.twig', [
            'entite' => $entite,
        ]);
    }

    /**
     * DataTables server-side
     */
    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EM $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $draw   = (int) $request->request->get('draw', 1);
        $start  = max(0, (int) $request->request->get('start', 0));
        $length = (int) $request->request->get('length', 10);
        $length = ($length <= 0) ? 10 : min($length, 500);

        $searchValue = trim((string) ($request->request->all('search')['value'] ?? ''));

        // Filtres custom (toolbar)
        $dateFrom = trim((string) $request->request->get('dateFrom', '')); // YYYY-MM-DD
        $dateTo   = trim((string) $request->request->get('dateTo', ''));   // YYYY-MM-DD
        $hasCom   = (string) $request->request->get('hasCommentaires', 'all'); // all|yes|no
        $hasCrit  = (string) $request->request->get('hasCriteres', 'all');     // all|yes|no

        // Tri
        $order = $request->request->all('order');
        $columns = $request->request->all('columns');
        $orderColIndex = (int)($order[0]['column'] ?? 3);
        $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderColData = (string)($columns[$orderColIndex]['data'] ?? 'submittedAt');

        $formateur = $this->getCurrentFormateur($em);
        $qb = $this->baseQb($em, $entite, $formateur);
        $this->applyFilters($qb, $dateFrom, $dateTo, $hasCom, $hasCrit);
        $recordsTotal = (int) $this->countQb(clone $qb)->getQuery()->getSingleScalarResult();

        // Search
        if ($searchValue !== '') {
            $qb->andWhere('CAST(r.id as string) LIKE :q OR s.code LIKE :q OR u.email LIKE :q')
                ->setParameter('q', '%' . $searchValue . '%');
        }

        $recordsFiltered = (int) $this->countQb(clone $qb)->getQuery()->getSingleScalarResult();

        // Mapping colonnes DataTables -> champs DB
        $orderMap = [
            'id'          => 'r.id',
            'session'     => 's.code',
            'formateur'   => 'u.email',
            'submittedAt' => 'r.submittedAt',
        ];
        $orderBy = $orderMap[$orderColData] ?? 'r.submittedAt';
        $qb->orderBy($orderBy, $orderDir);

        $qb->setFirstResult($start)
            ->setMaxResults($length);

        $rows = $qb->getQuery()->getResult(); // array results (select custom)

        $data = array_map(static function (array $r): array {
            $submittedAt = $r['submittedAt'] instanceof \DateTimeInterface
                ? $r['submittedAt']->format('d/m/Y H:i')
                : '-';

            $comment = (string)($r['commentaires'] ?? '');
            $commentShort = mb_strlen($comment) > 90 ? (mb_substr($comment, 0, 90) . '…') : $comment;

            $critCount = is_array($r['criteres'] ?? null) ? count($r['criteres']) : 0;

            return [
                'id'          => $r['id'],
                'session'     => $r['sessionCode'] ?? '-',
                'formateur'   => $r['formateurEmail'] ?? ('#' . $r['formateurId']),
                'submittedAt' => $submittedAt,
                'comment'     => $commentShort !== '' ? $commentShort : '—',
                // données pour modal
                'commentFull' => $comment !== '' ? $comment : '',
                'critCount'   => $critCount,
                'criteres'    => $r['criteres'] ?? null,
                'actions'     => '', // rempli côté JS (bouton modal)
            ];
        }, $rows);

        return $this->json([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * KPI (respecte les mêmes filtres que la toolbar)
     */
    #[Route('/kpis', name: 'kpis', methods: ['GET'])]
    public function kpis(Entite $entite, Request $request, EM $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $dateFrom = trim((string) $request->query->get('dateFrom', ''));
        $dateTo   = trim((string) $request->query->get('dateTo', ''));
        $hasCom   = (string) $request->query->get('hasCommentaires', 'all');
        $hasCrit  = (string) $request->query->get('hasCriteres', 'all');

        $formateur = $this->getCurrentFormateur($em);
        $qb = $this->baseQb($em, $entite, $formateur);
        $this->applyFilters($qb, $dateFrom, $dateTo, $hasCom, $hasCrit);

        // Total filtré
        $count = (int) $this->countQb(clone $qb)->getQuery()->getSingleScalarResult();

        // Avec commentaires
        $qbCom = clone $qb;
        $qbCom->andWhere('r.commentaires IS NOT NULL AND r.commentaires <> \'\'');
        $withCommentaires = (int) $this->countQb($qbCom)->getQuery()->getSingleScalarResult();

        // Avec critères (JSON array non null)
        $qbCrit = clone $qb;
        $qbCrit->andWhere('r.criteres IS NOT NULL');
        $withCriteres = (int) $this->countQb($qbCrit)->getQuery()->getSingleScalarResult();

        // Derniers 30 jours (en plus des filtres)
        $qb30 = clone $qb;
        $qb30->andWhere('r.submittedAt >= :d30')
            ->setParameter('d30', new \DateTimeImmutable('-30 days'));
        $last30 = (int) $this->countQb($qb30)->getQuery()->getSingleScalarResult();

        return $this->json([
            'count'            => $count,
            'withCommentaires' => $withCommentaires,
            'withCriteres'     => $withCriteres,
            'last30'           => $last30,
        ]);
    }

    private function baseQb(EM $em, Entite $entite, ?Formateur $formateur): QueryBuilder
    {
        $qb = $em->createQueryBuilder()
            ->select('r.id AS id')
            ->addSelect('r.submittedAt AS submittedAt')
            ->addSelect('r.commentaires AS commentaires')
            ->addSelect('r.criteres AS criteres')
            ->addSelect('s.code AS sessionCode')
            ->addSelect('f.id AS formateurId')
            ->addSelect('u.email AS formateurEmail')
            ->from(RapportFormateur::class, 'r')
            ->leftJoin('r.session', 's')
            ->leftJoin('r.formateur', 'f')
            ->leftJoin('f.utilisateur', 'u')
            ->andWhere('r.entite = :entite')
            ->setParameter('entite', $entite);

        // ✅ Filtre “formateur courant”
        if ($formateur) {
            $qb->andWhere('r.formateur = :formateur')
                ->setParameter('formateur', $formateur);
        } else {
            // si aucun Formateur trouvé, on ne retourne rien
            $qb->andWhere('1 = 0');
        }

        return $qb;
    }

    private function applyFilters(QueryBuilder $qb, string $dateFrom, string $dateTo, string $hasCom, string $hasCrit): void
    {
        // dateFrom/dateTo sur submittedAt
        if ($dateFrom !== '') {
            try {
                $qb->andWhere('r.submittedAt >= :df')
                    ->setParameter('df', new \DateTimeImmutable($dateFrom . ' 00:00:00'));
            } catch (\Throwable) {
            }
        }
        if ($dateTo !== '') {
            try {
                $qb->andWhere('r.submittedAt <= :dt')
                    ->setParameter('dt', new \DateTimeImmutable($dateTo . ' 23:59:59'));
            } catch (\Throwable) {
            }
        }

        if ($hasCom === 'yes') {
            $qb->andWhere('r.commentaires IS NOT NULL AND r.commentaires <> \'\'');
        } elseif ($hasCom === 'no') {
            $qb->andWhere('(r.commentaires IS NULL OR r.commentaires = \'\')');
        }

        if ($hasCrit === 'yes') {
            $qb->andWhere('r.criteres IS NOT NULL');
        } elseif ($hasCrit === 'no') {
            $qb->andWhere('r.criteres IS NULL');
        }
    }

    private function countQb(QueryBuilder $qb): QueryBuilder
    {
        return $qb->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(DISTINCT r.id)');
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Entite $entite, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formateur = $em->getRepository(Formateur::class)->findOneBy([
            'utilisateur' => $user,
        ]);

        $r = new RapportFormateur();
        $r->setCreateur($user);
        $r->setEntite($entite);
        $r->setFormateur($formateur);
        $r->setSubmittedAt(new \DateTimeImmutable());

        // ✅ Pré-remplissage session via ?session=ID
        $sessionId = $req->query->get('session');
        if ($sessionId) {
            $session = $em->getRepository(Session::class)->find($sessionId);
            if ($session && $session->getEntite()->getId() === $entite->getId()) {
                $r->setSession($session);
            }
        }

        $form = $this->createForm(RapportFormateurType::class, $r)
            ->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($r);
            $em->flush();

            $this->addFlash('success', 'Rapport formateur enregistré.');

            return $this->redirectToRoute('app_formateur_rapport_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('formateur/rapport/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau rapport',
            'entite' => $entite,
        ]);
    }

    private function getCurrentFormateur(EM $em): ?Formateur
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) return null;

        return $em->getRepository(Formateur::class)->findOneBy([
            'utilisateur' => $user,
        ]);
    }
}
