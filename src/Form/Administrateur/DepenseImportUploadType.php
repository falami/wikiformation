<?php
// src/Form/Administrateur/DepenseImportUploadType.php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class DepenseImportUploadType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opt): void
  {
    $b
      ->add('file', FileType::class, [
        'label' => 'Fichier (.xlsx ou .csv)',
        'mapped' => false,
        'required' => true,
        'constraints' => [
          new Assert\NotNull(),
          new Assert\File([
            'maxSize' => '12M',
            'mimeTypes' => [
              // XLSX
              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              // CSV (selon navigateurs)
              'text/plain',
              'text/csv',
              'application/csv',
              'application/vnd.ms-excel',
            ],
          ]),
        ],
        'attr' => ['class' => 'form-control'],
      ])
      ->add('mappingPreset', ChoiceType::class, [
        'label' => 'Type d’export',
        'required' => true,
        'choices' => [
          'Modèle Dive/WK (Date/Libellé/Montant/Devise)' => 'wk',
          'Générique (best effort)' => 'generic',
        ],
        'attr' => ['class' => 'form-select'],
      ]);
  }
}
