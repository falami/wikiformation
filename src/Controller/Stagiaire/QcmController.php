<?php

namespace App\Controller\Stagiaire;

use App\Entity\{QcmAttempt, Entite, Utilisateur, QcmAnswer, QcmAssignment};
use App\Enum\QcmAssignmentStatus;
use App\Enum\QcmPhase;
use App\Repository\QcmAssignmentRepository;
use App\Service\Qcm\QcmScoringService;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Service\Pdf\PdfManager;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/stagiaire/{entite}/qcm', name: 'app_stagiaire_qcm_')]
#[IsGranted(TenantPermission::STAGIAIRE_QCM_MANAGE, subject: 'entite')]
final class QcmController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, QcmAssignmentRepository $repo): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $assignments = $repo->findForStagiaire($user->getId());

    // ✅ Comparaison PRE vs POST par session
    $comparisons = [];

    foreach ($assignments as $a) {
      $session = $a->getSession();
      if (!$session || !$session->getId()) continue;

      $sid = (int) $session->getId();

      $comparisons[$sid] ??= [
        'sessionId' => $sid,
        'sessionLabel' => $session->getLabel(), // ✅ existe chez toi
        'pre' => null,
        'post' => null,
        'delta' => null,
        'trend' => 'na',          // up | down | same | na
        'ready' => false,         // ✅ true seulement si PRE+POST soumis
        'missing' => [],          // ex: ['pre'] ou ['post']
        'actionRequired' => false // true si post < pre
      ];

      $attempt = $a->getAttempt();
      $isSubmitted = $attempt && $attempt->getSubmittedAt(); // + robuste que a->isSubmitted si besoin
      if (!$isSubmitted) {
        // pas soumis => ne compte pas pour la comparaison
        continue;
      }

      $pct = $attempt->getScorePercent(); // int|null

      if ($a->getPhase() === QcmPhase::PRE) {
        // si plusieurs PRE soumis, on garde le meilleur (tu peux changer pour "dernier" si tu veux)
        $comparisons[$sid]['pre'] = ($comparisons[$sid]['pre'] === null) ? $pct : max((int)$comparisons[$sid]['pre'], (int)$pct);
      }

      if ($a->getPhase() === QcmPhase::POST) {
        $comparisons[$sid]['post'] = ($comparisons[$sid]['post'] === null) ? $pct : max((int)$comparisons[$sid]['post'], (int)$pct);
      }
    }

    // Calcul final : UNIQUEMENT si les 2 sont remplis
    foreach ($comparisons as $sid => $c) {
      $missing = [];
      if ($c['pre'] === null)  $missing[] = 'pre';
      if ($c['post'] === null) $missing[] = 'post';

      $comparisons[$sid]['missing'] = $missing;

      if (count($missing) === 0) {
        $comparisons[$sid]['ready'] = true;

        $delta = (int)$c['post'] - (int)$c['pre'];
        $comparisons[$sid]['delta'] = $delta;

        if ($delta > 0) $comparisons[$sid]['trend'] = 'up';
        elseif ($delta < 0) $comparisons[$sid]['trend'] = 'down';
        else $comparisons[$sid]['trend'] = 'same';

        // 🔥 Plan d'action requis si post < pre
        $comparisons[$sid]['actionRequired'] = ($delta < 0);
      }
    }

    return $this->render('stagiaire/qcm/index.html.twig', [
      'assignments' => $assignments,
      'comparisons' => $comparisons, // ✅ AJOUTE ÇA
      'entite' => $entite,


    ]);
  }



  #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
  public function show(Entite $entite, QcmAssignment $a, Request $req, EM $em, QcmScoringService $scoring, QcmAssignmentRepository $repo): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    if (!$a->getInscription() || $a->getInscription()->getStagiaire()?->getId() !== $user->getId()) {
      throw $this->createAccessDeniedException();
    }

    if ($a->isSubmitted()) {
      if ($a->isSubmitted()) {

        // ⚠️ Important: éviter les lazy-load surprises si tu es hors transaction
        $attempt = $a->getAttempt();
        if (!$attempt) {
          throw $this->createNotFoundException('Tentative introuvable');
        }

        // Map answers par questionId (plus rapide pour retrouver la réponse d’une question)
        $answersByQid = [];
        foreach ($attempt->getAnswers() as $ans) {
          if ($ans->getQuestion()?->getId()) {
            $answersByQid[$ans->getQuestion()->getId()] = $ans;
          }
        }

        $items = [];
        $qcm = $a->getQcm();

        foreach ($qcm->getQuestions() as $question) {
          $qid = $question->getId();
          if (!$qid) continue;

          $ans = $answersByQid[$qid] ?? null;

          // IDs sélectionnés par l’utilisateur
          $selectedIds = [];
          if ($ans) {
            foreach ($ans->getSelectedOptions() as $opt) {
              if ($opt->getId()) {
                $selectedIds[] = (int) $opt->getId();
              }
            }
          }
          $selectedIds = array_values(array_unique($selectedIds));

          // IDs des bonnes réponses
          $correctIds = [];
          foreach ($question->getOptions() as $opt) {
            if ($opt->getId() && $opt->isCorrect()) { // ✅ adapte si ton bool s'appelle autrement
              $correctIds[] = (int) $opt->getId();
            }
          }
          sort($correctIds);
          $sortedSelected = $selectedIds;
          sort($sortedSelected);

          // Correct si exactement le même ensemble (gère multi-choix)
          $isCorrect = ($sortedSelected === $correctIds);

          $items[] = [
            'question' => $question,
            'choices' => $question->getOptions(),   // le template itère dessus
            'selected' => $selectedIds,             // le template accepte une liste d'IDs
            'isCorrect' => $isCorrect,
          ];
        }

        return $this->render('stagiaire/qcm/result.html.twig', [
          'a' => $a,
          'items' => $items, // ✅ c'est ça qu'il te manquait
          'entite' => $entite,

        ]);
      }
    }

    $attempt = $a->getAttempt();
    if (!$attempt) {
      $attempt = (new QcmAttempt())->setAssignment($a)
        ->setCreateur($user)
        ->setEntite($entite);
      $a->setAttempt($attempt);
      $a->setStatus(QcmAssignmentStatus::STARTED);
      $em->persist($attempt);
      $em->flush();
    }

    if ($req->isMethod('POST')) {
      $this->denyAccessUnlessGranted('ROLE_USER');
      if (!$this->isCsrfTokenValid('qcm_submit_' . $a->getId(), (string)$req->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide');
      }

      // wipe existing answers (robuste si re-submit avant submit final)
      foreach ($attempt->getAnswers() as $old) {
        $em->remove($old);
      }
      $em->flush();

      $payload = $req->request->all('q'); // q[questionId]=optionId or array
      $qcm = $a->getQcm();

      foreach ($qcm->getQuestions() as $question) {
        $qid = $question->getId();
        if (!$qid) continue;

        $val = $payload[$qid] ?? null;
        $selectedIds = is_array($val) ? array_map('intval', $val) : (isset($val) ? [(int)$val] : []);
        $selectedIds = array_values(array_unique(array_filter($selectedIds, fn($x) => $x > 0)));

        $selectedOptions = [];
        foreach ($question->getOptions() as $opt) {
          if ($opt->getId() && in_array($opt->getId(), $selectedIds, true)) {
            $selectedOptions[] = $opt;
          }
        }

        $ans = (new QcmAnswer())
          ->setCreateur($user)
          ->setEntite($entite)
          ->setQuestion($question)
          ->setSelectedOptions($selectedOptions);

        $attempt->addAnswer($ans);
        $em->persist($ans);
      }

      $attempt->setSubmittedAt(new \DateTimeImmutable());
      $a->setSubmittedAt(new \DateTimeImmutable());

      $scoring->computeAttemptScore($attempt);

      // règle "fin >= début"
      $a->setStatus(QcmAssignmentStatus::SUBMITTED);

      if ($a->getPhase() === QcmPhase::POST) {
        $pre = $repo->findOneByInscriptionAndPhase($a->getInscription(), QcmPhase::PRE);
        $preScore = $pre?->getAttempt()?->getScorePoints();
        if ($preScore !== null && $attempt->getScorePoints() < $preScore) {
          $a->setStatus(QcmAssignmentStatus::REVIEW_REQUIRED);
        } else {
          $a->setStatus(QcmAssignmentStatus::VALIDATED);
        }
      }

      $em->flush();

      return $this->redirectToRoute('app_stagiaire_qcm_show', [
        'id' => $a->getId(),
        'entite' => $entite->getId(),

      ]);
    }

    return $this->render('stagiaire/qcm/show.html.twig', [
      'a' => $a,
      'attempt' => $attempt,
      'entite' => $entite,

    ]);
  }



  #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function pdf(
    Entite $entite,
    QcmAssignment $a,
    EM $em,
    PdfManager $pdfManager,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // Sécurité : le PDF ne doit être accessible que par le stagiaire de l'inscription
    if (!$a->getInscription() || $a->getInscription()->getStagiaire()?->getId() !== $user->getId()) {
      throw $this->createAccessDeniedException();
    }

    // On n'exporte que si soumis
    if (!$a->isSubmitted() || !$a->getAttempt() || !$a->getAttempt()->getSubmittedAt()) {
      throw $this->createAccessDeniedException('QCM non soumis.');
    }

    $attempt = $a->getAttempt();
    $qcm = $a->getQcm();
    $session = $a->getSession();

    // Map answers par questionId
    $answersByQid = [];
    foreach ($attempt->getAnswers() as $ans) {
      if ($ans->getQuestion()?->getId()) {
        $answersByQid[$ans->getQuestion()->getId()] = $ans;
      }
    }

    // Items : question + selectedIds + correctIds + isCorrect
    $items = [];
    foreach ($qcm->getQuestions() as $question) {
      $qid = $question->getId();
      if (!$qid) continue;

      $ans = $answersByQid[$qid] ?? null;

      $selectedIds = [];
      if ($ans) {
        foreach ($ans->getSelectedOptions() as $opt) {
          if ($opt->getId()) $selectedIds[] = (int)$opt->getId();
        }
      }
      $selectedIds = array_values(array_unique($selectedIds));

      $correctIds = [];
      foreach ($question->getOptions() as $opt) {
        if ($opt->getId() && $opt->isCorrect()) $correctIds[] = (int)$opt->getId();
      }
      sort($correctIds);
      $sortedSelected = $selectedIds;
      sort($sortedSelected);

      $isCorrect = ($sortedSelected === $correctIds);

      $items[] = [
        'question' => $question,
        'choices' => $question->getOptions(),
        'selected' => $selectedIds,
        'correctIds' => $correctIds,
        'isCorrect' => $isCorrect,
      ];
    }

    $scorePoints = (int)($attempt->getScorePoints() ?? 0);
    $maxPoints = (int)($attempt->getMaxPoints() ?? 0);
    $pct = (int)round((float)($attempt->getScorePercent() ?? 0));

    $filenameSafe = preg_replace('/[^A-Za-z0-9\-_]+/', '-', trim(($qcm->getTitre() ?: 'QCM')));
    $sessionCode = $session?->getCode() ?: ('session-' . ($session?->getId() ?: ''));
    $phase = $a->getPhase()?->value ?? 'phase';
    $file = sprintf('QCM-%s-%s-%s', $sessionCode, strtoupper($phase), $filenameSafe);

    // HTML -> PDF
    $html = $this->renderView('pdf/qcm_result.html.twig', [
      'entite' => $entite,
      'a' => $a,
      'attempt' => $attempt,
      'items' => $items,
      'pct' => $pct,
      'scorePoints' => $scorePoints,
      'maxPoints' => $maxPoints,
      'stagiaire' => $user,
      'session' => $session,
      'qcm' => $qcm,
      'generatedAt' => new \DateTimeImmutable(),
    ]);

    return $pdfManager->createPortrait($html, $file);
  }
}
