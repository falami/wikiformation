<?php

namespace App\Controller\Administrateur;

use App\Entity\{QuestionnaireSatisfaction, Entite, Utilisateur, SatisfactionAttempt};
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;




#[Route('/administrateur/{entite}/satisfaction', name: 'app_administrateur_satisfaction_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_MANAGE, subject: 'entite')]
class SatisfactionController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}

    #[Route('/liste', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/satisfaction/index.html.twig', [
            'entite' => $entite,

        ]);
    }

    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $req, EM $em): JsonResponse
    {
        $draw   = (int) $req->request->get('draw', 1);
        $start  = max(0, (int) $req->request->get('start', 0));
        $length = (int) $req->request->get('length', 10);
        if ($length <= 0) $length = 10;

        $searchValue = trim((string)(($req->request->all('search')['value'] ?? '') ?: ''));

        $order = $req->request->all('order')[0] ?? null;
        $colIndex = isset($order['column']) ? (int)$order['column'] : 0;
        $dir = strtolower((string)($order['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Base query
        $qb = $em->createQueryBuilder()
            ->select('q', 's', 'f')
            ->from(QuestionnaireSatisfaction::class, 'q')
            ->leftJoin('q.session', 's')
            ->leftJoin('s.formation', 'f')
            ->andWhere('q.entite = :entite')
            ->setParameter('entite', $entite);

        // Total
        $totalQb = clone $qb;
        $recordsTotal = (int) $totalQb
            ->select('COUNT(q.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        // Search
        if ($searchValue !== '') {
            $sLike = '%' . mb_strtolower($searchValue) . '%';
            $qb->andWhere(
                "LOWER(COALESCE(s.code,'')) LIKE :s
             OR LOWER(COALESCE(f.titre, s.formationIntituleLibre, '')) LIKE :s
             OR LOWER(COALESCE(q.type, '')) LIKE :s"
            )->setParameter('s', $sLike);
        }

        // Filtered
        $filteredQb = clone $qb;
        $recordsFiltered = (int) $filteredQb
            ->select('COUNT(q.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        // Ordering mapping (col index => DQL field)
        $orderMap = [
            0 => 's.code',
            1 => 'f.titre',
            2 => 'q.submittedAt',
            3 => 'q.id',
        ];
        $orderBy = $orderMap[$colIndex] ?? 'q.submittedAt';
        $qb->orderBy($orderBy, $dir);

        // Pagination
        $qb->setFirstResult($start)->setMaxResults($length);

        $rows = $qb->getQuery()->getResult();

        $data = [];
        foreach ($rows as $q) {
            /** @var QuestionnaireSatisfaction $q */
            $session = $q->getSession();
            $formation = $session?->getFormation();

            $submitted = $q->getSubmittedAt();
            $started   = $q->getStartedAt();

            $statusHtml = $submitted
                ? '<span class="badge bg-success">Soumis</span>'
                : ($started
                    ? '<span class="badge bg-primary">En cours</span>'
                    : '<span class="badge bg-light text-dark border">Non démarré</span>');

            $sessionHtml = '<div class="fw-semibold">'
                . htmlspecialchars($session?->getCode() ?? ('Session #' . ($session?->getId() ?? '—')))
                . '</div>';

            // (si tu as dateDebut/dateFin dans Session, tu peux remettre ici comme avant)
            $sessionHtml .= '<div class="text-muted small">'
                . 'Type : ' . htmlspecialchars($q->getType()->value ?? (string)$q->getType())
                . '</div>';

            $formationTitle = $formation?->getTitre() ?? $session?->getFormationIntituleLibre() ?? '—';
            $formationHtml = '<div class="fw-semibold">' . htmlspecialchars($formationTitle) . '</div>'
                . '<div class="text-muted small">Stagiaire : '
                . htmlspecialchars($q->getStagiaire()?->getNom() ?? $q->getStagiaire()?->getEmail() ?? '—')
                . '</div>';

            // 🔗 URL remplissage/lecture (à adapter à ta vraie route)
            // Ici on part sur une route stagiaire de type: app_stagiaire_satisfaction_fill(entite, questionnaire)
            $urlFill = $this->generateUrl('app_stagiaire_satisfaction_fill', [
                'entite' => $entite->getId(),
                'questionnaire' => $q->getId(),
            ]);

            $actionsHtml = '<div class="btn-group btn-group-sm satisfaction-actions" role="group">'
                . '<a class="btn btn-light satisfaction-main ' . ($submitted ? '' : 'btn-warning') . '"'
                . ' href="' . $urlFill . '"'
                . ' title="' . ($submitted ? 'Voir' : 'Remplir') . '">'
                . ($submitted ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-pencil-square"></i>')
                . '</a>'

                . '<button type="button" class="btn btn-light dropdown-toggle dropdown-toggle-split satisfaction-split"'
                . ' data-bs-toggle="dropdown" aria-expanded="false">'
                . '<span class="visually-hidden">Actions</span></button>'

                . '<ul class="dropdown-menu dropdown-menu-end satisfaction-menu">'
                . '<li><a class="dropdown-item d-flex align-items-center" href="' . $urlFill . '">'
                . ($submitted ? '<i class="bi bi-eye me-2"></i> Voir' : '<i class="bi bi-pencil-square me-2"></i> Remplir')
                . '</a></li>';

            // Option admin lecture (ex: une future route admin show questionnaire)
            if ($submitted) {
                // ⚠️ mets la route si tu la crées (sinon tu peux enlever ce bloc)
                $urlAdmin = $this->generateUrl('app_administrateur_satisfaction_questionnaire_show', [
                    'entite' => $entite,
                    'id' => $q->getId(),
                ]);

                $actionsHtml .= '<li><a class="dropdown-item d-flex align-items-center" href="' . $urlAdmin . '">'
                    . '<i class="bi bi-clipboard-data me-2"></i> Lecture admin</a></li>';
            }

            $actionsHtml .= '</ul></div>';

            $data[] = [
                'session'   => $sessionHtml,
                'formation' => $formationHtml,
                'status'    => $statusHtml,
                'actions'   => $actionsHtml,
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }


    // ton attempt_show reste inchangé
    #[Route('/attempts/{attempt}', name: 'attempt_show', requirements: ['attempt' => '\d+'], methods: ['GET'])]
    public function show(Entite $entite, SatisfactionAttempt $attempt): Response
    {
        $sessionEntiteId = $attempt->getAssignment()?->getSession()?->getEntite()?->getId();
        if ($sessionEntiteId !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('administrateur/satisfaction/show.html.twig', [
            'entite' => $entite,
            'attempt' => $attempt,
            'assignment' => $attempt->getAssignment(),
            'template' => $attempt->getAssignment()?->getTemplate(),
        ]);
    }
}
