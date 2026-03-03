<?php

namespace App\Form\Administrateur;

use App\Entity\QcmQuestion;
use App\Enum\QcmQuestionType as QuestionEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

final class QcmQuestionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $options): void
  {
    $b
      ->add('ordre', HiddenType::class, [
        'required' => false,
        'attr' => [
          'class' => 'js-q-order', // utile pour update en JS
        ],
      ])
      ->add('enonce', TextareaType::class, [
        'label' => 'Question',
        'required' => true,
        'empty_data' => '',                 // ✅ empêche null
        'constraints' => [
          new NotBlank(['message' => 'Veuillez saisir l’énoncé de la question.']),
        ],
        'attr' => ['class' => 'form-control', 'rows' => 3],
      ])

      ->add('type', ChoiceType::class, [
        'label' => 'Type de réponse',
        'choices' => [
          'Choix unique' => QuestionEnum::SINGLE,
          'Choix multiple' => QuestionEnum::MULTIPLE,
        ],
        'choice_label' => fn(QuestionEnum $e) => $e->label(),
        'placeholder' => false,     // ✅ pas de vide
        'required' => true,         // ✅ obligatoire
        'constraints' => [
          new NotNull(['message' => 'Veuillez choisir un type de réponse.']),
        ],
        'attr' => ['class' => 'form-select js-tomselect'],
      ])





      ->add('pointsMax', IntegerType::class, [
        'label' => 'Points (barème)',
        'required' => true,
        'attr' => [
          'min' => 0,
          'class' => 'form-control',
        ],
      ])

      ->add('videoUrl', TextType::class, [
        'label' => 'Vidéo (URL)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'https://...'],
      ])
      ->add('imageFile', FileType::class, [
        'label' => 'Image (optionnel)',
        'mapped' => false,
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ])
      ->add('options', CollectionType::class, [
        'entry_type' => QcmOptionType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,   // ✅ IMPORTANT : force l'appel à addOption()
        'prototype_name' => '__o__',   // ✅ IMPORTANT (manquant)
        'prototype' => true,
        'required' => false,
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => QcmQuestion::class]);
  }
}
