<?php
// src/Controller/Administrateur/SatisfactionAttemptController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{SatisfactionAttempt, Entite, Utilisateur};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/satisfaction/attempts', name: 'app_administrateur_satisfaction_attempt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_ATTEMPT_MANAGE, subject: 'entite')]
final class SatisfactionAttemptController extends AbstractController
{
  public function __construct(private UtilisateurEntiteManager $utilisateurEntiteManager) {}

  #[Route('/template/{template}/responses', name: 'responses', methods: ['GET'], requirements: ['template' => '\d+'])]
  public function responses(Entite $entite, \App\Entity\SatisfactionTemplate $template, \Symfony\Component\HttpFoundation\Request $request, EM $em): Response
  {
    if ($template->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    $search = trim($request->query->getString('q'));
    $qb = $em->getRepository(SatisfactionAttempt::class)->createQueryBuilder('a')
      ->join('a.assignment', 'assignment')->join('assignment.session', 's')
      ->leftJoin('assignment.stagiaire', 'u')->leftJoin('assignment.participantAccess', 'participant')
      ->where('assignment.template = :template')->andWhere('s.entite = :entite')
      ->andWhere('a.submittedAt IS NOT NULL')->setParameter('template', $template)->setParameter('entite', $entite);
    $stats = (clone $qb)->select('COUNT(a.id) AS total, AVG(a.noteGlobale) AS average')->getQuery()->getSingleResult();
    if ($search !== '') {
      $qb->andWhere('LOWER(u.nom) LIKE :q OR LOWER(u.prenom) LIKE :q OR LOWER(s.code) LIKE :q OR LOWER(participant.displayName) LIKE :q')
        ->setParameter('q', '%'.mb_strtolower($search).'%');
    }
    $count = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    $pages = max(1, (int) ceil($count / 20));
    $page = min($pages, max(1, $request->query->getInt('page', 1)));
    $attempts = $qb->addSelect('assignment', 's', 'u', 'participant')->orderBy('a.submittedAt', 'DESC')->addOrderBy('a.id', 'DESC')
      ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();
    return $this->render('administrateur/satisfaction/attempt/responses.html.twig', compact('entite', 'template', 'attempts', 'stats', 'search', 'count', 'page', 'pages'));
  }

  #[Route('/{attempt}', name: 'show', methods: ['GET'], requirements: ['attempt' => '\d+'])]
  public function show(Entite $entite, SatisfactionAttempt $attempt, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException('Accès interdit à cette entité.');

    // sécurité via session->entite
    $assignment = $attempt->getAssignment();
    $session = $assignment?->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if ($assignment?->getTemplate()?->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    $formationIds = [];
    foreach ($assignment->getTemplate()->getChapters() as $chapter) {
      foreach ($chapter->getQuestions() as $question) {
        if ($question->getType() === \App\Enum\SatisfactionQuestionType::MULTI_FORMATIONS) {
          foreach ((array) ($attempt->getAnswers()[$question->getId()] ?? []) as $id) {
            if (is_numeric($id)) $formationIds[] = (int) $id;
          }
        }
      }
    }
    $formationLabels = [];
    if ($formationIds) {
      foreach ($em->getRepository(\App\Entity\Formation::class)->findBy(['entite' => $entite, 'id' => array_unique($formationIds)]) as $formation) {
        $formationLabels[$formation->getId()] = $formation->getTitre();
      }
    }

    return $this->render('administrateur/satisfaction/attempt/show.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'attempt' => $attempt,
      'formationLabels' => $formationLabels,
      'assignment' => $assignment,
      'session' => $session,
      'stagiaire' => $assignment?->getStagiaire(),
      'template' => $assignment?->getTemplate(),
      'title' => 'Questionnaire rempli',
    ]);
  }
}
