<?php

declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{Entite, EvaluationDossier, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Evaluation\{EvaluationResultNotifier, EvaluationWorkflow};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/formateur/{entite}/evaluations', name: 'app_formateur_evaluation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR, subject: 'entite')]
final class EvaluationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        $dossiers = $em->getRepository(EvaluationDossier::class)->createQueryBuilder('d')
            ->addSelect('i', 's', 'u')->join('d.inscription', 'i')->join('d.trainingSession', 's')->join('d.learner', 'u')
            ->where('d.entite = :entite')->andWhere('i.entite = :entite')->andWhere('s.entite = :entite')->andWhere('d.evaluator = :user')
            ->setParameter('entite', $entite)->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')->getQuery()->getResult();

        return $this->render('evaluation/formateur/index.html.twig', compact('entite', 'dossiers'));
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(Entite $entite, EvaluationDossier $dossier, Request $request, EntityManagerInterface $em, EvaluationWorkflow $workflow, EvaluationResultNotifier $notifier): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur || $dossier->getEntite()?->getId() !== $entite->getId()
            || $dossier->getInscription()?->getEntite()?->getId() !== $entite->getId()
            || $dossier->getTemplate()?->getEntite()?->getId() !== $entite->getId()
            || $dossier->getSession()?->getEntite()?->getId() !== $entite->getId()
            || $dossier->getEvaluator()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $error = null;
        $conflict = false;
        $status = 200;
        $theory = $dossier->getTheoryGrading();
        $practice = $dossier->getPracticeGrading();
        $theoryVerdict = $dossier->getTheoryVerdict();
        $practiceVerdict = $dossier->getPracticeVerdict();
        if ($request->isMethod('POST')) {
            $original = clone $dossier;
            if (!$this->isCsrfTokenValid('evaluation_review_'.$dossier->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Le formulaire a expiré. Rechargez la page.');
            }
            try {
                $version = $request->request->getString('version');
                if (!ctype_digit($version) || (int) $version !== $dossier->getVersion()) {
                    throw new \UnexpectedValueException('Ce dossier a été modifié dans une autre fenêtre. Rechargez-le avant de poursuivre.');
                }
                $em->lock($dossier, LockMode::OPTIMISTIC, (int) $version);
                $action = $request->request->getString('action');
                if (!in_array($action, ['save', 'publish'], true)) throw new \DomainException('Action inconnue.');
                $theory = $request->request->all('theory');
                $practice = $request->request->all('practice');
                $theoryVerdict = $request->request->getString('theory_verdict') ?: null;
                $practiceVerdict = $request->request->getString('practice_verdict') ?: null;
                $workflow->saveReview($dossier, $theory, $practice, $theoryVerdict, $practiceVerdict);
                if ($action === 'publish') {
                    if ($request->request->getString('validation_confirmed') !== '1') {
                        throw new \DomainException('Confirmez avoir vérifié les réponses et réalisé l’évaluation pratique avant de publier.');
                    }
                    $workflow->publish($dossier);
                }
                $em->flush();
                if ($action === 'publish') {
                    $this->addFlash('success', 'Les résultats ont été validés et sont disponibles dans l’espace du stagiaire.');
                    if (!$notifier->notify($dossier)) {
                        $this->addFlash('warning', 'Les résultats sont publiés, mais le mail de notification n’a pas pu être envoyé. Prévenez votre organisme.');
                    }
                } else {
                    $this->addFlash('success', 'Votre brouillon est enregistré. Les notes restent privées jusqu’à la publication.');
                }
                return $this->redirectToRoute('app_formateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]);
            } catch (OptimisticLockException|\UnexpectedValueException $e) {
                $em->detach($dossier);
                $dossier = $original;
                $error = 'Ce dossier a été modifié dans une autre fenêtre. Rechargez-le pour consulter sa dernière version ; votre enregistrement n’a pas été appliqué.';
                $conflict = true;
                $status = 409;
            } catch (\DomainException|\InvalidArgumentException $e) {
                $em->detach($dossier);
                $dossier = $original;
                $error = $e->getMessage();
                $status = 422;
            }
        }

        $suggestions = $dossier->getTheorySubmittedAt() ? $workflow->suggestTheoryGrading($dossier) : [];
        $summary = $workflow->scoreSummary($dossier);
        $theory = $this->safeFormGrading($theory);
        $practice = $this->safeFormGrading($practice);
        return $this->render('evaluation/formateur/show.html.twig', compact('entite', 'dossier', 'theory', 'practice', 'theoryVerdict', 'practiceVerdict', 'suggestions', 'summary', 'error', 'conflict'), new Response(status: $status));
    }

    /** Invalid nested POST values must not become template values on the error page. */
    private function safeFormGrading(array $grades): array
    {
        $safe = [];
        foreach ($grades as $id => $grade) {
            if (!is_array($grade)) continue;
            $safe[$id] = [
                'points' => is_scalar($grade['points'] ?? null) ? $grade['points'] : null,
                'comment' => is_string($grade['comment'] ?? null) ? $grade['comment'] : '',
                'na' => in_array($grade['na'] ?? false, [true, 1, '1'], true),
                'failed' => in_array($grade['failed'] ?? false, [true, 1, '1'], true),
            ];
        }
        return $safe;
    }
}
