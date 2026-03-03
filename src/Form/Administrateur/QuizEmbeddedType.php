<?php
// src/Form/Administrateur/QuizEmbeddedType.php
namespace App\Form\Administrateur;

use App\Entity\Quiz;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{TextType, IntegerType, CheckboxType, CollectionType};
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuizEmbeddedType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opts): void
  {
    $b
      ->add('title', TextType::class, [
        'label' => 'Titre du quiz',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Quiz de validation'],
      ])

      // Champs "settings" (non mappés) => on les remettra dans $quiz->settings au contrôleur
      ->add('settingsTimeLimitSec', IntegerType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Limite (sec)',
        'attr' => ['class' => 'form-control', 'min' => 0, 'placeholder' => '0 = illimité'],
      ])
      ->add('settingsShuffleQuestions', CheckboxType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Mélanger les questions',
        'attr' => ['class' => 'form-check-input'],
        'label_attr' => ['class' => 'form-check-label'],
      ])
      ->add('settingsShuffleChoices', CheckboxType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Mélanger les choix',
        'attr' => ['class' => 'form-check-input'],
        'label_attr' => ['class' => 'form-check-label'],
      ])

      ->add('questions', CollectionType::class, [
        'entry_type' => QuizQuestionType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'label' => false,
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Quiz::class,
    ]);
  }
}
