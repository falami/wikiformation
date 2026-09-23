<?php

namespace App\Form\Administrateur;

use App\Entity\SessionJour;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DevisSessionJourType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['dateDebut' => 'Début', 'dateFin' => 'Fin'] as $name => $label) {
            $builder->add($name, DateTimeType::class, [
                'label' => $label,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['data-datepicker' => 'datetime', 'autocomplete' => 'off'],
            ]);
        }
        $builder->add('pauseMinutes', IntegerType::class, [
            'label' => 'Pause (minutes)',
            'required' => false,
            'help' => 'Une journée prévoit 7 h de cours et 90 min de pause déjeuner. Vide : 90 min déduites sur une journée complète, aucune sur une demi-journée. Saisissez 0 ou une autre durée pour adapter la pause.',
            'attr' => ['min' => 0, 'step' => 1, 'placeholder' => 'Automatique', 'data-training-pause' => ''],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SessionJour::class, 'attr' => ['data-training-slot' => '']]);
    }
}
