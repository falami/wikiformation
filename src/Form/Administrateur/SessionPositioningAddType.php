<?php

namespace App\Form\Administrateur;

use App\Entity\PositioningQuestionnaire;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, IntegerType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SessionPositioningAddType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('questionnaire', EntityType::class, [
        'class' => PositioningQuestionnaire::class,
        'choice_label' => fn(PositioningQuestionnaire $q) => $q->getTitle(),
        'placeholder' => '- Choisir un questionnaire -',
      ])
      ->add('isRequired', CheckboxType::class, [
        'required' => false,
        'label' => 'Obligatoire',
      ])
      ->add('position', IntegerType::class, [
        'required' => false,
        'empty_data' => '0',
        'label' => 'Ordre',
      ])
      ->add('generateForExisting', CheckboxType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Créer les tentatives pour les stagiaires déjà inscrits',
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([]);
  }
}
