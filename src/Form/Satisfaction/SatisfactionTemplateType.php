<?php
// src/Form/Satisfaction/SatisfactionTemplateType.php
namespace App\Form\Satisfaction;

use App\Entity\Entite;
use App\Entity\Formation;
use App\Entity\SatisfactionTemplate;
use App\Repository\FormationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SatisfactionTemplateType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'] ?? null;

    $b
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'attr' => ['class' => 'form-control'],
      ])

      // ✅ Multi formations (filtrées par entité)
      ->add('formations', EntityType::class, [
        'class' => Formation::class,
        'choice_label' => function (Formation $f): string {
          $titre  = (string) $f->getTitre();

          $niveau = $f->getNiveau();
          $niveauLabel = method_exists($niveau, 'label') ? $niveau->label() : $niveau->value;

          $duree = $f->getDuree();
          $dureeLabel = $duree ? ($duree . 'j') : '-';

          return sprintf('%s - %s - %s', $titre, $niveauLabel, $dureeLabel);
        },
        'required' => false,
        'multiple' => true,
        'expanded' => false,
        'label' => 'Formations concernées (optionnel)',
        'help' => 'Si vide : template générique (toutes les formations de l’entité).',
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-ts-placeholder' => 'Choisir une ou plusieurs formations…',
        ],

        'query_builder' => function (FormationRepository $repo) use ($entite) {
          $qb = $repo->createQueryBuilder('f')
            ->orderBy('f.titre', 'ASC');

          if (!$entite) {
            return $qb->andWhere('1 = 0'); // sécurité multi-tenant
          }

          return $qb
            ->andWhere('f.entite = :entite')
            ->setParameter('entite', $entite);
        },
      ])

      ->add('isActive', CheckboxType::class, [
        'label' => 'Actif',
        'required' => false,
      ])

      ->add('chapters', CollectionType::class, [
        'label' => false,
        'entry_type' => SatisfactionChapterType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__chapter__',
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => SatisfactionTemplate::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class, 'int']);
  }
}
