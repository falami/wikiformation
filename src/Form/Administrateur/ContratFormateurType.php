<?php
// src/Form/Administrateur/ContratFormateurType.php

namespace App\Form\Administrateur;

use App\Entity\ContratFormateur;
use App\Entity\Entite;
use App\Entity\Formateur;
use App\Entity\Session;
use App\Enum\ContratFormateurStatus;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ContratFormateurType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $rowAttr     = ['class' => 'mb-3'];
    $controlAttr = $o['field_attr'] ?? ['class' => 'form-control'];
    $selectAttr  = $o['select_attr'] ?? ['class' => 'form-select'];

    $mergeAttr = static function (array $base, array $extra = []): array {
      $baseClass  = trim((string)($base['class'] ?? ''));
      $extraClass = trim((string)($extra['class'] ?? ''));
      $class = trim($baseClass . ' ' . $extraClass);
      $out = array_merge($base, $extra);
      if ($class !== '') $out['class'] = $class;
      return $out;
    };

    $b
      ->add('formateur', EntityType::class, [
        'class' => Formateur::class,
        'label' => 'Formateur',
        'placeholder' => '- Choisir -',
        'row_attr' => $rowAttr,
        'attr' => $selectAttr,
        'query_builder' => function (EntityRepository $repo) use ($entite) {
          return $repo->createQueryBuilder('f')
            ->leftJoin('f.utilisateur', 'u')->addSelect('u')
            ->andWhere('f.entite = :e')->setParameter('e', $entite)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');
        },
        'choice_label' => function (Formateur $f) {
          $u = $f->getUtilisateur();
          $nom = trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''));
          return $nom ?: ('Formateur #' . $f->getId());
        },
        'choice_attr' => function (Formateur $f) {
          return [
            'data-assujetti-tva' => $f->isAssujettiTva() ? '1' : '0',
            'data-taux-tva'      => (string)($f->getTauxTvaParDefaut() ?? ''),
            'data-numero-tva'    => (string)($f->getNumeroTvaIntra() ?? ''),
            'data-taux-horaire'  => (string)($f->getTauxHoraireCents() ?? ''), // ✅ AJOUT
          ];
        },
      ])

      ->add('session', EntityType::class, [
        'class' => Session::class,
        'label' => 'Session',
        'placeholder' => '- Choisir -',
        'row_attr' => $rowAttr,
        'attr' => $selectAttr,
        'query_builder' => function (EntityRepository $repo) use ($entite) {
          return $repo->createQueryBuilder('s')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->orderBy('s.id', 'DESC');
        },
        'choice_label' => fn(Session $s) => $s->getCode() ? ('Session ' . $s->getCode()) : ('Session #' . $s->getId()),
      ])

      ->add('status', EnumType::class, [
        'class' => ContratFormateurStatus::class,
        'label' => 'Statut',
        'row_attr' => $rowAttr,
        'attr' => $selectAttr,
        'choice_label' => fn(ContratFormateurStatus $s) => method_exists($s, 'label') ? $s->label() : $s->value,
      ])

      // ✅ Champs TVA DU CONTRAT (manquants)
      ->add('assujettiTva', CheckboxType::class, [
        'label' => 'Assujetti à la TVA (contrat)',
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => ['class' => 'form-check-input'],
      ])
      ->add('tauxTva', NumberType::class, [
        'label' => 'Taux TVA (%)',
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['inputmode' => 'decimal', 'placeholder' => '20']),
      ])
      ->add('numeroTvaIntra', TextType::class, [
        'label' => 'N° TVA intracommunautaire',
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['placeholder' => 'FR...']),
      ])

      ->add('montantPrevuCents', MoneyType::class, [
        'label' => 'Honoraires (HT)',
        'currency' => false,
        'divisor' => 100,
        'scale' => 2,
        'html5' => false,
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['inputmode' => 'decimal', 'placeholder' => '0,00']),
        'help' => 'Sélectionne une session et un formateur pour calculer automatiquement (heures × taux horaire).',
      ])

      ->add('fraisMissionCents', MoneyType::class, [
        'label' => 'Frais de mission (HT)',
        'divisor' => 100,
        'currency' => false,
        'scale' => 2,
        'html5' => false,
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['inputmode' => 'decimal', 'placeholder' => '0,00']),
      ])

      ->add('conditionsGenerales', TextareaType::class, [
        'label' => 'Conditions générales',
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['rows' => 8]),
      ])
      ->add('conditionsParticulieres', TextareaType::class, [
        'label' => 'Conditions particulières',
        'required' => false,
        'row_attr' => $rowAttr,
        'attr' => $mergeAttr($controlAttr, ['rows' => 8]),
      ])

      ->add('clauseEngagement', TextareaType::class, ['label' => 'Clause engagement', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseObjet', TextareaType::class, ['label' => 'Clause objet', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseObligations', TextareaType::class, ['label' => 'Clause obligations', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseNonConcurrence', TextareaType::class, ['label' => 'Clause non-concurrence', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseInexecution', TextareaType::class, ['label' => 'Clause inexécution', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseAssurance', TextareaType::class, ['label' => 'Clause assurance', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseFinContrat', TextareaType::class, ['label' => 'Clause fin de contrat', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
      ->add('clauseProprieteIntellectuelle', TextareaType::class, ['label' => 'Clause propriété intellectuelle', 'required' => false, 'row_attr' => $rowAttr, 'attr' => $mergeAttr($controlAttr, ['rows' => 6])])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ContratFormateur::class,
      'field_attr'  => ['class' => 'form-control'],
      'select_attr' => ['class' => 'form-select'],
    ]);
    $r->setRequired('entite');
    $r->setAllowedTypes('entite', Entite::class);
  }
}
