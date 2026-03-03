<?php
// src/Form/Administrateur/ElearningCourseType.php
namespace App\Form\Administrateur;

use App\Entity\Elearning\ElearningCourse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
  TextType,
  TextareaType,
  IntegerType,
  MoneyType,
  CheckboxType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ElearningCourseType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opts): void
  {
    $b
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : Excel — Niveau 1',
        ],
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Length(max: 255),
        ],
      ])

      ->add('description', TextareaType::class, [
        'label' => 'Description',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'rows' => 6,
          'placeholder' => 'Résumé du cours, objectifs, prérequis…',
        ],
        'constraints' => [
          new Assert\Length(max: 5000),
        ],
      ])

      ->add('dureeMinutes', IntegerType::class, [
        'label' => 'Durée (minutes)',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'min' => 0,
          'placeholder' => 'Ex : 90',
        ],
        'constraints' => [
          new Assert\PositiveOrZero(),
        ],
      ])

      // Stocké en cents, affiché en euros grâce à divisor=100
      ->add('prixCents', MoneyType::class, [
        'label' => 'Prix',
        'required' => false,
        'divisor' => 100,
        'currency' => false,
        'input' => 'integer',
        'attr' => [
          'class' => 'form-control text-end',
          'inputmode' => 'decimal',
          'placeholder' => '0,00',
        ],
        'help' => 'Laisse vide ou 0 pour un cours gratuit.',
      ])


      ->add('isPublic', CheckboxType::class, [
        'label' => 'Cours public',
        'required' => false,
        'attr' => [
          'class' => 'form-check-input',
        ],
        'label_attr' => [
          'class' => 'form-check-label',
        ],
      ])

      ->add('isPublished', CheckboxType::class, [
        'label' => 'Publié',
        'required' => false,
        'attr' => [
          'class' => 'form-check-input',
        ],
        'label_attr' => [
          'class' => 'form-check-label',
        ],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ElearningCourse::class,
    ]);
  }
}
