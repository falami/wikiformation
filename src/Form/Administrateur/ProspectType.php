<?php
// src/Form/Administrateur/ProspectType.php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\Prospect;
use App\Enum\ProspectSource;
use App\Enum\ProspectStatus;
use App\Form\DataTransformer\FrenchToDateTimeTransformer;
use App\Form\DataTransformer\PhoneNumberTransformer;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{MoneyType, TextType, EmailType, TelType, TextareaType, CheckboxType, EnumType, ChoiceType};
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;



final class ProspectType extends AbstractType
{
  public function __construct(
    private readonly PhoneNumberTransformer $phoneTransformer,
    private readonly FrenchToDateTimeTransformer $dateTimeFrTransformer,
  ) {}

  public function buildForm(FormBuilderInterface $b, array $o): void
  {


    $postes = [
      '—' => null,

      // Direction / Executive
      'Dirigeant / Propriétaire' => 'dirigeant',
      'Président / CEO' => 'ceo',
      'Directeur général (DG)' => 'dg',
      'Directeur général adjoint (DGA)' => 'dga',
      'COO (Ops)' => 'coo',
      'CFO (Finance)' => 'cfo',
      'CTO (Tech)' => 'cto',
      'CIO / DSI' => 'dsi',
      'CMO (Marketing)' => 'cmo',
      'DRH' => 'drh',
      'Directeur commercial' => 'dir_commercial',
      'Directeur des opérations' => 'dir_ops',
      'Directeur de site' => 'dir_site',
      'Directeur de production' => 'dir_production',
      'Directeur administratif' => 'dir_admin',

      // Achat / Finance
      'Responsable achats' => 'resp_achats',
      'Acheteur' => 'acheteur',
      'Responsable administratif et financier (RAF)' => 'raf',
      'Comptable' => 'comptable',
      'Contrôleur de gestion' => 'controleur_gestion',

      // RH
      'Responsable RH' => 'resp_rh',
      'Chargé RH' => 'charge_rh',
      'Chargé recrutement' => 'recrutement',
      'Responsable formation' => 'resp_formation',
      'Chargé formation' => 'charge_formation',
      'Responsable QVCT / RSE' => 'resp_qvct_rse',

      // Qualité / HSE
      'Responsable qualité' => 'resp_qualite',
      'Responsable QHSE' => 'resp_qhse',
      'Responsable HSE' => 'resp_hse',
      'Responsable conformité' => 'resp_conformite',

      // Commercial / Marketing
      'Responsable commercial' => 'resp_commercial',
      'Commercial / Business Developer' => 'commercial',
      'Account Manager' => 'account_manager',
      'Responsable grands comptes' => 'key_account',
      'Responsable marketing' => 'resp_marketing',
      'Chargé marketing' => 'charge_marketing',
      'Responsable communication' => 'resp_com',
      'Chargé communication' => 'charge_com',

      // IT
      'Responsable informatique' => 'resp_it',
      'Administrateur systèmes / réseaux' => 'admin_sys',
      'Chef de projet IT' => 'chef_projet_it',
      'RSSI' => 'rssi',

      // Opérations / Prod
      'Responsable exploitation' => 'resp_exploitation',
      'Responsable production' => 'resp_production',
      'Responsable maintenance' => 'resp_maintenance',
      'Responsable logistique' => 'resp_logistique',
      'Responsable planning' => 'resp_planning',

      // Juridique / Admin
      'Responsable juridique' => 'resp_juridique',
      'Office Manager' => 'office_manager',
      'Assistant(e) de direction' => 'assist_dir',
      'Assistant(e) administratif(ve)' => 'assist_admin',

      // Formation (ton métier)
      'Dirigeant OF' => 'dir_of',
      'Responsable pédagogique' => 'resp_peda',
      'Coordinateur pédagogique' => 'coord_peda',
      'Formateur' => 'formateur',
      'Référent handicap' => 'referent_handicap',
      'Référent qualité (Qualiopi)' => 'referent_qualiopi',

      // Divers
      'Consultant' => 'consultant',
      'Autre (à préciser)' => '__other__',
    ];
    $b
      ->add('civilite', ChoiceType::class, [
        'required' => false,
        'choices' => ['-' => null, 'Monsieur' => 'Monsieur', 'Madame' => 'Madame'],
        'attr' => ['class' => 'form-select']
      ])

      ->add('prenom', TextType::class, [
        'label' => '*Prénom',
        'attr' => [
          'class' => 'form-control',
          'autocomplete' => 'given-name',
        ],
      ])

      ->add('nom', TextType::class, [
        'label' => '*Nom',
        'attr' => [
          'class' => 'form-control',
          'autocomplete' => 'family-name',
        ],
      ])

      ->add('email', EmailType::class, [
        'label' => 'Email',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'autocomplete' => 'email',
          'placeholder' => 'prenom.nom@domaine.fr',
        ],
      ])

      ->add('telephone', TelType::class, [
        'label' => 'Téléphone',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'autocomplete' => 'tel',
          'placeholder' => '+33606060606 (ou 06 06 06 06 06)',
          'inputmode' => 'tel',
        ],
      ])

      ->add('poste', ChoiceType::class, [
        'label' => 'Poste',
        'required' => false,
        'choices' => $postes,
        'attr' => [
          'class' => 'form-select js-ts',   // ✅ TomSelect comme le reste
          'data-placeholder' => 'Choisir un poste…',
        ],
      ])

      ->add('ville', TextType::class, [
        'label' => 'Ville',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ville',
        ],
      ])

      ->add('adresse', TextType::class, [
        'label' => 'Adresse',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Adresse',
        ],
      ])

      ->add('complement', TextType::class, [
        'label' => 'Complement',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Complement',
        ],
      ])

      ->add('codePostal', TextType::class, [
        'label' => 'Code Postal',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Code Postal',
        ],
      ])

      ->add('departement', TextType::class, [
        'label' => 'Département',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Hérault',
        ],
      ])

      ->add('region', TextType::class, [
        'label' => 'Region',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Occitanie',
        ],
      ])

      ->add('pays', TextType::class, [
        'label' => 'Pays',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'France',
        ],
      ])

      ->add('status', EnumType::class, [
        'class' => ProspectStatus::class,
        'label' => '*Statut',
        'attr'  => ['class' => 'form-select js-tomselect'],
        'choice_label' => fn(ProspectStatus $s) => $s->label(),
        'placeholder' => '-',
      ])

      ->add('source', EnumType::class, [
        'class' => ProspectSource::class,
        'label' => 'Source',
        'required' => false,
        'attr'  => ['class' => 'form-select js-tomselect'],
        'choice_label' => fn(ProspectSource $s) => $s->label(),
        'placeholder' => '-',
      ])

      ->add('estimatedValueCents', MoneyType::class, [
        'label' => 'Valeur estimée',
        'required' => false,
        'divisor' => 100,          // ✅ tu stockes en centimes
        'currency' => false,       // ✅ on affiche le symbole à côté (input-group)
        'attr' => [
          'class' => 'form-control text-end',
          'placeholder' => '0,00',
          'inputmode' => 'decimal',
          'min' => 0,
        ],
      ])

      ->add('score', RangeType::class, [
        'label' => 'Score (0–100)',
        'required' => false,
        'attr' => [
          'min' => 0,
          'max' => 100,
          'step' => 1,
          'class' => 'form-range score-range',
        ],
      ])

      ->add('devise', CurrencyType::class, [
        'label' => '*Devise',
        'required' => true,
        'data' => 'EUR', // ✅ défaut comme Facture
        'attr' => ['class' => 'form-select js-ts'],
        // Optionnel : pour remonter quelques devises
        'preferred_choices' => ['EUR', 'USD', 'GBP', 'CHF', 'CAD'],
      ])

      ->add('nextActionAt', TextType::class, [
        'label' => 'Prochaine action',
        'required' => false,
        'attr' => [
          'class' => 'form-control js-flatpickr-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:mm',
          'autocomplete' => 'off',
          'inputmode' => 'numeric',
        ],
        'help' => 'Relance / RDV / prochain appel.',
      ])

      ->add('isActive', CheckboxType::class, [
        'label' => 'Prospect actif',
        'required' => false,
        'row_attr' => ['class' => 'form-check form-switch'],
        'attr' => ['class' => 'form-check-input'],
        'label_attr' => ['class' => 'form-check-label'],
      ])

      ->add('notes', TextareaType::class, [
        'label' => 'Notes',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'rows' => 5,
          'placeholder' => 'Notes internes…',
        ],
      ])
      ->add('linkedEntreprise', EntityType::class, [
        'label' => 'Entreprise',
        'class' => Entreprise::class,
        'choice_label' => 'raisonSociale',
        'placeholder' => '— Sélectionner une entreprise —',
        'required' => false,
        'query_builder' => function (EntityRepository $er) use ($o) {
          $entite = $o['entite'] ?? null;

          $qb = $er->createQueryBuilder('e')
            ->orderBy('e.raisonSociale', 'ASC');

          if ($entite) {
            $qb->andWhere('e.entite = :entite')
              ->setParameter('entite', $entite);
          }

          return $qb;
        },
        'attr' => ['class' => 'form-select js-ts'], // ou js-tomselect selon ton init
      ])

      ->add('googleAddressSearch', TextType::class, [
          'label' => 'Recherche d’adresse',
          'mapped' => false,
          'required' => false,
          'attr' => [
              'class' => 'form-control',
              'placeholder' => 'Commencez à saisir une adresse…',
              'autocomplete' => 'off',
          ],
      ])
    ;




    // ✅ Valeur par défaut uniquement en création
    $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
      $prospect = $event->getData();
      $form = $event->getForm();

      if (!$prospect || null !== $prospect->getId()) return;

      if ($prospect->getScore() === null) {
        $prospect->setScore(50);
        $form->get('score')->setData(50); // ✅ force aussi la valeur du champ
      }
    });

    // ✅ Transformers
    $b->get('telephone')->addModelTransformer($this->phoneTransformer);
    $b->get('nextActionAt')->addModelTransformer($this->dateTimeFrTransformer);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Prospect::class,
      'entite' => null,
    ]);
  }
}
