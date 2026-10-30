<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, FormateurSatisfactionTemplate, FormateurSatisfactionAttempt, PositioningQuestionnaire, PositioningAttempt, Qcm, QcmAttempt};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class QuestionnaireResponsesController extends AbstractController
{
    #[Route('/administrateur/{entite}/questionnaires/{kind}/{id}/reponses', name: 'app_administrateur_questionnaire_responses', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+', 'kind' => 'formateur|positionnement|niveau'])]
    public function index(Entite $entite, string $kind, int $id, Request $request, EntityManagerInterface $em): Response
    {
        [$modelClass, $attemptClass, $permission, $indexRoute, $detailRoute, $label] = match ($kind) {
            'formateur' => [FormateurSatisfactionTemplate::class, FormateurSatisfactionAttempt::class, TenantPermission::FORMATEUR_SATISFACTION_ATTEMPT_MANAGE, 'app_administrateur_formateur_satisfaction_template_index', 'app_administrateur_formateur_satisfaction_attempt_show', 'Satisfaction formateur'],
            'positionnement' => [PositioningQuestionnaire::class, PositioningAttempt::class, TenantPermission::POSITIONING_MANAGE, 'app_administrateur_positioning_questionnaire_index', 'app_administrateur_positioning_show', 'Positionnement'],
            'niveau' => [Qcm::class, QcmAttempt::class, TenantPermission::QCM_ATTEMPT_MANAGE, 'app_administrateur_qcm_index', 'app_administrateur_qcm_attempt_show', 'Questionnaire de niveau'],
        };
        $this->denyAccessUnlessGranted($permission, $entite);
        $model = $em->find($modelClass, $id);
        if (!$model || $model->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $qb = $em->getRepository($attemptClass)->createQueryBuilder('a')->where('a.submittedAt IS NOT NULL');
        if ($kind === 'positionnement') {
            $qb->leftJoin('a.stagiaire', 'person')->leftJoin('a.session', 'session')->andWhere('a.questionnaire = :model')
                ->andWhere('(session.entite = :entite OR session.id IS NULL)');
        } else {
            $qb->join('a.assignment', 'assignment')->join('assignment.session', 'session')->addSelect('assignment')
                ->andWhere('session.entite = :entite');
            if ($kind === 'formateur') {
                $qb->leftJoin('assignment.formateur', 'person')->andWhere('assignment.template = :model');
            } else {
                $qb->leftJoin('assignment.inscription', 'inscription')->leftJoin('inscription.stagiaire', 'person')->addSelect('inscription')->andWhere('assignment.qcm = :model');
            }
        }
        $qb->setParameter('model', $model)->setParameter('entite', $entite);
        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $search = trim($request->query->getString('q'));
        if ($search !== '') $qb->andWhere('LOWER(person.nom) LIKE :search OR LOWER(person.prenom) LIKE :search OR LOWER(session.code) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        $count = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($count / 20));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $attempts = $qb->addSelect('person', 'session')->orderBy('a.submittedAt', 'DESC')->addOrderBy('a.id', 'DESC')->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();
        $rows = [];
        foreach ($attempts as $attempt) {
            $assignment = $kind === 'positionnement' ? null : $attempt->getAssignment();
            $person = match ($kind) { 'formateur' => $assignment->getFormateur(), 'niveau' => $assignment->getInscription()?->getStagiaire(), default => $attempt->getStagiaire() };
            $session = $kind === 'positionnement' ? $attempt->getSession() : $assignment->getSession();
            $score = match ($kind) {
                'formateur' => $attempt->getNoteGlobale() !== null ? (string) $attempt->getNoteGlobale() : '—',
                'niveau' => $attempt->getMaxPoints() > 0 ? number_format($attempt->getScorePercent(), 1, ',', ' ').' %' : '—',
                default => $attempt->getSuggestedLevel()?->label() ?? 'Non renseigné',
            };
            $rows[] = ['name' => $person ? trim($person->getPrenom().' '.$person->getNom()) : 'Participant', 'email' => $person?->getEmail(), 'session' => $session?->getCode() ?? 'Sans session', 'date' => $attempt->getSubmittedAt(), 'score' => $score, 'phase' => $kind === 'niveau' ? $assignment->getPhase()->value : null, 'url' => $this->generateUrl($detailRoute, ['entite' => $entite->getId(), 'attempt' => $attempt->getId()])];
        }
        $title = $kind === 'positionnement' ? $model->getTitle() : $model->getTitre();
        return $this->render('administrateur/questionnaire/responses.html.twig', compact('entite', 'kind', 'id', 'title', 'label', 'rows', 'search', 'count', 'total', 'page', 'pages', 'indexRoute'));
    }
}
