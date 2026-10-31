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
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();
            $stored = $data instanceof Formateur ? $data->getCertifications() : ($data['certifications'] ?? null);
            $subjects = json_decode(file_get_contents(dirname(__DIR__, 2).'/public/dist/recruitment/subjects.json'), true, 512, JSON_THROW_ON_ERROR);
            $choices = [];
            foreach ($subjects as $subject) {
                $choices[$subject['text']] = $subject['text'];
            }
            // Only values already stored on this record may bypass the catalogue.
            // They remain removable, but submitted arbitrary values never become choices.
            $existing = self::split($stored);
            foreach ($existing as $value) {
                if (!isset($choices[$value])) {
                    $choices[$value.' (ancienne saisie)'] = $value;
                }
            }
            $field = $event->getForm()->getConfig()->getFormFactory()->createNamedBuilder('certifications', ChoiceType::class, null, [
                'auto_initialize' => false,
                'label' => 'Spécialités et domaines d’intervention',
                'required' => false,
                'multiple' => true,
                'choices' => $choices,
                'invalid_message' => 'Sélectionnez uniquement des spécialités proposées dans la liste.',
                'help' => 'Recherchez et sélectionnez une ou plusieurs spécialités du catalogue. Les anciennes saisies restent conservées jusqu’à leur retrait.',
                'attr' => ['class' => 'form-select', 'data-trainer-expertise' => '', 'data-placeholder' => 'Rechercher une spécialité…'],
            ]);
            $field->addModelTransformer(new CallbackTransformer(
                static fn (?string $value): array => self::split($value),
                static fn (array $values): ?string => $values === [] ? null : implode("\n", $values),
            ));
            $event->getForm()->add($field->getForm());
        });
    }

    private static function split(?string $value): array
    {
        return $value === null || $value === '' ? [] : array_values(array_unique(explode("\n", $value)));
    }
}
