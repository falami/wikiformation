<?php

// src/Form/Administrateur/FormateurType.php
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use App\Entity\Engin;
use App\Entity\Site;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Validator\Constraints\{
  Image,
};
use Symfony\Component\Form\Extension\Core\Type\{FileType, TextareaType, TextType, CheckboxType, ChoiceType, MoneyType};

class FormateurInlineType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder

      ->add('qualificationEngins', EntityType::class, [
        'class' => Engin::class,
        'choice_label' => fn(Engin $b) => $b->getNom() . ' (' . $b->getSite()->getNom() . ')',
        'label' => 'Engins qualifiés',
        'multiple' => true,
        'expanded' => false,
        'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélection multiple'],
      ])
      ->add('sitePreferes', EntityType::class, [
        'class' => Site::class,
        'choice_label' => fn(Site $s) => $s->getNom() . ' - ' . $s->getVille(),
        'label' => 'Sites préférés',
        'multiple' => true,
        'expanded' => false,
        'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélection multiple'],
      ])

      ->add('certifications', TextareaType::class, [
        'label' => 'Certifications',
        'required' => false,
        'attr' => ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'STCW, Médical, etc.'],
      ])
      ->add('assujettiTva', CheckboxType::class, [
        'label' => 'Formateur assujetti à la TVA',
        'required' => false,
      ])
      ->add('tauxTvaParDefaut', ChoiceType::class, [
        'label' => 'Taux de TVA appliqué',
        'required' => false,
        'placeholder' => 'Choisir un taux',
        'choices' => [
          '0 % (non assujetti / exonéré)' => 0,
          '5,5 %' => 5.5,
          '10 %'  => 10.0,
          '20 %'  => 20.0,
        ],
        'attr' => ['class' => 'form-select'],
      ])

      ->add('numeroTvaIntra', TextType::class, [
        'label' => 'N° de TVA',
        'attr'  => ['class' => 'form-control', 'placeholder' => 'FR00000000000'],
        'required' => false,
      ])

      ->add('modeRemuneration', ChoiceType::class, [
        'label' => 'Mode de rémunération',
        'required' => false,
        'placeholder' => 'Non défini',
        'choices' => [
          'À l’heure'   => 'HEURE',
          'À la journée' => 'JOUR',
        ],
        'attr' => ['class' => 'form-select'],
      ])
      ->add('tauxHoraireCents', MoneyType::class, [
        'label' => 'Taux horaire HT',
        'required' => false,
        'divisor' => 100,
        'currency' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 60,00'],
      ])
      ->add('tauxJournalierCents', MoneyType::class, [
        'label' => 'Taux journalier HT',
        'required' => false,
        'divisor' => 100,
        'currency' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 400,00'],
      ])
      ->add('siret', TextType::class, [
        'required' => false,
        'attr'  => ['class' => 'form-control', 'placeholder' => '000 000 000 00000'],
        'required' => false,
      ])
      ->add('representant', EntityType::class, [
        'class' => Utilisateur::class,
        'choice_label' => fn(Utilisateur $u) => trim(($u->getNom() ?? '') . ' ' . ($u->getPrenom() ?? '')) . ' - ' . $u->getEmail(),
        'label' => 'Représentant',
        'placeholder' => '— Choisir —',
        'required' => false,
        'attr' => ['class' => 'form-select'],
        'query_builder' => fn($er) => $er->createQueryBuilder('u')
          ->orderBy('u.nom', 'ASC')
          ->addOrderBy('u.prenom', 'ASC'),
      ])
    ;
  }
}
