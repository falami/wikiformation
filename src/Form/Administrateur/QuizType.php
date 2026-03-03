<?php
// src/Form/Administrateur/QuizType.php
namespace App\Form\Administrateur;

use App\Entity\Quiz;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{IntegerType, TextType, CollectionType, CheckboxType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QuizType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b->add('title', TextType::class, ['label'=>'Titre du quiz', 'required'=>false, 'attr'=>['class'=>'form-control']])
          // mini settings explicit
          ->add('settingsShuffleQuestions', CheckboxType::class, [
              'label'=>'Mélanger les questions', 'required'=>false, 'mapped'=>false, 'row_attr'=>['class'=>'form-check form-switch'], 'attr'=>['class'=>'form-check-input']
          ])
          ->add('settingsShuffleChoices', CheckboxType::class, [
              'label'=>'Mélanger les choix', 'required'=>false, 'mapped'=>false, 'row_attr'=>['class'=>'form-check form-switch'], 'attr'=>['class'=>'form-check-input']
          ])
          ->add('settingsTimeLimitSec', IntegerType::class, [
              'label'=>'Limite (sec)', 'required'=>false, 'mapped'=>false, 'empty_data'=>'0', 'attr'=>['min'=>0, 'class'=>'form-control']
          ])
          ->add('questions', CollectionType::class, [
              'entry_type'=>QuizQuestionType::class,
              'allow_add'=>true, 
              'entry_options'=> ['allow_extra_fields' => true],
              'allow_delete'=>true, 
              'by_reference'=>false,
              'prototype'=>true, 
              'label'=>'Questions'
          ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void 
    { 
        $r->setDefaults([
            'data_class'=>Quiz::class,
            'allow_extra_fields' => true,
        ]);
    }
}
