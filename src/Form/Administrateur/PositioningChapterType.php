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

    $current = $b->getData();
    $choices = $q->getChapters()->filter(static function (PositioningChapter $candidate) use ($current): bool {
      $seen = [];
      for ($node = $candidate; $node !== null; $node = $node->getParent()) {
        $key = spl_object_id($node);
        if ($node === $current || isset($seen[$key])) return false;
        $seen[$key] = true;
      }
      return true;
    })->toArray();

    $b
      ->add('title', TextType::class, ['label' => 'Nom du chapitre'])
      ->add('parent', EntityType::class, [
        'class' => PositioningChapter::class,
        'required' => false,
        'label' => 'Ranger dans un chapitre',
        'help' => 'Choisissez un chapitre pour créer un sous-chapitre. Sinon, laissez « Chapitre principal ».',
        'placeholder' => 'Chapitre principal (aucun rattachement)',
        'choice_label' => fn(PositioningChapter $c) => ($c->getParent() ? $c->getParent()->getTitle() . ' › ' : '') . $c->getTitle(),
        'choices' => $choices,
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => PositioningChapter::class]);
    $r->setRequired(['questionnaire']);
  }
}
