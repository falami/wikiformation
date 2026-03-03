<?php

declare(strict_types=1);

namespace App\Form\Entreprise;

use App\Entity\EntrepriseDocument;
use App\Enum\EntrepriseDocType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
  ChoiceType,
  FileType,
  TextType,
  HiddenType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EntrepriseDocumentUploadType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opt): void
  {
    $b
      ->add('type', ChoiceType::class, [
        'label' => 'Type',
        'choices' => array_reduce(EntrepriseDocType::cases(), function ($acc, $c) {
          $acc[$c->label()] = $c;
          return $acc;
        }, []),
        'placeholder' => '— Sélectionner —',
        'attr' => ['class' => 'form-select'],
        'label_attr' => ['class' => 'form-label fw-semibold'],
        'row_attr' => ['class' => 'mb-3'],
      ])
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex: Attestation employeur signée'],
        'label_attr' => ['class' => 'form-label fw-semibold'],
        'row_attr' => ['class' => 'mb-3'],
      ])
      // Liaison optionnelle (remplie côté JS ou côté contrôleur)
      ->add('sessionId', HiddenType::class, [
        'mapped' => false,
      ])
      ->add('file', FileType::class, [
        'mapped' => false,
        'label' => 'Fichier',
        'help' => 'PDF recommandé. Formats acceptés : PDF, PNG, JPG.',
        'required' => true,
        'attr' => ['class' => 'form-control'],
        'constraints' => [
          new Assert\NotNull(),
          new Assert\File([
            'maxSize' => '15M',
            'mimeTypes' => [
              'application/pdf',
              'image/png',
              'image/jpeg',
              'image/jpg',
            ],
            'mimeTypesMessage' => 'Format invalide (PDF/PNG/JPG).',
          ]),
        ],
        'label_attr' => ['class' => 'form-label fw-semibold'],
        'row_attr' => ['class' => 'mb-3'],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => EntrepriseDocument::class,
    ]);
  }
}
