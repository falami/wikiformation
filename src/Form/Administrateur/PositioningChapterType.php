<?php

namespace App\Form\Administrateur;

use App\Entity\PositioningChapter;
use App\Entity\PositioningQuestionnaire;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{IntegerType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositioningChapterType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var PositioningQuestionnaire $q */
    $q = $o['questionnaire'];

    $b
      ->add('title', TextType::class, ['label' => 'Titre'])
      ->add('parent', EntityType::class, [
        'class' => PositioningChapter::class,
        'required' => false,
        'placeholder' => '- Aucun (chapitre racine) -',
        'choice_label' => fn(PositioningChapter $c) => $c->getTitle(),
        'query_builder' => function ($repo) use ($q) {
          return $repo->createQueryBuilder('c')
            ->andWhere('c.questionnaire = :q')->setParameter('q', $q)
            ->orderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC');
        },
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => PositioningChapter::class]);
    $r->setRequired(['questionnaire']);
  }
}
