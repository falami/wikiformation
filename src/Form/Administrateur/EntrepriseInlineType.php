<?php
// src/Form/Administrateur/EntrepriseInlineType.php
namespace App\Form\Administrateur;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EntrepriseInlineType extends AbstractType
{

  public function getParent(): string
  {
    return EntrepriseType::class; // ✅ réutilise TOUS les champs
  }
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('raisonSociale', TextType::class, [
        'label' => '*Raison sociale',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : WIKI Formation'],
      ])
      ->add('siret', TextType::class, [
        'label' => 'SIRET',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => '14 chiffres'],
      ])
      ->add('emailFacturation', EmailType::class, [
        'label' => 'Email facturation',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'facturation@entreprise.fr'],
      ])
      ->add('email', EmailType::class, [
        'label' => 'Email (contact)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'contact@entreprise.fr'],
      ])

      ->add('numeroTVA', TextType::class, [
        'label' => 'N° TVA intracommunautaire',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : FRXX123456789'],
      ])


      // --- Adresse ---
      ->add('adresse', TextType::class, [
        'label' => 'Adresse',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 12 rue Victor Hugo'],
      ])
      ->add('complement', TextType::class, [
        'label' => 'Complément',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Bâtiment, étage, etc.'],
      ])
      ->add('codePostal', TextType::class, [
        'label' => 'Code postal',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 75001'],
      ])
      ->add('ville', TextType::class, [
        'label' => 'Ville',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Paris'],
      ])
      ->add('departement', TextType::class, [
        'label' => 'Département',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Gard'],
      ])
      ->add('region', TextType::class, [
        'label' => 'Région',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Occitanie'],
      ])
      ->add('pays', TextType::class, [
        'label' => 'Pays',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : France'],
      ]);
  }
  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'locked' => false,
    ]);
    $resolver->setAllowedTypes('locked', 'bool');
  }
}
