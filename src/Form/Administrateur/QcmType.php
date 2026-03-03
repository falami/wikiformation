<?php

namespace App\Form\Administrateur;

use App\Entity\Qcm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QcmType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $options): void
  {
    $b
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('description', TextareaType::class, [
        'label' => 'Description',
        'required' => false,
        'attr' => ['class' => 'form-control', 'rows' => 3],
      ])
      ->add('isActive', CheckboxType::class, [
        'label' => 'Actif',
        'required' => false,
        'row_attr' => ['class' => 'form-check form-switch mt-2'],
        'label_attr' => ['class' => 'form-check-label'],
        'attr' => ['class' => 'form-check-input'],
      ])
      ->add('questions', CollectionType::class, [
        'entry_type' => QcmQuestionType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__q__',   // ✅ IMPORTANT
        'required' => false,
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => Qcm::class]);
  }
}
