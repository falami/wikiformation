<?php

namespace App\Controller\Administrateur;

use App\Entity\{Qcm, Entite, Utilisateur, QcmAssignment, QcmQuestion, QcmOption};
use App\Form\Administrateur\QcmType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\Pdf\PdfManager;
use Symfony\Component\Form\FormInterface;
use App\Security\Permission\TenantPermission;




#[Route('/administrateur/{entite}/qcm', name: 'app_administrateur_qcm_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QCM_MANAGE, subject: 'entite')]
final class QcmController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private PdfManager $pdfManager,
  ) {}




  #[Route(
    '/assignments/{assignment}/result.pdf',
    name: 'assignment_result_pdf',
    requirements: ['entite' => '\d+', 'assignment' => '\d+']
  )]
  public function qcmResultPdf(
    Entite $entite,
    QcmAssignment $assignment,
    PdfManager $pdf
  ): Response {


    $session = $assignment->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

    $attempt = $assignment->getAttempt();
    if (!$attempt || !$attempt->getSubmittedAt()) {
      throw $this->createNotFoundException(); // ou une page “pas encore soumis”
    }

    $qcm = $assignment->getQcm();
    $ins = $assignment->getInscription();
    $stagiaire = $ins?->getStagiaire();

    // construire items (question + choices + selected + correct)
    $items = [];
    foreach ($qcm->getQuestions() as $q) {
      $choices = $q->getOptions();
      // retrouver réponse:
      $ans = null;
      foreach ($attempt->getAnswers() as $a) {
        if ($a->getQuestion()?->getId() === $q->getId()) {
          $ans = $a;
          break;
        }
      }

      $selectedIds = $ans ? $ans->getSelectedOptionIds() : [];
      $correctIds  = $q->getCorrectOptionIds();

      // “isCorrect” simple : ids identiques
      $isCorrect = ($selectedIds === $correctIds);

      $items[] = [
        'question' => $q,
        'choices' => $choices,
        'selected' => $selectedIds,
        'correctIds' => $correctIds,
        'isCorrect' => $isCorrect,
      ];
    }

    $html = $this->renderView('pdf/qcm_result.html.twig', [
      'entite' => $entite,
      'session' => $session,
      'qcm' => $qcm,
      'a' => $assignment,
      'attempt' => $attempt,
      'stagiaire' => $stagiaire,
      'items' => $items,
      'scorePoints' => $attempt->getScorePoints(),
      'maxPoints' => $attempt->getMaxPoints(),
      'pct' => (int) round($attempt->getScorePercent()),
      'generatedAt' => new \DateTimeImmutable(),
    ]);


    $stagiaire = $assignment->getInscription()?->getStagiaire();

    $prenom = $stagiaire?->getPrenom() ?? 'PRENOM';
    $nom    = $stagiaire?->getNom() ?? 'NOM';

    // PRE/POST
    $phase = strtoupper($assignment->getPhase()->value);

    // MAJ + accents OK
    $full = mb_strtoupper($prenom . '_' . $nom, 'UTF-8');

    // filename safe
    $full = preg_replace('/[^A-Z0-9_-]+/u', '_', $full);
    $full = trim($full, '_');

    $codeSession = (string)($session?->getCode() ?? 'SESSION');
    $codeSession = preg_replace('/[^A-Za-z0-9_-]+/', '_', $codeSession);

    $filename = sprintf('RESULTAT_QCM_%s_%s_%s', $codeSession, $phase, $full);

    return $pdf->createPortrait($html, $filename);
  }



  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $qcms = $em->getRepository(Qcm::class)->findBy(['entite' => $entite], ['id' => 'DESC']);

    return $this->render('administrateur/qcm/index.html.twig', [
      'entite' => $entite,
      'qcms' => $qcms,

    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $qcm = (new Qcm())->setCreateur($user)->setEntite($entite);

    $form = $this->createForm(QcmType::class, $qcm);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->handleUploads($form, $qcm);


      /** @var Utilisateur $user */
      $user = $this->getUser();

      foreach ($qcm->getQuestions() as $question) {
        $question->setQcm($qcm);

        // ✅ IMPORTANT : sur une question nouvellement ajoutée
        if (!$question->getCreateur()) {
          $question->setCreateur($user);
        }
        if (!$question->getEntite()) {
          $question->setEntite($entite); // ou $qcm->getEntite()
        }

        foreach ($question->getOptions() as $option) {
          $option->setQuestion($question);

          // ✅ IMPORTANT : sur une option nouvellement ajoutée
          if (!$option->getCreateur()) {
            $option->setCreateur($user);
          }
          if (!$option->getEntite()) {
            $option->setEntite($entite); // ou $qcm->getEntite()
          }
        }
      }




      $em->persist($qcm);
      $em->flush();

      $this->addFlash('success', 'QCM créé.');
      return $this->redirectToRoute('app_administrateur_qcm_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/qcm/form.html.twig', [
      'entite' => $entite,
      'form' => $form,
      'qcm' => $qcm,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Qcm $qcm, Request $req, EM $em): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();
    $form = $this->createForm(QcmType::class, $qcm);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->handleUploads($form, $qcm);

      /** @var Utilisateur $user */
      $user = $this->getUser();

      foreach ($qcm->getQuestions() as $question) {
        $question->setQcm($qcm);

        // ✅ IMPORTANT : sur une question nouvellement ajoutée
        if (!$question->getCreateur()) {
          $question->setCreateur($user);
        }
        if (!$question->getEntite()) {
          $question->setEntite($entite); // ou $qcm->getEntite()
        }

        foreach ($question->getOptions() as $option) {
          $option->setQuestion($question);

          // ✅ IMPORTANT : sur une option nouvellement ajoutée
          if (!$option->getCreateur()) {
            $option->setCreateur($user);
          }
          if (!$option->getEntite()) {
            $option->setEntite($entite); // ou $qcm->getEntite()
          }
        }
      }


      $em->flush();
      $this->addFlash('success', 'QCM mis à jour.');
      return $this->redirectToRoute('app_administrateur_qcm_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/qcm/form.html.twig', [
      'entite' => $entite,
      'form' => $form,
      'qcm' => $qcm,
    ]);
  }

  private function handleUploads(FormInterface $form, Qcm $qcm): void
  {
    $baseQ = $this->getParameter('kernel.project_dir') . '/public/uploads/qcm/questions';
    $baseO = $this->getParameter('kernel.project_dir') . '/public/uploads/qcm/options';
    @mkdir($baseQ, 0775, true);
    @mkdir($baseO, 0775, true);

    // ✅ Parcours des QUESTIONS via le FORM (source de vérité)
    foreach ($form->get('questions') as $qForm) {

      /** @var QcmQuestion|null $question */
      $question = $qForm->getData();
      if (!$question) {
        continue;
      }

      /** @var UploadedFile|null $file */
      $file = $qForm->has('imageFile') ? $qForm->get('imageFile')->getData() : null;

      if ($file instanceof UploadedFile) {
        $name = 'q_' . bin2hex(random_bytes(8)) . '.' . ($file->guessExtension() ?: 'bin');
        $file->move($baseQ, $name);
        $question->setImage($name);
      }

      // ✅ Parcours des OPTIONS via le FORM (plus d'index $oi)
      if (!$qForm->has('options')) {
        continue;
      }

      foreach ($qForm->get('options') as $oForm) {

        /** @var QcmOption|null $option */
        $option = $oForm->getData();
        if (!$option) {
          continue;
        }

        /** @var UploadedFile|null $of */
        $of = $oForm->has('imageFile') ? $oForm->get('imageFile')->getData() : null;

        if ($of instanceof UploadedFile) {
          $name = 'o_' . bin2hex(random_bytes(8)) . '.' . ($of->guessExtension() ?: 'bin');
          $of->move($baseO, $name);
          $option->setImage($name);
        }
      }
    }
  }


  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em): JsonResponse
  {
    $draw  = (int) $req->request->get('draw', 1);
    $start = max(0, (int) $req->request->get('start', 0));
    $len   = (int) $req->request->get('length', 25);
    $len   = ($len <= 0) ? 25 : min($len, 500);

    $search = trim((string) ($req->request->all('search')['value'] ?? ''));
    $activeFilter = (string) $req->request->get('activeFilter', 'all'); // all|1|0

    $conn = $em->getConnection();
    $params = ['entite' => $entite->getId()];
    $where = "q.entite_id = :entite";

    if ($activeFilter === '1' || $activeFilter === '0') {
      $where .= " AND q.is_active = :active";
      $params['active'] = (int)$activeFilter;
    }

    if ($search !== '') {
      $where .= " AND (CAST(q.id AS CHAR) LIKE :s OR q.titre LIKE :s)";
      $params['s'] = '%' . $search . '%';
    }

    // Total (sans search, sans filtre actif ? -> on garde le filtre entité uniquement)
    $total = (int) $conn->fetchOne(
      "SELECT COUNT(*) FROM qcm q WHERE q.entite_id = :entite",
      ['entite' => $entite->getId()]
    );

    // Total filtré
    $filtered = (int) $conn->fetchOne(
      "SELECT COUNT(*) FROM qcm q WHERE $where",
      $params
    );

    // Order (DataTables)
    $orderCol = (int) ($req->request->all('order')[0]['column'] ?? 0);
    $orderDir = strtolower((string) ($req->request->all('order')[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    // Mapping colonnes DataTables -> SQL
    $columns = [
      0 => 'q.id',
      1 => 'q.titre',
      2 => 'questions_count', // tri via alias
      3 => 'q.is_active',
    ];
    $orderBy = $columns[$orderCol] ?? 'q.id';

    $sql = "
        SELECT
          q.id,
          q.titre,
          q.is_active,
          COUNT(DISTINCT qu.id) AS questions_count
        FROM qcm q
        LEFT JOIN qcm_question qu ON qu.qcm_id = q.id
        WHERE $where
        GROUP BY q.id
        ORDER BY $orderBy $orderDir
        LIMIT :lim OFFSET :off
    ";

    // LIMIT/OFFSET doivent être bind en int
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('lim', $len, \PDO::PARAM_INT);
    $stmt->bindValue('off', $start, \PDO::PARAM_INT);

    $rows = $stmt->executeQuery()->fetchAllAssociative();

    $data = array_map(function (array $r) use ($entite) {
      $id = (int)$r['id'];
      $titre = (string)($r['titre'] ?? '');
      $isActive = (int)($r['is_active'] ?? 0) === 1;
      $qc = (int)($r['questions_count'] ?? 0);

      $activeHtml = $isActive
        ? '<span class="badge-soft good"><i class="bi bi-check2-circle"></i> Oui</span>'
        : '<span class="badge-soft off"><i class="bi bi-slash-circle"></i> Non</span>';

      $actions = sprintf(
        '<a class="btn btn-sm btn-outline-primary" href="%s"><i class="bi bi-pencil"></i> Modifier</a>',
        $this->generateUrl('app_administrateur_qcm_edit', ['entite' => $entite->getId(), 'id' => $id])
      );

      $titleHtml = sprintf(
        '<div class="qcm-title">%s</div>
             <div class="qcm-sub"><i class="bi bi-building"></i> %s <span class="mx-2">•</span><i class="bi bi-ui-checks"></i> Questionnaire</div>',
        htmlspecialchars($titre !== '' ? $titre : '—', ENT_QUOTES),
        htmlspecialchars($entite->getNom(), ENT_QUOTES)
      );

      return [
        'id' => '<span class="mini-chip"><i class="bi bi-hash"></i> ' . $id . '</span>',
        'titre' => $titleHtml,
        'questions' => '<span class="mini-chip"><i class="bi bi-question-circle"></i> ' . $qc . '</span>',
        'active' => $activeHtml,
        'actions' => $actions,
      ];
    }, $rows);

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => $data,
    ]);
  }

  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function kpis(Entite $entite, Request $req, EM $em): JsonResponse
  {
    $activeFilter = (string) $req->query->get('active', 'all'); // all|1|0
    $search = trim((string) $req->query->get('q', ''));

    $conn = $em->getConnection();
    $params = ['entite' => $entite->getId()];
    $where = "q.entite_id = :entite";

    if ($activeFilter === '1' || $activeFilter === '0') {
      $where .= " AND q.is_active = :active";
      $params['active'] = (int)$activeFilter;
    }
    if ($search !== '') {
      $where .= " AND (CAST(q.id AS CHAR) LIKE :s OR q.titre LIKE :s)";
      $params['s'] = '%' . $search . '%';
    }

    $sql = "
      SELECT
        COUNT(DISTINCT q.id) AS total,
        SUM(CASE WHEN q.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        COUNT(qu.id) AS questions_total
      FROM qcm q
      LEFT JOIN qcm_question qu ON qu.qcm_id = q.id
      WHERE $where
    ";

    $k = $conn->fetchAssociative($sql, $params) ?: ['total' => 0, 'active_count' => 0, 'questions_total' => 0];

    $total = (int)($k['total'] ?? 0);
    $active = (int)($k['active_count'] ?? 0);
    $questions = (int)($k['questions_total'] ?? 0);
    $avg = $total > 0 ? round($questions / $total, 1) : 0.0;

    return $this->json([
      'total' => $total,
      'active' => $active,
      'questions' => $questions,
      'avg' => $avg,
    ]);
  }
}
