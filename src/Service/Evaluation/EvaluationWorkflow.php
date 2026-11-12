<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

use App\Entity\{Entite, EvaluationDossier, EvaluationTemplate};

/** Explicit publication separates learner submission, trainer judgement and success. */
final class EvaluationWorkflow
{
    public function __construct(private readonly EvaluationSchema $schema = new EvaluationSchema()) {}

    public function validateTemplate(array $schema): array { return $this->schema->validate($schema); }

    public function snapshotTemplate(EvaluationTemplate $template): array
    {
        if (!$template->isActive()) throw new \DomainException('Ce modèle est archivé.');
        return ['title' => $this->schema->text($template->getTitle(), 180), 'version' => $template->getVersion(), 'owner' => $template->getOwnerLabel(), 'trainerOwnerId' => $template->getTrainerOwner()?->getId(), 'schema' => $this->validateTemplate($template->getSchema())];
    }

    public function submitTheory(EvaluationDossier $dossier, array $answers): void
    {
        $dossier->assertUnpublished();
        if ($dossier->getTheorySubmittedAt() !== null) throw new \DomainException('Le test théorique a déjà été soumis.');
        $schema = $this->dossierSchema($dossier);
        $this->schema->keys($answers, array_column($schema['theory'], 'id'), 'test');
        $normalized = [];
        foreach ($schema['theory'] as $question) {
            $value = $answers[$question['id']] ?? ($question['type'] === 'text' ? '' : []);
            if ($question['type'] === 'text') {
                $normalized[$question['id']] = $this->schema->text($value, 10000, true);
                continue;
            }
            if ($question['type'] === 'single' && is_string($value)) $value = $value === '' ? [] : [$value];
            if (!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException('Réponse invalide pour '.$question['label'].'.');
            $options = array_column($question['options'], 'id');
            $seen = [];
            foreach ($value as $option) {
                if (!is_string($option) || !in_array($option, $options, true) || in_array($option, $seen, true)) throw new \InvalidArgumentException('Choix inconnu ou répété.');
                $seen[] = $option;
            }
            if ($question['type'] === 'single' && count($value) > 1) throw new \InvalidArgumentException('Un seul choix est autorisé.');
            if ($question['type'] === 'order' && count($value) !== count($options)) throw new \InvalidArgumentException('Classez chaque proposition une seule fois.');
            $normalized[$question['id']] = $value;
        }
        $dossier->setTheoryAnswers($normalized)->setTheorySubmittedAt(new \DateTimeImmutable());
    }

    public function saveReview(EvaluationDossier $dossier, array $theory, array $practice, ?string $theoryVerdict, ?string $practiceVerdict): void
    {
        $dossier->assertUnpublished();
        $schema = $this->dossierSchema($dossier);
        $this->verdict($theoryVerdict);
        $this->verdict($practiceVerdict);
        // Validate all inputs before mutating the entity, including an incomplete draft.
        $theory = $this->grading($theory, $schema['theory'], false, false);
        $practice = $this->grading($practice, $schema['practice'], true, false);
        if ($dossier->getTheorySubmittedAt() === null && array_filter($theory, static fn(array $grade): bool => $grade['points'] !== null) !== []) throw new \DomainException('Le stagiaire doit soumettre son test avant sa correction.');
        $dossier->setTheoryGrading($theory)->setPracticeGrading($practice)->setTheoryVerdict($theoryVerdict)->setPracticeVerdict($practiceVerdict);
    }

    public function publish(EvaluationDossier $dossier): void
    {
        $dossier->assertUnpublished();
        $schema = $this->dossierSchema($dossier);
        if ($dossier->getTheorySubmittedAt() === null) throw new \DomainException('Le test théorique n’a pas encore été soumis.');
        if ($dossier->getTheoryVerdict() === null || $dossier->getPracticeVerdict() === null) throw new \DomainException('Le formateur doit donner un avis explicite pour la théorie et la pratique.');
        $this->grading($dossier->getTheoryGrading(), $schema['theory'], false, true);
        $practice = $this->grading($dossier->getPracticeGrading(), $schema['practice'], true, true);
        $scores = $this->scoreSummary($dossier);
        if ($scores['practice']['max'] <= 0) throw new \DomainException('Au moins un critère pratique doit être évalué.');
        foreach (['theory', 'practice'] as $part) {
            $verdict = $part === 'theory' ? $dossier->getTheoryVerdict() : $dossier->getPracticeVerdict();
            $threshold = $schema[$part.'Threshold'];
            if ($verdict === 'favorable' && $threshold !== null && ($scores[$part]['points'] * 20 + 0.0000001) < ($threshold * $scores[$part]['max'])) throw new \DomainException('Un avis favorable est impossible en dessous du seuil de réussite.');
        }
        foreach ($schema['practice'] as $criterion) {
            if ($criterion['critical'] && !$practice[$criterion['id']]['na'] && $practice[$criterion['id']]['failed'] && $dossier->getPracticeVerdict() === 'favorable') throw new \DomainException('Un critère éliminatoire non acquis empêche un avis pratique favorable.');
        }
        $now = new \DateTimeImmutable();
        $name = static fn($person): string => trim(($person?->getPrenom() ?? '').' '.($person?->getNom() ?? ''));
        $dossier->setPublicationRecord([
            'learnerName' => $name($dossier->getStagiaire()), 'evaluatorName' => $name($dossier->getEvaluator()),
            'learnerId' => $dossier->getStagiaire()?->getId(), 'evaluatorId' => $dossier->getEvaluator()?->getId(),
            'entityName' => $dossier->getEntite()?->getNom() ?? '', 'sessionCode' => $dossier->getSession()?->getCode() ?? '',
            'formationTitle' => $dossier->getSession()?->getFormationLabel() ?? '',
            'sessionStart' => $dossier->getSession()?->getDateDebut()?->format(DATE_ATOM),
            'sessionEnd' => $dossier->getSession()?->getDateFin()?->format(DATE_ATOM),
            'publishedAt' => $now->format(DATE_ATOM), 'title' => $dossier->getSnapshot()['title'],
            'templateVersion' => $dossier->getSnapshot()['version'], 'theoryVerdict' => $dossier->getTheoryVerdict(),
            'practiceVerdict' => $dossier->getPracticeVerdict(), 'scores' => $scores,
        ])->setPublishedAt($now);
    }

    public function scoreSummary(EvaluationDossier $dossier): array
    {
        $schema = $this->dossierSchema($dossier);
        return ['theory' => $this->sum($schema['theory'], $dossier->getTheoryGrading(), false), 'practice' => $this->sum($schema['practice'], $dossier->getPracticeGrading(), true)];
    }

    /** Suggestions never populate marks or verdicts without a trainer's explicit review. */
    public function suggestTheoryGrading(EvaluationDossier $dossier): array
    {
        $schema = $this->dossierSchema($dossier);
        $result = [];
        foreach ($schema['theory'] as $question) {
            $points = null;
            if ($dossier->getTheorySubmittedAt() !== null && $question['type'] !== 'text') {
                $answer = $dossier->getTheoryAnswers()[$question['id']] ?? [];
                $correct = $question['correct'];
                if ($question['type'] === 'multiple') { sort($answer); sort($correct); }
                $points = $answer === $correct ? $question['points'] : 0.0;
            }
            $result[$question['id']] = ['points' => $points, 'comment' => ''];
        }
        return $result;
    }

    private function dossierSchema(EvaluationDossier $dossier): array
    {
        $tenant = $dossier->getEntite();
        if ($tenant === null || !$this->sameTenant($tenant, $dossier->getInscription()?->getEntite()) || !$this->sameTenant($tenant, $dossier->getSession()?->getEntite()) || (!$dossier->getTemplate()?->getTrainerOwner() && !$this->sameTenant($tenant, $dossier->getTemplate()?->getEntite()))) throw new \DomainException('Le dossier, la session, l’inscription et le modèle doivent appartenir au même organisme.');
        if ($dossier->getStagiaire() === null || $dossier->getEvaluator() === null) throw new \DomainException('Le stagiaire et le formateur évaluateur sont requis.');
        $snapshot = $dossier->getSnapshot();
        if (!is_array($snapshot['schema'] ?? null) || !is_string($snapshot['title'] ?? null) || !is_int($snapshot['version'] ?? null) || $snapshot['version'] < 1) throw new \DomainException('Le modèle figé de ce dossier est incomplet.');
        $previous = $dossier->getPreviousDossier();
        if ($previous !== null && ($previous->getPublishedAt() === null || !$this->sameTenant($tenant, $previous->getEntite()) || $previous->getInscription() !== $dossier->getInscription() || $previous->getStagiaire() !== $dossier->getStagiaire() || $previous->getSession() !== $dossier->getSession())) throw new \DomainException('La nouvelle tentative doit suivre un dossier publié du même stagiaire et de la même session.');
        return $this->validateTemplate($snapshot['schema']);
    }

    private function sameTenant(Entite $a, ?Entite $b): bool
    {
        return $b !== null && ($a === $b || ($a->getId() !== null && $a->getId() === $b->getId()));
    }

    private function verdict(?string $verdict): void
    {
        if ($verdict !== null && !in_array($verdict, ['favorable', 'defavorable'], true)) throw new \InvalidArgumentException('Avis invalide.');
    }

    private function grading(array $values, array $items, bool $practical, bool $complete): array
    {
        $this->schema->keys($values, array_column($items, 'id'), 'barème');
        $result = [];
        foreach ($items as $item) {
            $grade = $values[$item['id']] ?? [];
            if (!is_array($grade)) throw new \InvalidArgumentException('Correction invalide.');
            $this->schema->keys($grade, $practical ? ['points', 'comment', 'na', 'failed'] : ['points', 'comment'], 'critère corrigé');
            $na = $practical && $this->schema->boolean($grade['na'] ?? false);
            $failed = $practical && $this->schema->boolean($grade['failed'] ?? false);
            if ($na && !$item['allowNa']) throw new \InvalidArgumentException('Ce critère ne peut pas être déclaré non applicable.');
            if ($na && $failed) throw new \InvalidArgumentException('Un critère non applicable ne peut pas être déclaré non acquis.');
            $value = $grade['points'] ?? null;
            $points = $value === null || $value === '' ? null : $this->schema->points($value, $item['points']);
            if ($points !== null && abs($points * 2 - round($points * 2)) > 0.0000001) throw new \InvalidArgumentException('La note doit être un multiple de 0,5 point.');
            if ($na && $points !== null) throw new \InvalidArgumentException('Un critère non applicable ne reçoit pas de note.');
            if ($complete && !$na && $points === null) throw new \DomainException('Tous les éléments applicables doivent être notés avant publication.');
            $result[$item['id']] = ['points' => $points, 'comment' => $this->schema->text($grade['comment'] ?? '', 10000, true)];
            if ($practical) $result[$item['id']] += ['na' => $na, 'failed' => $failed];
        }
        return $result;
    }

    private function sum(array $items, array $grading, bool $practical): array
    {
        $points = 0.0;
        $max = 0.0;
        $complete = true;
        foreach ($items as $item) {
            $grade = $grading[$item['id']] ?? [];
            if ($practical && ($grade['na'] ?? false)) continue;
            $max += $item['points'];
            if (!isset($grade['points'])) $complete = false;
            else $points += $this->schema->points($grade['points'], $item['points']);
        }
        return ['points' => $complete && $max > 0 ? round($points, 2) : null, 'max' => round($max, 2), 'on20' => $complete && $max > 0 ? round(20 * $points / $max, 2) : null];
    }
}
