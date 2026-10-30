<?php
namespace App\Tests\Integration;

use App\Entity\{Entite, FormateurSatisfactionTemplate, FormateurSatisfactionChapter, FormateurSatisfactionQuestion, FormateurSatisfactionAttempt, FormateurSatisfactionAssignment, Session, Utilisateur};
use App\Enum\SatisfactionQuestionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TrainerSatisfactionResponsesViewTest extends KernelTestCase
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
        $template = $this->id((new FormateurSatisfactionTemplate())->setTitre('Évaluation'), 2);
        $chapter = (new FormateurSatisfactionChapter())->setTitre('Votre retour');
        $template->addChapter($chapter);
        $zero = $this->id((new FormateurSatisfactionQuestion())->setLibelle('Votre note')->setType(SatisfactionQuestionType::SCALE), 10);
        $no = $this->id((new FormateurSatisfactionQuestion())->setLibelle('Recommandez-vous ?')->setType(SatisfactionQuestionType::YES_NO), 11);
        $comment = $this->id((new FormateurSatisfactionQuestion())->setLibelle('Commentaire')->setType(SatisfactionQuestionType::TEXTAREA), 12);
        $chapter->addQuestion($zero); $chapter->addQuestion($no); $chapter->addQuestion($comment);
        $session = $this->id((new Session())->setCode('SES-TEST'), 3);
        $person = (new Utilisateur())->setPrenom('Camille')->setNom('Test');
        $assignment = (new FormateurSatisfactionAssignment())->setSession($session)->setTemplate($template)->setFormateur($person);
        $attempt = $this->id((new FormateurSatisfactionAttempt())->setAssignment($assignment)->setAnswers([10 => 0, 11 => 0, 12 => '<script>alert(1)</script>', 99 => 'Ancienne réponse']), 4);
        $html = $twig->load('administrateur/formateur/satisfaction/attempt/show.html.twig')->renderBlock('body', [
            'entite' => $entite, 'template' => $template, 'session' => $session, 'formateur' => $person,
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
