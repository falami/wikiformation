<?php
namespace App\Tests\Integration;

use App\Entity\{Entite, SatisfactionTemplate, SatisfactionChapter, SatisfactionQuestion, SatisfactionAttempt, SatisfactionAssignment, Session, Utilisateur};
use App\Enum\SatisfactionQuestionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SatisfactionResponsesViewTest extends KernelTestCase
{
    private function id(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
        return $entity;
    }

    public function testIndividualAnswersRenderWithoutLosingZeroOrEscaping(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        // Render the body alone: no authentication or database is needed for layout assertions.
        $entite = $this->id(new Entite(), 1);
        $template = $this->id((new SatisfactionTemplate())->setTitre('Évaluation'), 2);
        $chapter = (new SatisfactionChapter())->setTitre('Votre retour');
        $template->addChapter($chapter);
        $zero = $this->id((new SatisfactionQuestion())->setLibelle('Votre note')->setType(SatisfactionQuestionType::SCALE), 10);
        $no = $this->id((new SatisfactionQuestion())->setLibelle('Recommandez-vous ?')->setType(SatisfactionQuestionType::YES_NO), 11);
        $comment = $this->id((new SatisfactionQuestion())->setLibelle('Commentaire')->setType(SatisfactionQuestionType::TEXTAREA), 12);
        $chapter->addQuestion($zero); $chapter->addQuestion($no); $chapter->addQuestion($comment);
        $session = $this->id((new Session())->setCode('SES-TEST'), 3);
        $person = (new Utilisateur())->setPrenom('Camille')->setNom('Test');
        $assignment = (new SatisfactionAssignment())->setSession($session)->setTemplate($template)->setStagiaire($person);
        $attempt = $this->id((new SatisfactionAttempt())->setAssignment($assignment)->setAnswers([10 => 0, 11 => 0, 12 => '<script>alert(1)</script>', 99 => 'Ancienne réponse']), 4);
        $html = $twig->load('administrateur/satisfaction/attempt/show.html.twig')->renderBlock('body', [
            'entite' => $entite, 'template' => $template, 'session' => $session, 'stagiaire' => $person,
            'assignment' => $assignment, 'attempt' => $attempt, 'formationLabels' => [],
        ]);
        self::assertStringContainsString('0 / 10', $html);
        self::assertStringContainsString('Non</div>', $html);
        self::assertStringContainsString('Votre note', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Ancienne réponse', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
