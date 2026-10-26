<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\SatisfactionQuestion;
use App\Enum\SatisfactionQuestionType as QuestionType;
use App\Form\Satisfaction\SatisfactionQuestionType;
use Symfony\Component\Form\Test\TypeTestCase;

final class SatisfactionQuestionTypeTest extends TypeTestCase
{
    public function testExistingChoicesAreDisplayedAndSurviveAnUnchangedSubmission(): void
    {
        foreach ([QuestionType::CHOICE, QuestionType::MULTICHOICE] as $type) {
            $choices = ['Évolution professionnelle', 'Prise de poste', 'Développement personnel'];
            $question = (new SatisfactionQuestion())->setLibelle('Votre contexte')->setType($type)->setChoices($choices);
            $form = $this->factory->create(SatisfactionQuestionType::class, $question);
            $view = $form->createView();
            self::assertSame(implode("\n", $choices), $view->children['choicesText']->vars['value']);

            $form->submit($this->submission($type, $view->children['choicesText']->vars['value']));
            self::assertTrue($form->isSynchronized());
            self::assertSame($choices, $question->getChoices());
        }
    }

    public function testLegacyChoiceLabelsAreShownInTheirOriginalOrder(): void
    {
        $question = (new SatisfactionQuestion())->setType(QuestionType::CHOICE)->setChoices([
            ['label' => 'Premier choix', 'value' => 'first'],
            'Autre choix',
            ['label' => 'Dernier choix', 'value' => 'last'],
        ]);
        $form = $this->factory->create(SatisfactionQuestionType::class, $question);
        self::assertSame("Premier choix\nAutre choix\nDernier choix", $form->createView()->children['choicesText']->vars['value']);
    }

    public function testEditingChoicesTrimsBlankLinesWithoutDroppingZero(): void
    {
        $question = (new SatisfactionQuestion())->setType(QuestionType::MULTICHOICE)->setChoices(['Ancienne réponse']);
        $form = $this->factory->create(SatisfactionQuestionType::class, $question);
        $form->submit($this->submission(QuestionType::MULTICHOICE, "  0 \r\n\r\n  1  \nNouvelle réponse\n "));
        self::assertTrue($form->isSynchronized());
        self::assertSame(['0', '1', 'Nouvelle réponse'], $question->getChoices());
        self::assertSame("0 \n\n  1  \nNouvelle réponse", $form->createView()->children['choicesText']->vars['value']);
    }

    public function testExplicitlyRemovingAllChoicesRemainsPossible(): void
    {
        $question = (new SatisfactionQuestion())->setType(QuestionType::CHOICE)->setChoices(['Ancienne réponse']);
        $form = $this->factory->create(SatisfactionQuestionType::class, $question);
        $form->submit($this->submission(QuestionType::CHOICE, ''));
        self::assertTrue($form->isSynchronized());
        self::assertNull($question->getChoices());
    }

    private function submission(QuestionType $type, string $choices): array
    {
        return [
            'libelle' => 'Votre contexte', 'type' => $type->value, 'required' => '1',
            'help' => '', 'placeholder' => '', 'choicesText' => $choices,
            'metricKey' => '', 'position' => '1',
        ];
    }
}
