<?php

namespace App\Form\Administrateur;

use App\Entity\EmailTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  EmailType,
  TextType,
  TextareaType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;


final class ProspectSendEmailType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('toEmail', EmailType::class, [
        'label' => 'Destinataire',
        'attr' => ['class' => 'form-control', 'autocomplete' => 'off'],
        'required' => true,
      ])
      ->add('template', EntityType::class, [
        'label' => 'Modèle',
        'class' => EmailTemplate::class,
        'choices' => $o['templates'] ?? [],
        'choice_label' => fn(EmailTemplate $t) => $t->getName(),
        'placeholder' => 'Choisir…',
        'required' => true,
        'attr' => ['class' => 'form-select js-tomselect'],
      ])
      ->add('subject', TextType::class, [
        'label' => 'Sujet',
        'attr' => ['class' => 'form-control', 'maxlength' => 200],
        'required' => true,
      ])
      ->add('idemKey', HiddenType::class, [
        'mapped' => false,
      ])

      ->add('message', TextareaType::class, [
        'label' => 'Message (optionnel)',
        'help' => 'Ajouté à ton modèle si tu l’utilises dans le template (ex: {{ message }}).',
        'required' => false,
        'attr' => ['class' => 'form-control', 'rows' => 5],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => null,
      'templates' => [],
    ]);
    $r->setAllowedTypes('templates', 'array');
  }
}
