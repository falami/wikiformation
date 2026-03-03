<?php

// src/Form/Administrateur/UserSendEmailType.php
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, HiddenType, TextType, TextareaType};
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UserSendEmailType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $tpls = $o['templates'] ?? [];

    $choices = [];
    foreach ($tpls as $t) {
      $choices[$t->getName() ?? $t->getCode() ?? ('Template #' . $t->getId())] = $t->getId();
    }

    $b
      ->add('template', ChoiceType::class, [
        'label' => 'Modèle',
        'choices' => $choices,
        'placeholder' => '- Choisir -',
        'required' => true,
      ])
      ->add('toEmail', TextType::class, [
        'label' => 'À',
        'required' => true,
      ])
      ->add('subject', TextType::class, [
        'label' => 'Objet',
        'required' => false,
      ])
      ->add('message', TextareaType::class, [
        'label' => 'Message',
        'required' => false,
        'attr' => ['rows' => 6],
      ])
      ->add('idemKey', HiddenType::class);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'templates' => [],
    ]);
  }
}
