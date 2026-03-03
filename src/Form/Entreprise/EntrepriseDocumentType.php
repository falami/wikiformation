<?php

declare(strict_types=1);

namespace App\Form\Entreprise;

use App\Entity\EntrepriseDocument;
use App\Enum\EntrepriseDocType;
use App\Entity\{Inscription, Session};
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{EnumType, FileType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EntrepriseDocumentType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opt): void
  {
    $b
      ->add('type', EnumType::class, [
        'class' => EntrepriseDocType::class,
        'choice_label' => fn(EntrepriseDocType $t) => $t->label(),
        'label' => 'Type de document',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('session', EntityType::class, [
        'class' => Session::class,
        'required' => false,
        'label' => 'Rattacher à une session (optionnel)',
        'placeholder' => '— Aucun —',
        'choice_label' => fn(Session $s) => ($s->getFormation()?->getTitre() ?? $s->getFormationIntituleLibre() ?? 'Session')
          . ' · ' . ($s->getCode() ?? ''),
        'attr' => ['class' => 'form-select'],
      ])
      ->add('inscription', EntityType::class, [
        'class' => Inscription::class,
        'required' => false,
        'label' => 'Rattacher à un salarié (optionnel)',
        'placeholder' => '— Aucun —',
        'choice_label' => function (Inscription $i) {
          $u = $i->getStagiaire();
          $name = trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''));
          return $name ?: ('Inscription #' . $i->getId());
        },
        'attr' => ['class' => 'form-select'],
      ])
      ->add('file', FileType::class, [
        'mapped' => false,
        'label' => 'Fichier',
        'attr' => ['class' => 'form-control'],
        'constraints' => [
          new Assert\NotNull(message: 'Veuillez sélectionner un fichier.'),
          new Assert\File(
            maxSize: '12M',
            mimeTypes: [
              'application/pdf',
              'image/png',
              'image/jpeg',
              'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            mimeTypesMessage: 'Formats acceptés: PDF, PNG, JPG, DOCX.',
          )
        ]
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
