<?php

namespace App\Form;

use App\Entity\Formateur;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/** Shared by the standalone trainer editor and the embedded user form. */
final class TrainerExpertiseField
{
    public static function attach(FormBuilderInterface $builder): void
    {
        $configure = static function (FormEvent $event): void {
            $data = $event->getData();
            $submitted = $event instanceof \Symfony\Component\Form\Event\PreSubmitEvent;
            $stored = $data instanceof Formateur ? $data->getCertifications() : ($data['certifications'] ?? null);
            $existing = $submitted ? (is_array($stored) ? $stored : []) : self::split($stored);
            if ($submitted && (!is_array($stored ?? []) || count($existing) > 40)) {
                $event->getForm()->addError(new \Symfony\Component\Form\FormError('Sélectionnez au maximum 40 spécialités.'));
                $existing = [];
            }
            $subjects = json_decode(file_get_contents(dirname(__DIR__, 2).'/public/dist/recruitment/subjects.json'), true, 512, JSON_THROW_ON_ERROR);
            $choices = [];
            foreach ($subjects as $subject) {
                $choices[$subject['text']] = $subject['text'];
            }
            foreach ($existing as $value) {
                if (!is_string($value) || ($submitted && (mb_strlen(trim($value)) < 1 || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $value)))) {
                    continue;
                }
                if (!isset($choices[$value])) {
                    $choices[$value] = $value;
                }
            }
            $field = $event->getForm()->getConfig()->getFormFactory()->createNamedBuilder('certifications', ChoiceType::class, null, [
                'auto_initialize' => false,
                'label' => 'Spécialités et domaines d’intervention',
                'required' => false,
                'multiple' => true,
                'choices' => $choices,
                'invalid_message' => 'Chaque spécialité doit contenir entre 1 et 255 caractères, sur une seule ligne.',
                'help' => 'Recherchez une spécialité dans le catalogue ou saisissez la vôtre puis cliquez sur « Ajouter ». Jusqu’à 40 spécialités.',
                'attr' => ['class' => 'form-select', 'data-trainer-expertise' => '', 'data-placeholder' => 'Rechercher une spécialité…'],
            ]);
            $field->addModelTransformer(new CallbackTransformer(
                static fn (?string $value): array => self::split($value),
                static fn (array $values): ?string => $values === [] ? null : implode("\n", array_unique(array_map('trim', $values))),
            ));
            $event->getForm()->add($field->getForm());
        };
        $builder->addEventListener(FormEvents::PRE_SET_DATA, $configure);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $configure);
    }

    private static function split(?string $value): array
    {
        return $value === null || $value === '' ? [] : array_values(array_unique(explode("\n", $value)));
    }
}
