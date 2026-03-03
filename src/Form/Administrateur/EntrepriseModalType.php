<?php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\Entite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
  EmailType,
  TextType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class EntrepriseModalType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $options): void
  {
    $b
      ->add('raisonSociale', TextType::class, [
        'label' => '*Raison sociale',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Nom de l’entreprise',
        ],
        'constraints' => [
          new Assert\NotBlank(message: 'La raison sociale est obligatoire'),
          new Assert\Length(max: 255),
        ],
      ])

      ->add('siret', TextType::class, [
        'label' => 'SIRET',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => '14 chiffres',
          'inputmode' => 'numeric',
        ],
        'constraints' => [
          new Assert\Length(
            min: 14,
            max: 14,
            exactMessage: 'Le SIRET doit contenir exactement {{ limit }} chiffres'
          ),
          new Assert\Regex(
            pattern: '/^\d{14}$/',
            message: 'Le SIRET doit contenir uniquement des chiffres'
          ),
        ],
      ])

      ->add('emailFacturation', EmailType::class, [
        'label' => 'Email de facturation',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'facturation@entreprise.fr',
        ],
        'constraints' => [
          new Assert\Email(message: 'Email invalide'),
        ],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Entreprise::class,
      'entite' => null, // ⚠️ injectée par le contrôleur
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
