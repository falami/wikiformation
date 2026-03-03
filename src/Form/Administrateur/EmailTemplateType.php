<?php

namespace App\Form\Administrateur;

use App\Entity\EmailTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  TextType,
  TextareaType,
  CheckboxType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EmailTemplateType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('name', TextType::class, [
        'label' => 'Nom',
        'attr' => ['class' => 'form-control', 'maxlength' => 160],
      ])
      ->add('code', TextType::class, [
        'label' => 'Code (unique par entité)',
        'help' => 'Ex: prospect_relance_j3',
        'attr' => ['class' => 'form-control', 'maxlength' => 80],
      ])
      ->add('subject', TextType::class, [
        'label' => 'Sujet',
        'help' => 'Twig OK: {{ entite.nom }}, {{ prospect.prenom }}, etc.',
        'attr' => ['class' => 'form-control', 'maxlength' => 200],
      ])
      ->add('bodyHtml', TextareaType::class, [
        'label' => 'Corps HTML (Twig)',
        'help' => 'Tu peux écrire du HTML + variables Twig (admin uniquement).',
        'attr' => ['class' => 'form-control', 'rows' => 18],
      ])
      ->add('isActive', CheckboxType::class, [
        'label' => 'Actif',
        'required' => false,
        'attr' => ['class' => 'form-check-input'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => EmailTemplate::class]);
  }
}
