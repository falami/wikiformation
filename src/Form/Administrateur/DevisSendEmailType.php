<?php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{EmailType, TextType, TextareaType, HiddenType, CheckboxType};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class DevisSendEmailType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b

      ->add('to', EmailType::class, [
        'label' => 'Email destinataire',
        'required' => true,
        'constraints' => [
          new Assert\NotBlank(message: 'Email requis.'),
          new Assert\Email(message: 'Email invalide.'),
        ],
        'attr' => [],

        'attr' => ['class' => 'form-control', 'autocomplete' => 'off', 'placeholder' => 'client@exemple.fr'],
      ])
      ->add('subject', TextType::class, [
        'label' => 'Objet',
        'required' => true,
        'constraints' => [
          new Assert\NotBlank(message: 'Objet requis.'),
          new Assert\Length(max: 200),
        ],
        'attr' => ['class' => 'form-control', 'maxlength' => 200],
      ])
      ->add('message', TextareaType::class, [
        'label' => 'Message',
        'required' => false,
        'attr' => ['rows' => 6, 'placeholder' => "Bonjour,\nVeuillez trouver votre devis en pièce jointe.\n\nCordialement,"],
        'constraints' => [new Assert\Length(max: 20000)],
        'attr' => ['class' => 'form-control', 'rows' => 5],
      ])
      ->add('attachPdf', CheckboxType::class, [
        'label' => 'Joindre le PDF du devis',
        'required' => false,
        'data' => true,
      ])
      // ✅ idempotence: anti double envoi
      ->add('idemKey', HiddenType::class, [
        'mapped' => false,
        'required' => true,
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'csrf_protection' => false, // on est en AJAX + idemKey (tu peux passer à true si tu veux gérer csrf token JS)
    ]);
  }
}
