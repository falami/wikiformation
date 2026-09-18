<?php

namespace App\Form\Administrateur;

use App\Entity\SessionJour;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SessionJour::class]);
    }
}
