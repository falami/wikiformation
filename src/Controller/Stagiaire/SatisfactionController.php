<?php
// src/Controller/Stagiaire/SatisfactionController.php
declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{SatisfactionAssignment, Entite, Utilisateur, SatisfactionAttempt, Inscription};
use App\Form\Satisfaction\SatisfactionFillType;
use App\Service\Satisfaction\SatisfactionAccess;
use App\Service\Satisfaction\SatisfactionKpiExtractor;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/stagiaire/{entite}/satisfaction', name: 'app_stagiaire_satisfaction_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_STAGIAIRE_MANAGE, subject: 'entite')]
final class SatisfactionController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private SatisfactionKpiExtractor $kpiExtractor,
  ) {}

  #[Route('/{inscription}/fill', name: 'fill', methods: ['GET', 'POST'], requirements: ['inscription' => '\d+'])]
  public function fill(
    Entite $entite,
    Inscription $inscription,
    Request $request,
    EM $em,
    SatisfactionAccess $access,
  ): Response {
    /** @var Utilisateur|null $user */
    $user = $this->getUser();
    if (!$user) {
      throw $this->createAccessDeniedException();
    }

    // sécurité : uniquement le stagiaire de l'inscription
    if ($inscription->getStagiaire()?->getId() !== $user->getId()) {
      throw $this->createAccessDeniedException();
    }

    $session = $inscription->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    /*
    // fenêtre d’accès
    if (!$access->canFill($session, 7)) {
      $this->addFlash('warning', 'Le questionnaire est disponible uniquement à la dernière journée (et quelques jours après).');
      return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite], 302);
    }
*/
    // assignment (session + stagiaire)
    $assignmentRepo = $em->getRepository(SatisfactionAssignment::class);
    /** @var SatisfactionAssignment|null $assignment */
    $assignment = $assignmentRepo->findOneBy([
      'session' => $session,
      'stagiaire' => $user,
    ]);

    if (!$assignment) {
      $this->addFlash('warning', 'Aucune affectation de satisfaction trouvée pour cette session.');
      return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite->getId()], 302);
    }

    $template = $assignment->getTemplate();
    if (!$template) {
      $this->addFlash('warning', 'Aucun template associé à cette affectation.');
      return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite->getId()], 302);
    }

    // attempt lié à l'assignment
    $attempt = $assignment->getAttempt();
    if (!$attempt) {
      $attempt = (new SatisfactionAttempt())->setAssignment($assignment)
        ->setCreateur($user)
        ->setEntite($entite);
      $assignment->setAttempt($attempt);
      $em->persist($attempt);
    }

    // déjà soumis => lock
    if ($attempt->isSubmitted()) {
      $this->addFlash('info', 'Questionnaire déjà soumis, merci !');
      return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite->getId()], 302);
    }

    // form
    $form = $this->createForm(SatisfactionFillType::class, null, [
      'template' => $template,
      'entite_id' => $entite->getId(),
      'only_public' => true,
    ]);
    $form->handleRequest($request);

    // ✅ On marque startedAt au premier POST (ou au GET sans flush)
    if (!$attempt->isStarted() && ($request->isMethod('POST') || $form->isSubmitted())) {
      $attempt->setStartedAt(new \DateTimeImmutable());
    }

    if ($form->isSubmitted() && $form->isValid()) {
      $raw = $form->getData() ?? [];

      // extraction answers => [questionId => value]
      $answers = $this->extractAnswers($raw);

      $attempt->setAnswers($answers);

      $this->kpiExtractor->apply($attempt, $template, $answers);

      $attempt->setSubmittedAt(new \DateTimeImmutable());

      $em->flush();

      $this->addFlash('success', 'Merci ! Votre évaluation a été enregistrée.');
      return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite->getId()]);
    }

    return $this->render('stagiaire/satisfaction/fill.html.twig', [
      'inscription' => $inscription,
      'session' => $session,
      'template' => $template,
      'form' => $form->createView(),
      'assignment' => $assignment,
      'attempt' => $attempt,
      'entite' => $entite,


    ]);
  }

  /**
   * @param array<string, mixed> $raw
   * @return array<int, mixed> answers keyed by questionId
   */
  private function extractAnswers(array $raw): array
  {
    $answers = [];

    foreach ($raw as $k => $v) {
      $k = (string) $k;
      if (!str_starts_with($k, 'q_')) continue;

      $qid = (int) substr($k, 2);
      if ($qid <= 0) continue;

      // Collections / multi-select => array d'ids
      if (is_iterable($v) && !is_string($v)) {
        $ids = [];
        foreach ($v as $obj) {
          if (is_object($obj) && method_exists($obj, 'getId')) {
            $ids[] = $obj->getId();
          } else {
            $ids[] = (string) $obj;
          }
        }
        $answers[$qid] = $ids;
        continue;
      }

      // normalise scalaires (ex: "5" => 5)
      if (is_string($v) && is_numeric($v)) {
        $v = (int) $v;
      }

      $answers[$qid] = $v;
    }

    return $answers;
  }
}
