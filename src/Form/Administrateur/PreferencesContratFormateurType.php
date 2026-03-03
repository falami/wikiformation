<?php

namespace App\Form\Administrateur;

use App\Entity\EntitePreferences;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// src/Form/Administrateur/PreferencesContratFormateurType.php
class PreferencesContratFormateurType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $common = [
      'label' => false,
      'required' => false,
      'attr' => ['rows' => 12, 'class' => 'form-control'],
    ];
    $b
      ->add('contratFormateurConditionsGeneralesDefault', TextareaType::class, $common)
      ->add('contratFormateurConditionsParticulieresDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseEngagementDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseEmploiDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseObjetDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseObligationsDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseNonConcurrenceDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseInexecutionDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseAssuranceDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseFinContratDefault', TextareaType::class, $common)
      ->add('contratFormateurClauseProprieteIntellectuelleDefault', TextareaType::class, $common);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => EntitePreferences::class,
    ]);
  }
}
