<?php
// src/Form/Super/GrantTrialType.php

namespace App\Form\Super;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GrantTrialType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder->add('days', IntegerType::class, [
      'label' => 'Jours d’essai',
      'attr' => ['class' => 'form-control', 'min' => 1, 'max' => 365],
      'data' => $options['default_days'],
    ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'default_days' => 14,
    ]);
    $resolver->setAllowedTypes('default_days', 'int');
  }
}
