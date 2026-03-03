<?php

declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{FormateurSatisfactionAssignment, Entite, Utilisateur, Session, FormationObjective, FormateurSatisfactionAttempt};
use App\Enum\AcquisitionLevel;
use App\Form\FormateurSatisfaction\FormateurSatisfactionFillType;
use App\Service\FormateurSatisfaction\FormateurSatisfactionKpiExtractor;
use App\Service\Satisfaction\SatisfactionAccess;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/formateur/{entite}/satisfaction', name: 'app_formateur_satisfaction_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_SATISFACTION_MANAGE, subject: 'entite')]
final class FormateurSatisfactionController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $uem,
    private FormateurSatisfactionKpiExtractor $kpiExtractor,
  ) {}

  #[Route('/session/{session}/fill', name: 'fill', methods: ['GET', 'POST'], requirements: ['session' => '\d+'])]
  public function fill(
    Entite $entite,
    Session $session,
    Request $request,
    EM $em,
    SatisfactionAccess $access,
  ): Response {
    /** @var Utilisateur|null $user */
    $user = $this->getUser();
    if (!$user) {
      throw $this->createAccessDeniedException();
    }



    // sécurité entité
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    // fenêtre d’accès
    if (!$access->canFill($session, 7)) {
      $this->addFlash('warning', 'Le questionnaire formateur est disponible à la dernière journée (et quelques jours après).');
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()], 302);
    }


    /** @var FormateurSatisfactionAssignment|null $assignment */
    $assignment = $em->getRepository(FormateurSatisfactionAssignment::class)->findOneBy([
      'session' => $session,
      'formateur' => $user,
    ]);

    if (!$assignment) {
      $this->addFlash('warning', 'Aucune affectation formateur trouvée pour cette session.');
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()], 302);
    }

    $template = $assignment->getTemplate();
    if (!$template) {
      $this->addFlash('warning', 'Aucun template associé à cette affectation.');
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()], 302);
    }

    // attempt
    $attempt = $assignment->getAttempt();
    if (!$attempt) {
      $attempt = (new FormateurSatisfactionAttempt())->setAssignment($assignment)
        ->setCreateur($user)
        ->setEntite($entite);
      $assignment->setAttempt($attempt);
      $em->persist($attempt);
    }

    if ($attempt->isSubmitted()) {
      $this->addFlash('info', 'Questionnaire déjà soumis, merci !');
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()], 302);
    }

    // stagiaires
    $stagiaires = [];
    foreach ($session->getInscriptions() as $ins) {
      $st = $ins->getStagiaire();
      if ($st instanceof Utilisateur && $st->getId()) {
        $stagiaires[] = $st;
      }
    }

    // objectifs existants
    $formation = $session->getFormation();
    $objectives = [];
    if ($formation) {
      $objectives = $em->getRepository(FormationObjective::class)->findBy(
        ['formation' => $formation],
        ['position' => 'ASC']
      );
    }

    // form defaults
    $defaults = $this->buildDefaults($attempt);

    $form = $this->createForm(FormateurSatisfactionFillType::class, $defaults, [
      'template' => $template,
      'stagiaires' => $stagiaires,
      'objectives' => $objectives,
    ]);
    $form->handleRequest($request);

    // startedAt
    if ($form->isSubmitted() && !$attempt->isStarted()) {
      $attempt->setStartedAt(new \DateTimeImmutable());
    }

    if ($form->isSubmitted() && $form->isValid()) {
      $raw = $form->getData() ?? [];

      // 1) réponses questionnaire
      $answers = $this->extractAnswers($raw);
      $attempt->setAnswers($answers);
      $this->kpiExtractor->apply($attempt, $template, $answers);

      // 2) si pas d’objectifs => on les crée + on applique la matrice JSON
      if (empty($objectives)) {
        $points = $this->extractCompetencesPoints($form->get('competences_points')->getData());


        if (count($points) === 0) {
          $this->addFlash('danger', 'Merci de saisir au moins un point/compétence.');
          // on retombe sur le rendu twig
          return $this->render('formateur/satisfaction/fill.html.twig', [
            'entite' => $entite,
            'session' => $session,
            'template' => $template,
            'form' => $form->createView(),
            'assignment' => $assignment,
            'attempt' => $attempt,
            'stagiaires' => $stagiaires,
            'objectives' => [],
            'utilisateurEntite' => $this->uem->getUserEntiteLink($entite),
          ]);
        }

        if (!$formation) {
          $this->addFlash('danger', 'Formation introuvable pour créer les objectifs.');
          return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite], 302);
        }

        // création objectifs
        $pos = 1;
        foreach ($points as $label) {
          $obj = new FormationObjective();
          $obj->setCreateur($user);
          $obj->setEntite($entite);
          $obj->setFormation($formation);
          $obj->setLabel($label);
          if (method_exists($obj, 'setPosition')) {
            $obj->setPosition($pos++);
          }
          $em->persist($obj);
        }
        $em->flush();

        // reload objectifs
        $objectives = $em->getRepository(FormationObjective::class)->findBy(
          ['formation' => $formation],
          ['position' => 'ASC']
        );

        // applique la matrice JSON => crée FormateurObjectiveEvaluation
        $json = $form->has('obj_matrix_json') ? (string) $form->get('obj_matrix_json')->getData() : '';
        $this->applyObjectiveMatrixJson($attempt, $stagiaires, $objectives, $json, $em);
      } else {
        // 3) objectifs existants => champs classiques obj_{sid}_{oid}
        $this->applyObjectiveEvaluations($attempt, $raw, $stagiaires, $objectives, $em);
      }

      // submit
      $attempt->setSubmittedAt(new \DateTimeImmutable());
      $em->flush();

      $this->addFlash('success', 'Merci ! Votre évaluation formateur a été enregistrée.');
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()]);
    }

    return $this->render('formateur/satisfaction/fill.html.twig', [
      'entite' => $entite,
      'session' => $session,
      'template' => $template,
      'form' => $form->createView(),
      'assignment' => $assignment,
      'attempt' => $attempt,
      'stagiaires' => $stagiaires,
      'objectives' => $objectives,
      'utilisateurEntite' => $this->uem->getUserEntiteLink($entite),
    ]);
  }

  /** @return array<string,mixed> */
  private function buildDefaults(FormateurSatisfactionAttempt $attempt): array
  {
    $d = [];
    $answers = $attempt->getAnswers() ?? [];
    foreach ($answers as $qid => $val) {
      $d['q_' . (int)$qid] = $val;
    }

    foreach ($attempt->getObjectiveEvaluations() as $e) {
      $sid = $e->getStagiaire()?->getId();
      $oid = $e->getObjective()?->getId();
      if (!$sid || !$oid) continue;

      $d['obj_' . $sid . '_' . $oid] = $e->getLevel()?->value;

      if ($e->getComment()) {
        $d['obj_comment_' . $sid] = $e->getComment();
      }
    }

    // garde la matrice si tu fais un retour validation (optionnel)
    // $d['obj_matrix_json'] = ...

    return $d;
  }

  /** @param array<string,mixed> $raw */
  private function extractAnswers(array $raw): array
  {
    $answers = [];
    foreach ($raw as $k => $v) {
      $k = (string)$k;
      if (!str_starts_with($k, 'q_')) continue;

      $qid = (int) substr($k, 2);
      if ($qid <= 0) continue;

      if (is_iterable($v) && !is_string($v)) {
        $ids = [];
        foreach ($v as $obj) {
          if (is_object($obj) && method_exists($obj, 'getId')) $ids[] = $obj->getId();
          else $ids[] = (string)$obj;
        }
        $answers[$qid] = $ids;
        continue;
      }

      if (is_string($v) && is_numeric($v)) $v = (int)$v;
      $answers[$qid] = $v;
    }
    return $answers;
  }

  /** @return string[] */
  private function extractCompetencesPoints(mixed $pointsRaw): array
  {
    $pointsRaw = (string) $pointsRaw;
    $lines = preg_split('/\R+/', $pointsRaw) ?: [];

    $points = [];
    foreach ($lines as $l) {
      $l = trim((string)$l);
      if ($l === '') continue;

      $l = preg_replace('/^\-\s*/', '', $l);
      $l = trim((string)$l);

      if ($l !== '') $points[] = $l;
    }

    return array_values(array_unique($points));
  }

  private function applyObjectiveMatrixJson(
    FormateurSatisfactionAttempt $attempt,
    array $stagiaires,
    array $objectives,
    string $json,
    EM $em
  ): void {
    if (trim($json) === '') {
      return;
    }

    try {
      $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
      return;
    }

    $matrix = $payload['matrix'] ?? [];
    if (!is_array($matrix)) return;

    // map label -> objective
    $byLabel = [];
    foreach ($objectives as $o) {
      $byLabel[(string)$o->getLabel()] = $o;
    }

    foreach ($stagiaires as $s) {
      $sid = (string) $s->getId();
      if ($sid === '' || !isset($matrix[$sid]) || !is_array($matrix[$sid])) {
        continue;
      }

      foreach ($matrix[$sid] as $label => $payload) {
        $label = (string) $label;
        if (!isset($byLabel[$label])) continue;

        $objective = $byLabel[$label];

        $levelStr = '';
        $comment = null;

        if (is_array($payload)) {
          $levelStr = (string)($payload['level'] ?? '');
          $comment = isset($payload['comment']) ? trim((string)$payload['comment']) : null;
          if ($comment === '') $comment = null;
        } else {
          // compat ancienne version: string
          $levelStr = is_string($payload) ? $payload : '';
        }

        $level = null;
        if ($levelStr !== '') {
          $level = AcquisitionLevel::tryFrom($levelStr);
        }

        $e = $attempt->upsertObjectiveEval($s, $objective);
        $e->setLevel($level);
        $e->setComment($comment);

        $em->persist($e);
      }
    }
  }

  private function applyObjectiveEvaluations(
    FormateurSatisfactionAttempt $attempt,
    array $raw,
    array $stagiaires,
    array $objectives,
    EM $em
  ): void {
    $commentByStagiaireId = [];
    foreach ($stagiaires as $s) {
      $sid = $s->getId();
      if (!$sid) continue;
      $commentByStagiaireId[$sid] = (string)($raw['obj_comment_' . $sid] ?? '');
    }

    foreach ($stagiaires as $s) {
      $sid = $s->getId();
      if (!$sid) continue;

      foreach ($objectives as $o) {
        $oid = $o->getId();
        if (!$oid) continue;

        $field = 'obj_' . $sid . '_' . $oid;
        $val = $raw[$field] ?? null;

        $level = null;
        if (is_string($val) && $val !== '') {
          $level = AcquisitionLevel::tryFrom($val);
        }

        $e = $attempt->upsertObjectiveEval($s, $o);
        $e->setLevel($level);

        $comment = trim($commentByStagiaireId[$sid] ?? '');
        $e->setComment($comment !== '' ? $comment : null);

        $em->persist($e);
      }
    }
  }
}
