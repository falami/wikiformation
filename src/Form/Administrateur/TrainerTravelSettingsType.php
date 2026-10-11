<?php

declare(strict_types=1);
namespace App\Form\Administrateur;

use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, ChoiceType, NumberType};
use Symfony\Component\Validator\Constraints\{NotNull, Range};

final class TrainerTravelSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rate', NumberType::class, ['label' => 'Indemnité kilométrique (€ / km)', 'scale' => 3, 'html5' => true, 'attr' => ['min' => 0, 'max' => 10, 'step' => '0.001'], 'constraints' => [new NotNull(), new Range(min: 0, max: 10)]])
            ->add('returnTrip', CheckboxType::class, ['label' => 'Inclure le retour (aller-retour)', 'required' => false, 'row_attr' => ['class' => 'form-switch mb-4']])
            ->add('frequency', ChoiceType::class, ['label' => 'Fréquence des déplacements', 'expanded' => true, 'choices' => ['Chaque jour d’intervention' => 'daily', 'Un seul déplacement pour la session (séjour sur place)' => 'once'], 'constraints' => [new NotNull()]]);
    }
}
