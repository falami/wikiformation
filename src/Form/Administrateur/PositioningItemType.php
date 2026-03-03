<?php

namespace App\Form\Administrateur;

use App\Entity\PositioningItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositioningItemType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('label', TextareaType::class, [
        'label' => 'Libellé',
        'attr' => ['rows' => 3],
      ])

      ->add('level', ChoiceType::class, [
        'label' => 'Niveau',
        'required' => true,
        'choices' => [
          '1 - Initial'        => 1,
          '2 - Intermédiaire'  => 2,
          '3 - Avancé'         => 3,
          '4 - Expert'         => 4,
        ],
        'placeholder' => 'Choisir un niveau',
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => PositioningItem::class,
    ]);
  }
}
