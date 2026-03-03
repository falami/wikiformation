<?php

namespace App\Form\Administrateur;

use App\Entity\QcmOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class QcmOptionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $options): void
  {
    $b
      ->add('ordre', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-opt-order'],
      ])
      ->add('label', TextType::class, [
        'label' => 'Réponse',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('isCorrect', CheckboxType::class, [
        'label' => 'Bonne réponse',
        'required' => false,
        'attr' => ['class' => 'form-check-input'],
      ])
      ->add('videoUrl', TextType::class, [
        'label' => 'Vidéo (URL)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'https://...'],
      ])
      ->add('imageFile', FileType::class, [
        'label' => 'Image (optionnel)',
        'mapped' => false,
        'required' => false,
        'attr' => ['class' => 'form-control'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => QcmOption::class]);
  }
}
