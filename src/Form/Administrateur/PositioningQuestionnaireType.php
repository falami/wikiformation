<?php

namespace App\Form\Administrateur;

use App\Entity\PositioningQuestionnaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositioningQuestionnaireType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('title', TextType::class, ['label' => 'Titre'])
      ->add('software', TextType::class, ['label' => 'Logiciel (optionnel)', 'required' => false])
      ->add('isPublished', CheckboxType::class, ['label' => 'Publié', 'required' => false]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => PositioningQuestionnaire::class]);
  }
}
