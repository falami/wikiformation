<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Entite, EvaluationDossier, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Evaluation\EvaluationWorkflow;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stagiaire/{entite}/evaluations', name: 'app_stagiaire_evaluation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_QCM_MANAGE, subject: 'entite')]
final class EvaluationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        $records = $em->getRepository(EvaluationDossier::class)->createQueryBuilder('d')
            ->addSelect('i', 's', 'e')->join('d.inscription', 'i')->join('d.trainingSession', 's')->join('d.evaluator', 'e')
            ->where('d.entite = :entite')->andWhere('i.entite = :entite')->andWhere('s.entite = :entite')->andWhere('d.learner = :user')
            ->setParameter('entite', $entite)->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')->getQuery()->getResult();
        // Never hand the entity (and therefore the private answer key) to learner views.
        $dossiers = array_map(fn(EvaluationDossier $d): array => $this->learnerView($d), $records);
        return $this->render('evaluation/stagiaire/index.html.twig', compact('entite', 'dossiers'));
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(Entite $entite, EvaluationDossier $dossier, Request $request, EntityManagerInterface $em, EvaluationWorkflow $workflow): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur || $dossier->getEntite()?->getId() !== $entite->getId()
            || $dossier->getInscription()?->getEntite()?->getId() !== $entite->getId()
            || (!$dossier->getTemplate()?->getTrainerOwner() && $dossier->getTemplate()?->getEntite()?->getId() !== $entite->getId())
            || $dossier->getSession()?->getEntite()?->getId() !== $entite->getId()
            || $dossier->getStagiaire()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
        $error = null;
        $conflict = false;
        $answers = $dossier->getTheoryAnswers();
        $status = 200;
        if ($request->isMethod('POST')) {
            $original = clone $dossier;
            if (!$this->isCsrfTokenValid('evaluation_submit_'.$dossier->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
            }
            try {
                $version = $request->request->getString('version');
                if (!ctype_digit($version) || (int) $version !== $dossier->getVersion()) {
                    throw new \UnexpectedValueException('Ce dossier a changé. Rechargez la page.');
                }
                $em->lock($dossier, LockMode::OPTIMISTIC, (int) $version);
                $answers = $request->request->all('answers');
                if ($request->request->getString('submission_confirmed') !== '1') {
                    throw new \DomainException('Confirmez que vous souhaitez transmettre définitivement vos réponses au formateur.');
                }
                $workflow->submitTheory($dossier, $answers);
                $em->flush();
                $this->addFlash('success', 'Votre test a été transmis au formateur. Vos résultats seront disponibles après sa validation.');
                return $this->redirectToRoute('app_stagiaire_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]);
            } catch (OptimisticLockException|\UnexpectedValueException $e) {
                $em->detach($dossier);
                $dossier = $original;
                $error = 'Ce dossier a été modifié depuis son ouverture. Rechargez-le pour consulter sa dernière version ; votre enregistrement n’a pas été appliqué.';
                $conflict = true;
                $status = 409;
            } catch (\DomainException|\InvalidArgumentException $e) {
                $em->detach($dossier);
                $dossier = $original;
                $error = $e->getMessage();
                $status = 422;
            }
        }
        $view = $this->learnerView($dossier, true);
        $summary = $dossier->getPublishedAt() ? $workflow->scoreSummary($dossier) : null;
        return $this->render('evaluation/stagiaire/show.html.twig', ['entite' => $entite, 'dossier' => $view, 'answers' => $answers, 'summary' => $summary, 'error' => $error, 'conflict' => $conflict], new Response(status: $status));
    }

    private function learnerView(EvaluationDossier $dossier, bool $includeQuestions = false): array
    {
        $snapshot = $dossier->getSnapshot();
        $view = [
            'id' => $dossier->getId(), 'version' => $dossier->getVersion(), 'title' => $snapshot['title'] ?? 'Évaluation',
            'session' => $dossier->getSession()?->getCode(), 'formation' => $dossier->getSession()?->getFormation()?->getTitre(),
            'evaluator' => trim(($dossier->getEvaluator()?->getPrenom() ?? '').' '.($dossier->getEvaluator()?->getNom() ?? '')),
            'state' => $dossier->getState(), 'submittedAt' => $dossier->getTheorySubmittedAt(), 'publishedAt' => $dossier->getPublishedAt(),
            'successful' => $dossier->isSuccessful(), 'createdAt' => $dossier->getCreatedAt(),
        ];
        if ($includeQuestions) {
            $view['questions'] = array_map(static function(array $question) use ($dossier): array {
                if (!$dossier->getPublishedAt()) unset($question['correct']);
                return $question;
            }, $snapshot['schema']['theory'] ?? []);
        }
        if ($dossier->getPublishedAt()) {
            $record = $dossier->getPublicationRecord();
            $view['evaluator'] = $record['evaluatorName'] ?? $view['evaluator'];
            $view['formation'] = $record['formationTitle'] ?? $view['formation'];
            $view['session'] = $record['sessionCode'] ?? $view['session'];
            $view['theoryVerdict'] = $dossier->getTheoryVerdict();
            $view['practiceVerdict'] = $dossier->getPracticeVerdict();
            if ($includeQuestions) {
                $view['theoryGrading'] = $dossier->getTheoryGrading();
                $view['practiceGrading'] = $dossier->getPracticeGrading();
                $view['practice'] = $snapshot['schema']['practice'] ?? [];
            }
        }
        return $view;
    }
}
