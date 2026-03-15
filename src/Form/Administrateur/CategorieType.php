<?php

namespace App\Form\Administrateur;

use App\Entity\{Categorie, Entite};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{FileType, TextType, CheckboxType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;



final class CategorieType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'] ?? null;

    $b
      ->add('nom', TextType::class, [
        'label' => 'Nom',
      ])
      ->add('slug', TextType::class, [
        'label' => 'Slug',
        'help' => 'Unique par entité (ex: bureautique, excel, word)',
      ])
      ->add('parent', EntityType::class, [
        'class' => Categorie::class,
        'required' => false,
        'placeholder' => '- Aucune (catégorie principale) -',
        'query_builder' => function (EntityRepository $er) use ($entite) {
          $qb = $er->createQueryBuilder('c')
            ->leftJoin('c.parent', 'p')
            ->addSelect('p')
            ->orderBy('p.nom', 'ASC')
            ->addOrderBy('c.nom', 'ASC');

          if ($entite) {
            $qb->andWhere('c.entite = :e')->setParameter('e', $entite);
          }
          return $qb;
        },
        'label' => 'Catégorie parente',
        'attr' => ['class' => 'form-select rounded-3 shadow-sm js-ts', 'data-ts-placeholder' => '- Aucune -'],
      ])
      ->add('photo', FileType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Photo',
        'constraints' => [
          new Image(
            maxSize: '4M'
            )
        ],
      ])
      ->add('showOnHome', CheckboxType::class, [
        'required' => false,
        'label' => 'Afficher sur la page d’accueil',
        'help' => 'Si désactivé, la catégorie n’apparaîtra pas dans la galerie de la page d’accueil.',
      ]);;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Categorie::class,
      'entite' => null,
    ]);
  }
}
