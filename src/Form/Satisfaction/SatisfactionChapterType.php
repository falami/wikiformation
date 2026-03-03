<?php
// src/Form/Satisfaction/SatisfactionChapterType.php
namespace App\Form\Satisfaction;

use App\Entity\SatisfactionChapter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SatisfactionChapterType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('titre', TextType::class, ['label' => 'Titre du chapitre'])
      ->add('position', HiddenType::class, [
        'empty_data' => '1', // string ok, Symfony cast en int
      ])

      // src/Form/Satisfaction/SatisfactionChapterType.php
      ->add('questions', CollectionType::class, [
        'label' => false,
        'entry_type' => SatisfactionQuestionType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__question__', // ✅ important
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => SatisfactionChapter::class]);
  }
}
