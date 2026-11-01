<?php

declare(strict_types=1);

namespace App\Tests\Service\Evaluation;

use App\Entity\{Entite, EvaluationDossier, EvaluationTemplate, Inscription, Session, Utilisateur};
use App\Service\Evaluation\EvaluationWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluationWorkflowTest extends TestCase
{
    private EvaluationWorkflow $workflow;
    protected function setUp(): void { $this->workflow = new EvaluationWorkflow(); }

    private function schema(): array
    {
        return [
            'theory' => [
                ['id' => 'q1', 'label' => 'Un choix', 'type' => 'single', 'points' => 2, 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'correct' => ['a']],
                ['id' => 'q2', 'label' => 'Plusieurs choix', 'type' => 'multiple', 'points' => 2, 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B'], ['id' => 'c', 'label' => 'C']], 'correct' => ['a', 'c']],
                ['id' => 'q3', 'label' => 'Ordre', 'type' => 'order', 'points' => 2, 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'correct' => ['b', 'a']],
                ['id' => 'q4', 'label' => 'Expliquez', 'type' => 'text', 'points' => 4],
            ],
            'practice' => [
                ['id' => 'p1', 'label' => 'Critère essentiel', 'points' => 4, 'critical' => true, 'allowNa' => false],
                ['id' => 'p2', 'label' => 'Critère facultatif', 'points' => 6, 'critical' => false, 'allowNa' => true],
            ],
            'theoryThreshold' => 12, 'practiceThreshold' => 14,
        ];
    }

    private function dossier(?array $schema = null): EvaluationDossier
    {
        $tenant = (new Entite())->setNom('Organisme');
        $learner = (new Utilisateur())->setPrenom('Léa')->setNom('Martin');
        $trainer = (new Utilisateur())->setPrenom('Alex')->setNom('Dupont');
        $session = (new Session())->setEntite($tenant)->setCode('SESSION-TEST');
        $template = (new EvaluationTemplate())->setEntite($tenant)->setCreateur($trainer)->setTitle('Travail en hauteur')->setSchema($schema ?? $this->schema());
        $inscription = (new Inscription())->setEntite($tenant)->setSession($session)->setStagiaire($learner);
        return (new EvaluationDossier())->setEntite($tenant)->setTemplate($template)->setInscription($inscription)->setEvaluator($trainer)->setCreateur($trainer)->setSnapshot($this->workflow->snapshotTemplate($template));
    }

    private function submit(EvaluationDossier $dossier): void
    {
        $this->workflow->submitTheory($dossier, ['q1' => 'a', 'q2' => ['c', 'a'], 'q3' => ['b', 'a'], 'q4' => 'Réponse du stagiaire.']);
    }

    private function review(EvaluationDossier $dossier, ?array $practice = null, ?string $theoryVerdict = 'favorable', ?string $practiceVerdict = 'favorable'): void
    {
        $this->workflow->saveReview($dossier, ['q1' => ['points' => 2], 'q2' => ['points' => 2], 'q3' => ['points' => 2], 'q4' => ['points' => 2.5]], $practice ?? ['p1' => ['points' => 4], 'p2' => ['points' => 4.5]], $theoryVerdict, $practiceVerdict);
    }

    public function testPublicationRequiresTrainerReviewAndBothVerdictsThenFreezesIdentities(): void
    {
        $d = $this->dossier();
        self::assertSame('assigned', $d->getState());
        $this->submit($d);
        self::assertSame('to_review', $d->getState());
        self::assertFalse($d->isSuccessful());
        $this->review($d);
        self::assertFalse($d->isSuccessful(), 'A favorable draft is not a published success.');
        $this->workflow->publish($d);
        self::assertSame('published', $d->getState());
        self::assertTrue($d->isSuccessful());
        self::assertSame(17.0, $d->getPublicationRecord()['scores']['theory']['on20']);
        $d->getStagiaire()->setNom('Modifié après publication');
        $d->getEvaluator()->setNom('Autre nom');
        self::assertSame('Léa Martin', $d->getPublicationRecord()['learnerName']);
        self::assertSame('Alex Dupont', $d->getPublicationRecord()['evaluatorName']);
        $this->expectException(\DomainException::class);
        $d->setEvaluator(new Utilisateur());
    }

    public function testSuggestionsUseTheFrozenAnswerKeyAndNeverMutateTheReview(): void
    {
        $d = $this->dossier();
        $this->submit($d);
        $d->getTemplate()->setSchema([])->setTitle('Un autre modèle');
        $suggestions = $this->workflow->suggestTheoryGrading($d);
        self::assertSame(2.0, $suggestions['q1']['points']);
        self::assertSame(2.0, $suggestions['q2']['points']);
        self::assertSame(2.0, $suggestions['q3']['points']);
        self::assertNull($suggestions['q4']['points']);
        self::assertSame([], $d->getTheoryGrading());
        self::assertSame('Travail en hauteur', $d->getSnapshot()['title']);
    }

    public function testWrongOrderAndPartialMultipleChoiceReceiveNoAutomaticCredit(): void
    {
        $d = $this->dossier();
        $this->workflow->submitTheory($d, ['q1' => [], 'q2' => ['a'], 'q3' => ['a', 'b'], 'q4' => '']);
        $suggestions = $this->workflow->suggestTheoryGrading($d);
        self::assertSame(0.0, $suggestions['q1']['points']);
        self::assertSame(0.0, $suggestions['q2']['points']);
        self::assertSame(0.0, $suggestions['q3']['points']);
    }

    public function testNonApplicableCriterionIsRemovedFromTheDenominator(): void
    {
        $d = $this->dossier();
        $this->submit($d);
        $this->review($d, ['p1' => ['points' => 3], 'p2' => ['na' => true]]);
        self::assertSame(['points' => 3.0, 'max' => 4.0, 'on20' => 15.0], $this->workflow->scoreSummary($d)['practice']);
        $this->workflow->publish($d);
        self::assertTrue($d->isSuccessful());
    }

    public function testAnUnfavorableVerdictPreventsSuccessDespiteGoodScores(): void
    {
        $d = $this->dossier(); $this->submit($d); $this->review($d, null, 'defavorable');
        $this->workflow->publish($d);
        self::assertFalse($d->isSuccessful());
    }

    public function testPublishedReviewCannotBeEditedOrPublishedAgain(): void
    {
        $d = $this->dossier(); $this->submit($d); $this->review($d); $this->workflow->publish($d);
        $this->expectException(\DomainException::class);
        $this->review($d);
    }

    public function testDoubleTheorySubmissionIsRejected(): void
    {
        $d = $this->dossier(); $this->submit($d);
        $this->expectException(\DomainException::class);
        $this->submit($d);
    }

    public function testDraftScoresDoNotPretendToBeACompleteResult(): void
    {
        $d = $this->dossier(); $this->submit($d);
        $this->workflow->saveReview($d, ['q1' => ['points' => 1.25]], [], null, null);
        self::assertNull($this->workflow->scoreSummary($d)['theory']['on20']);
        self::assertNull($this->workflow->scoreSummary($d)['practice']['on20']);
        self::assertSame(1.25, $d->getTheoryGrading()['q1']['points']);
        $this->expectException(\DomainException::class);
        $this->workflow->publish($d);
    }

    public function testMissingScoreCannotBePublishedEvenWithFavorableVerdicts(): void
    {
        $d = $this->dossier(); $this->submit($d);
        $this->workflow->saveReview($d, ['q1' => ['points' => 1]], ['p1' => ['points' => 4], 'p2' => ['points' => 6]], 'favorable', 'favorable');
        $this->expectException(\DomainException::class);
        $this->workflow->publish($d);
    }

    public function testCriticalFailurePreventsFavorablePractice(): void
    {
        $d = $this->dossier(); $this->submit($d);
        $this->review($d, ['p1' => ['points' => 4, 'failed' => true], 'p2' => ['points' => 6]]);
        $this->expectException(\DomainException::class);
        $this->workflow->publish($d);
    }

    public function testThresholdIsCheckedAgainstTheUnroundedNormalizedScore(): void
    {
        $schema = $this->schema(); $schema['practiceThreshold'] = 13.34;
        $schema['practice'] = [['id' => 'p1', 'label' => 'Critère', 'points' => 3]];
        $d = $this->dossier($schema); $this->submit($d);
        $this->review($d, ['p1' => ['points' => 2]]);
        self::assertSame(13.33, $this->workflow->scoreSummary($d)['practice']['on20']);
        $this->expectException(\DomainException::class);
        $this->workflow->publish($d);
    }

    public function testNoDefaultPassThresholdIsInvented(): void
    {
        $schema = $this->schema(); unset($schema['theoryThreshold'], $schema['practiceThreshold']);
        $d = $this->dossier($schema); $this->submit($d);
        $this->review($d, ['p1' => ['points' => 1], 'p2' => ['points' => 0]], 'favorable', 'favorable');
        $this->workflow->publish($d);
        self::assertTrue($d->isSuccessful(), 'Without a configured threshold, the explicit professional verdict controls success.');
    }

    public function testAllPracticalCriteriaNonApplicableCannotProduceSuccess(): void
    {
        $schema = $this->schema(); $schema['practice'][0]['allowNa'] = true;
        $d = $this->dossier($schema); $this->submit($d);
        $this->review($d, ['p1' => ['na' => true], 'p2' => ['na' => true]]);
        $this->expectException(\DomainException::class);
        $this->workflow->publish($d);
    }

    public function testSnapshotCannotBeReplacedAfterAssignment(): void
    {
        $d = $this->dossier();
        $this->expectException(\DomainException::class);
        $d->setSnapshot(['title' => 'Changed']);
    }

    public function testEditingTheOriginalRegistrationNeverTransfersTheEvaluation(): void
    {
        $d = $this->dossier();
        $learner = $d->getStagiaire(); $session = $d->getSession();
        $d->getInscription()->setStagiaire(new Utilisateur())->setSession((new Session())->setEntite($d->getEntite()));
        self::assertSame($learner, $d->getStagiaire());
        self::assertSame($session, $d->getSession());
        $this->submit($d); $this->review($d); $this->workflow->publish($d);
        self::assertSame('Léa Martin', $d->getPublicationRecord()['learnerName']);
        self::assertSame('SESSION-TEST', $d->getPublicationRecord()['sessionCode']);
    }

    public function testRegistrationCannotBeReplacedOnAnExistingDossier(): void
    {
        $d = $this->dossier();
        $this->expectException(\DomainException::class);
        $d->setInscription($this->dossier()->getInscription());
    }

    public function testCrossTenantInscriptionIsRejected(): void
    {
        $d = $this->dossier(); $d->getInscription()->setEntite(new Entite());
        $this->expectException(\DomainException::class);
        $this->submit($d);
    }

    public function testCrossTenantSessionIsRejected(): void
    {
        $d = $this->dossier(); $d->getSession()->setEntite(new Entite());
        $this->expectException(\DomainException::class);
        $this->submit($d);
    }

    public function testRetakePreservesAndReferencesPublishedPreviousAttempt(): void
    {
        $d = $this->dossier(); $this->submit($d); $this->review($d, null, 'defavorable'); $this->workflow->publish($d);
        $retake = (new EvaluationDossier())->setEntite($d->getEntite())->setTemplate($d->getTemplate())->setInscription($d->getInscription())->setEvaluator($d->getEvaluator())->setCreateur($d->getCreateur())->setPreviousDossier($d)->setSnapshot($d->getSnapshot());
        $this->submit($retake); $this->review($retake); $this->workflow->publish($retake);
        self::assertFalse($d->isSuccessful()); self::assertTrue($retake->isSuccessful());
        self::assertSame($d, $retake->getPreviousDossier());
    }

    #[DataProvider('invalidGrades')]
    public function testInvalidScoresAreRejectedWithoutPartiallyChangingDraft(mixed $score): void
    {
        $d = $this->dossier(); $this->submit($d);
        try {
            $this->workflow->saveReview($d, ['q1' => ['points' => $score]], [], null, null);
            self::fail('Invalid score was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $d->getTheoryGrading());
            self::assertSame([], $d->getPracticeGrading());
        }
    }

    public static function invalidGrades(): iterable
    {
        yield [-1]; yield [2.01]; yield [NAN]; yield [INF]; yield ['NaN']; yield [true]; yield [[]]; yield [0.001];
    }

    #[DataProvider('invalidSchemas')]
    public function testMalformedTemplatesAreRejected(string $case): void
    {
        $schema = $this->schema();
        switch ($case) {
            case 'duplicate': $schema['theory'][1]['id'] = 'q1'; break;
            case 'unknownCorrect': $schema['theory'][0]['correct'] = ['unavailable']; break;
            case 'partialOrder': $schema['theory'][2]['correct'] = ['a']; break;
            case 'emptyPractice': $schema['practice'] = []; break;
            case 'nan': $schema['practice'][0]['points'] = NAN; break;
            case 'threshold': $schema['theoryThreshold'] = 21; break;
            case 'type': $schema['theory'][0]['type'] = 'secret'; break;
            case 'unknownField': $schema['theory'][0]['hiddenResult'] = true; break;
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->workflow->validateTemplate($schema);
    }

    public static function invalidSchemas(): iterable
    {
        foreach (['duplicate', 'unknownCorrect', 'partialOrder', 'emptyPractice', 'nan', 'threshold', 'type', 'unknownField'] as $case) yield [$case];
    }

    #[DataProvider('invalidAnswers')]
    public function testMalformedTheorySubmissionDoesNotLockTheTest(array $overrides): void
    {
        $d = $this->dossier();
        try {
            $this->workflow->submitTheory($d, array_replace(['q1' => 'a', 'q2' => [], 'q3' => ['a', 'b'], 'q4' => ''], $overrides));
            self::fail('Invalid submission was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertNull($d->getTheorySubmittedAt());
            self::assertSame([], $d->getTheoryAnswers());
        }
    }

    public static function invalidAnswers(): iterable
    {
        yield [['q1' => ['a', 'b']]];
        yield [['q2' => ['a', 'a']]];
        yield [['q2' => ['fake']]];
        yield [['q3' => ['b']]];
        yield [['q4' => ['not', 'text']]];
        yield [['foreign_question' => 'a']];
    }
}
