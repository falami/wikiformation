<?php
// src/Form/Administrateur/DepenseType.php

namespace App\Form\Administrateur;

use App\Entity\Depense;
use App\Entity\Utilisateur;
use App\Form\DataTransformer\FrenchToDateTransformer;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  CheckboxType,
  ChoiceType,
  CurrencyType,
  FileType,
  MoneyType,
  TextType,
  NumberType,
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\DepenseCategorie;
use App\Entity\DepenseFournisseur;
use App\Repository\DepenseCategorieRepository;
use App\Repository\DepenseFournisseurRepository;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class DepenseType extends AbstractType
{
  public function __construct(private FrenchToDateTransformer $dateFr) {}

  public function buildForm(FormBuilderInterface $b, array $opt): void
  {
    $b
      ->add('libelle', TextareaType::class, [
        'label' => 'Libellé / Détails',
        'attr' => [
          'class' => 'form-control',
          'rows' => 3,
          'placeholder' => "Ex : Achat fournitures (facture #1234) • Licence logiciel • Déplacement…",
        ],
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Length(max: 2000),
        ],
      ])

      ->add('categorie', EntityType::class, [
        'class' => DepenseCategorie::class,
        'required' => false,
        'placeholder' => '- Sélectionner -',
        'choice_label' => 'libelle',
        'label' => 'Catégorie',
        'attr' => ['class' => 'form-select', 'data-enhanced' => 'tomselect'],

        // ✅ IMPORTANT : injecter les data-* dans <option>
        'choice_attr' => function (DepenseCategorie $c) {
          return [
            // HTML: data-type="tax" => JS: opt.dataset.type
            'data-type' => $c->getType() ?? 'operating',

            // HTML: data-actif="1" => JS: opt.dataset.actif
            'data-actif' => $c->isActif() ? '1' : '0',

            // HTML: data-include-charts="1" => JS: opt.dataset.includeCharts
            'data-include-charts' => $c->isIncludeInFinanceCharts() ? '1' : '0',
          ];
        },

        'query_builder' => function (DepenseCategorieRepository $repo) use ($opt) {
          $entite = $opt['entite'];
          return $entite ? $repo->qbForEntite($entite) : $repo->createQueryBuilder('c')->orderBy('c.libelle', 'ASC');
        },
      ])




      ->add('fournisseur', EntityType::class, [
        'class' => DepenseFournisseur::class,
        'required' => false,
        'placeholder' => '- Sélectionner -',
        'choice_label' => 'nom',
        'label' => 'Fournisseur',
        'attr' => ['class' => 'form-select', 'data-enhanced' => 'tomselect'],
        'choice_attr' => function (DepenseFournisseur $f) {
          return [
            'data-color' => $f->getCouleurHex() ?? '',
            'data-siret' => $f->getSiret() ?? '',
          ];
        },
        'query_builder' => function (DepenseFournisseurRepository $repo) use ($opt) {
          $entite = $opt['entite'];
          return $entite ? $repo->qbForEntite($entite) : $repo->createQueryBuilder('f')->orderBy('f.nom', 'ASC');
        },
      ])


      ->add('tvaDeductiblePct', NumberType::class, [
        'label' => '% TVA déductible',
        'required' => false,
        'scale' => 1,
        'html5' => true,
        'attr' => [
          'class' => 'form-control text-end',
          'min' => 0,
          'max' => 100,
          'step' => 0.5,
          'placeholder' => '100',
        ],
        'constraints' => [
          new Assert\Range(['min' => 0, 'max' => 100]),
        ],
      ])

      ->add('payeur', EntityType::class, [
        'class' => Utilisateur::class,
        'required' => false,
        'placeholder' => '- Sélectionner -',
        'choice_label' => fn(Utilisateur $u) => trim(($u->getNom() ?? '') . ' ' . ($u->getPrenom() ?? '')) ?: ($u->getEmail() ?? 'Utilisateur'),
        'label' => 'Payeur (qui a fait la dépense)',
        'attr' => ['class' => 'form-select', 'data-enhanced' => 'tomselect'],
        'query_builder' => function (UtilisateurRepository $repo) use ($opt) {
          $entite = $opt['entite'];

          $qb = $repo->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC');

          if (!$entite) {
            // Sécurité : si pas d'entité fournie, on évite d'afficher tout le monde
            return $qb->andWhere('1 = 0');
          }

          // ⚠️ Adapte "utilisateurEntites" au nom EXACT de ta relation dans Utilisateur
          return $qb
            ->innerJoin('u.utilisateurEntites', 'ue')
            ->andWhere('ue.entite = :entite')
            ->setParameter('entite', $entite)
            ->addOrderBy('u.prenom', 'ASC');
        },
      ])

      ->add('dateDepense', TextType::class, [
        'label' => '*Date de dépense',
        'attr' => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
      ])

      ->add('devise', CurrencyType::class, [
        'label' => '*Devise',
        'attr' => ['class' => 'form-select'],
      ])

      ->add('tauxTva', ChoiceType::class, [
        'label' => 'TVA %',
        'choices' => [
          '20 %'  => 20.0,
          '10 %'  => 10.0,
          '5,5 %' => 5.5,
          '2,1 %' => 2.1,
          '0 %'   => 0.0,
        ],
        'attr' => ['class' => 'form-select'],
      ])

      ->add('tvaDeductible', CheckboxType::class, [
        'label' => 'TVA déductible',
        'required' => false,
        'attr' => ['class' => 'form-check-input'],
        'label_attr' => ['class' => 'form-check-label'],
      ])

      ->add('montantHtCents', MoneyType::class, [
        'label' => 'Montant HT',
        'divisor' => 100,
        'currency' => false,
        'html5' => false,          // ✅ important (évite type="number")
        'grouping' => true,
        'attr' => [
          'class' => 'form-control text-end',
          'placeholder' => '0,00',
          'inputmode' => 'decimal',
          'autocomplete' => 'off',
        ],
      ])

      ->add('montantTvaCents', MoneyType::class, [
        'label' => 'Montant TVA',
        'divisor' => 100,
        'currency' => false,
        'html5' => false,          // ✅
        'grouping' => true,
        'attr' => [
          'class' => 'form-control text-end',
          'placeholder' => '0,00',
          'inputmode' => 'decimal',
          'autocomplete' => 'off',
        ],
      ])

      ->add('montantTtcCents', MoneyType::class, [
        'label' => 'Montant TTC',
        'divisor' => 100,
        'currency' => false,
        'html5' => false,          // ✅
        'grouping' => true,
        'attr' => [
          'class' => 'form-control text-end',
          'placeholder' => '0,00',
          'inputmode' => 'decimal',
          'autocomplete' => 'off',
        ],
      ])


      ->add('justificatifFile', FileType::class, [
        'label' => 'Justificatif (PDF / image)',
        'mapped' => false,
        'required' => false,
        'attr' => ['class' => 'form-control'],
        'constraints' => [
          new Assert\File([
            'maxSize' => '8M',
            'mimeTypes' => [
              'application/pdf',
              'image/jpeg',
              'image/png',
              'image/webp',
            ],
          ]),
        ],
      ])
    ;

    $b->get('dateDepense')->addModelTransformer($this->dateFr);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Depense::class,
      'entite' => null,
    ]);
  }
}
