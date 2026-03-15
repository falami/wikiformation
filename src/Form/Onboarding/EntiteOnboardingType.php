<?php

namespace App\Form\Onboarding;

use App\Entity\Entite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
  TextType,
  EmailType,
  HiddenType,
  FileType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EntiteOnboardingType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $colorChoices = [
      'Automatique (par défaut)' => null,
      'Bleu WikiFormation' => '#0d6efd',
      'Indigo' => '#6610f2',
      'Violet' => '#6f42c1',
      'Rose' => '#d63384',
      'Rouge' => '#dc3545',
      'Orange' => '#fd7e14',
      'Jaune' => '#ffc107',
      'Vert' => '#198754',
      'Cyan' => '#0dcaf0',
      'Gris ardoise' => '#6c757d',
      'Noir bleuté' => '#0f2336',
    ];

    $builder
      ->add('nom', TextType::class, [
        'label' => "Nom de l'organisme",
        'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
        'attr' => ['class' => 'form-control form-control-lg'],
      ])
      ->add('email', EmailType::class, [
        'label' => "Email",
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ])
      ->add('siret', TextType::class, [
        'label' => "SIRET",
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ])
      ->add('telephone', TextType::class, [
        'label' => "Téléphone",
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ])
      ->add('ville', TextType::class, [
        'label' => "Ville",
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ])

      // ✅ LOGO (non mappé -> on gère l’upload dans le contrôleur)
      ->add('logoFile', FileType::class, [
        'label' => false,
        'mapped' => false,
        'required' => false,
        'attr' => [
          'accept' => 'image/*',
          'class' => 'd-none', // on le masque: UI custom en Twig
        ],
        'constraints' => [
          new Assert\File(
            maxSize: '2M',
            mimeTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'],
            mimeTypesMessage: 'Format invalide (PNG/JPG/WebP/SVG).',
          ),
        ],
      ])


      ->add('couleurPrincipal', TextType::class, [
        'label' => 'Couleur principale',
        'required' => false,
        'empty_data' => null,
        'attr' => ['class' => 'form-control', 'placeholder' => '#RRGGBB ou laisser vide'],
        'constraints' => [
          new Assert\Regex([
            'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
            'message' => 'Couleur invalide. Format attendu : #RRGGBB',
          ]),
        ],
      ])

      ->add('couleurSecondaire', TextType::class, [
        'label' => 'Couleur secondaire',
        'required' => false,
        'empty_data' => null,
        'attr' => ['class' => 'form-control', 'placeholder' => '#RRGGBB (optionnel)'],
        'constraints' => [
          new Assert\Regex([
            'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
            'message' => 'Couleur invalide. Format attendu : #RRGGBB',
          ]),
        ],
      ])

      ->add('couleurTertiaire', TextType::class, [
        'label' => 'Couleur tertiaire',
        'required' => false,
        'empty_data' => null,
        'attr' => ['class' => 'form-control', 'placeholder' => '#RRGGBB (optionnel)'],
        'constraints' => [
          new Assert\Regex([
            'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
            'message' => 'Couleur invalide. Format attendu : #RRGGBB',
          ]),
        ],
      ])

      ->add('couleurQuaternaire', TextType::class, [
        'label' => 'Couleur quaternaire',
        'required' => false,
        'empty_data' => null,
        'attr' => ['class' => 'form-control', 'placeholder' => '#RRGGBB (optionnel)'],
        'constraints' => [
          new Assert\Regex([
            'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
            'message' => 'Couleur invalide. Format attendu : #RRGGBB',
          ]),
        ],
      ])

      // ✅ plan obligatoire (non mappé)
      ->add('planCode', HiddenType::class, [
        'mapped' => false,
        'constraints' => [new Assert\NotBlank(message: 'Choisissez un plan pour continuer.')],
      ])

      // ✅ périodicité
      ->add('interval', HiddenType::class, [
        'mapped' => false,
        'data' => 'year',
        'constraints' => [
          new Assert\Choice(choices: ['month', 'year'], message: 'Périodicité invalide.'),
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults(['data_class' => Entite::class]);
  }
}
