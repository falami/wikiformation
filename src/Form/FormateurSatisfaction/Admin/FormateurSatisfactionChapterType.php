<?php

namespace App\Form\FormateurSatisfaction\Admin;

use App\Entity\FormateurSatisfactionChapter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class FormateurSatisfactionChapterType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('titre', TextType::class, [
        'required' => true,
        'empty_data' => '',
      ])

      ->add('position', HiddenType::class, [
        'empty_data' => '1', // string ok, Symfony cast en int
      ])
      ->add('questions', CollectionType::class, [
        'label' => false,
        'entry_type' => FormateurSatisfactionQuestionType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__question__', // ✅ important
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => FormateurSatisfactionChapter::class,
    ]);
  }
}
