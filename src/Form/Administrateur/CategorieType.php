<?php

namespace App\Form\Administrateur;

use App\Entity\{Categorie, Entite};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{FileType, TextType, CheckboxType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\String\Slugger\AsciiSlugger;
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
        'required' => false,
        'empty_data' => '',
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
          } else {
            $qb->andWhere('1 = 0');
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
        'help' => 'JPEG, PNG, WebP ou GIF · 4 Mo maximum. Taille conseillée : 2 400 pixels sur le côté le plus long.',
        'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/gif'],
        'constraints' => [
          new Image(
            maxSize: '4M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            mimeTypesMessage: 'Choisissez une image JPEG, PNG, WebP ou GIF.',
            maxPixels: 24_000_000,
            maxPixelsMessage: 'Cette image est trop grande. Réduisez-la à 2 400 pixels sur son côté le plus long.'
            )
        ],
      ])
      ->add('showOnHome', CheckboxType::class, [
        'required' => false,
        'label' => 'Afficher sur la page d’accueil',
        'help' => 'Si désactivé, la catégorie n’apparaîtra pas dans la galerie de la page d’accueil.',
      ]);

    $b->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
      $data = $event->getData();
      if (!is_array($data)) return;
      if (is_string($data['nom'] ?? null) && (!isset($data['slug']) || $data['slug'] === null || (is_string($data['slug']) && trim($data['slug']) === ''))) {
        $data['slug'] = substr((string) (new AsciiSlugger('fr'))->slug($data['nom'])->lower(), 0, 140);
        $event->setData($data);
      }
    });
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Categorie::class,
      'entite' => null,
    ]);
  }
}
