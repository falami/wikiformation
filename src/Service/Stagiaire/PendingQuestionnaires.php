<?php

declare(strict_types=1);

namespace App\Service\Stagiaire;

use App\Entity\{Entite, Utilisateur, PositioningAttempt, QcmAssignment, Session, Inscription};
use App\Enum\{StatusSession, StatusInscription, QcmPhase, QcmAssignmentStatus};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PendingQuestionnaires
{
    public function __construct(private EntityManagerInterface $em, private UrlGeneratorInterface $urls) {}

    public function forLearner(Entite $entite, Utilisateur $user): array
    {
        $items = [];
        $attempts = $this->em->createQueryBuilder()->select('a, q, assignment, s, i, insSession, answers')
            ->from(PositioningAttempt::class, 'a')->join('a.questionnaire', 'q')
            ->leftJoin('a.answers', 'answers')->leftJoin('a.assignment', 'assignment')->leftJoin('a.session', 's')
            ->leftJoin('a.inscription', 'i')->leftJoin('i.session', 'insSession')
            ->where('a.stagiaire = :user AND a.entite = :entite AND q.entite = :entite')
            ->andWhere('a.submittedAt IS NULL')->andWhere('(q.isPublished = true OR assignment.isRequired = true)')
            ->setParameter('user', $user)->setParameter('entite', $entite)->orderBy('a.id', 'ASC')->getQuery()->getResult();
        foreach ($attempts as $attempt) {
            $assignment = $attempt->getAssignment();
            if ($assignment && ($assignment->getEntite()?->getId() !== $entite->getId() || $assignment->getStagiaire()?->getId() !== $user->getId())) continue;
            $inscription = $attempt->getInscription() ?? $assignment?->getInscription();
            $session = $attempt->getSession() ?? $assignment?->getSession() ?? $inscription?->getSession();
            if (!$this->active($session, $inscription, $entite, $user)) continue;
            $started = !$attempt->getAnswers()->isEmpty() || trim($attempt->getStagiaireComment() ?? '') !== '';
            $items[] = ['title' => $attempt->getQuestionnaire()->getTitle(), 'type' => 'Positionnement', 'phase' => 'Pour préparer votre formation',
                'session' => $session?->getCode(), 'required' => $assignment?->isRequired() ?? false, 'started' => $started,
                'url' => $this->urls->generate('app_stagiaire_positioning_fill', ['entite'=>$entite->getId(), 'attempt'=>$attempt->getId()])];
        }
        $assignments = $this->em->createQueryBuilder()->select('a, q, i, s, att')
            ->from(QcmAssignment::class, 'a')->join('a.qcm', 'q')->join('a.inscription', 'i')->join('a.session', 's')->leftJoin('a.attempt', 'att')
            ->where('i.stagiaire = :user AND a.entite = :entite AND q.entite = :entite AND i.entite = :entite AND s.entite = :entite')
            ->andWhere('a.submittedAt IS NULL AND att.submittedAt IS NULL')
            ->setParameter('user', $user)->setParameter('entite', $entite)->orderBy('a.id', 'ASC')->getQuery()->getResult();
        foreach ($assignments as $assignment) {
            if ($assignment->isSubmitted() || !$this->active($assignment->getSession(), $assignment->getInscription(), $entite, $user)) continue;
            if ($assignment->getInscription()->getSession()?->getId() !== $assignment->getSession()->getId()) continue;
            $items[] = ['title'=>$assignment->getQcm()->getTitre(), 'type'=>'Niveau',
                'phase'=>$assignment->getPhase() === QcmPhase::PRE ? 'Avant la formation' : 'Après la formation',
                'session'=>$assignment->getSession()->getCode(), 'required'=>true, 'started'=>$assignment->getStatus() === QcmAssignmentStatus::STARTED,
                'url'=>$this->urls->generate('app_stagiaire_qcm_show', ['entite'=>$entite->getId(), 'id'=>$assignment->getId()])];
        }
        // Required work first; stable order within each group.
        usort($items, static fn ($a, $b) => (int)$b['required'] <=> (int)$a['required']);
        return $items;
    }

    private function active(?Session $session, ?Inscription $inscription, Entite $entite, Utilisateur $user): bool
    {
        if ($session && ($session->getEntite()?->getId() !== $entite->getId() || $session->getStatus() === StatusSession::CANCELED)) return false;
        if ($inscription && ($inscription->getEntite()?->getId() !== $entite->getId()
            || $inscription->getStagiaire()?->getId() !== $user->getId()
            || in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true))) return false;
        return true;
    }
}
