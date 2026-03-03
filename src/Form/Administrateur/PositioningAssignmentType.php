<?php
// src/Form/Administrateur/PositioningAssignmentType.php
declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\{PositioningQuestionnaire, Entite, Utilisateur};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\UtilisateurEntite;

final class PositioningAssignmentType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('stagiaire', EntityType::class, [
        'class' => Utilisateur::class,
        'mapped' => false,
        'required' => true,
        'placeholder' => 'Stagiaire',
        'choice_label' => static function (Utilisateur $u): string {
          $label = trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? ''));
          return $label !== '' ? $label : ('User#' . $u->getId());
        },
        'query_builder' => static function (EntityRepository $r) use ($entite) {
          return $r->createQueryBuilder('u')
            ->innerJoin(UtilisateurEntite::class, 'ue', 'WITH', 'ue.utilisateur = u')
            ->andWhere('ue.entite = :entite')->setParameter('entite', $entite)
            // ✅ MySQL JSON_CONTAINS
            ->andWhere('JSON_CONTAINS(ue.roles, :roleJson) = 1')
            ->setParameter('roleJson', json_encode(UtilisateurEntite::TENANT_STAGIAIRE))
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');
        },
        'label' => 'Stagiaire',
        'label_attr' => ['class' => 'form-label fw-semibold small mb-1'],
        'row_attr' => ['class' => 'mb-3'],
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-placeholder' => 'Rechercher un stagiaire…',
        ],
      ])

      ->add('questionnaire', EntityType::class, [
        'class' => PositioningQuestionnaire::class,
        'mapped' => false,
        'required' => true,
        'placeholder' => 'Questionnaire',
        'choice_label' => 'title',
        'query_builder' => static fn(EntityRepository $r) => $r->createQueryBuilder('q')
          ->andWhere('q.entite = :e')->setParameter('e', $entite)
          ->orderBy('q.createdAt', 'DESC')
          ->addOrderBy('q.id', 'DESC'),
        'label' => 'Questionnaire',
        'label_attr' => ['class' => 'form-label fw-semibold small mb-1'],
        'row_attr' => ['class' => 'mb-3'],
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-placeholder' => 'Rechercher un questionnaire…',
        ],
      ])

      ->add('isRequired', CheckboxType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Obligatoire',
        'row_attr' => ['class' => 'mb-2'],
        'label_attr' => ['class' => 'form-check-label fw-semibold'],
        'attr' => ['class' => 'form-check-input'],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'csrf_protection' => true,
    ]);
    $r->setRequired(['entite']);
    $r->setAllowedTypes('entite', Entite::class);
  }
}
