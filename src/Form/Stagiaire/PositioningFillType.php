<?php

namespace App\Form\Stagiaire;

use App\Entity\PositioningAttempt;
use App\Enum\KnowledgeLevel;
use App\Enum\InterestChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositioningFillType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $items = $o['items'];

    foreach ($items as $item) {
      $id = $item->getId();

      $b->add("k_$id", ChoiceType::class, [
        'mapped' => false,
        'required' => false,
        'expanded' => true,
        'multiple' => false,
        'choices' => [
          '😶' => KnowledgeLevel::NONE,
          '🙂' => KnowledgeLevel::BASIC,
          '😃' => KnowledgeLevel::GOOD,
          '🤩' => KnowledgeLevel::ADVANCED,
        ],
      ]);

      $b->add("i_$id", ChoiceType::class, [
        'mapped' => false,
        'required' => false,
        'expanded' => true,
        'multiple' => false,
        'choices' => [
          'Oui' => InterestChoice::YES,
          'Non' => InterestChoice::NO,
          'Ne sais pas' => InterestChoice::UNKNOWN,
        ],
      ]);
    }

    $b->add('stagiaireComment', TextareaType::class, [
      'required' => false,
      'attr' => ['rows' => 4, 'placeholder' => 'Commentaires, besoins, contexte…'],
    ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => PositioningAttempt::class]);
    $r->setRequired(['items']);
  }
}
