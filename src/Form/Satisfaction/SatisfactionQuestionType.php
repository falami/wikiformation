<?php

namespace App\Form\Satisfaction;

use App\Entity\SatisfactionQuestion;
use App\Enum\SatisfactionQuestionType as QType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Enum\SatisfactionMetricKey;

final class SatisfactionQuestionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('libelle', TextType::class, ['label' => 'Libellé'])

      ->add('type', EnumType::class, [
        'class' => QType::class,
        'choice_label' => fn(QType $c) => $c->label(),
        'choices' => array_values(array_filter(QType::cases(), fn(QType $c) => $c !== QType::STARS)),
        'label' => 'Type de réponse',
        'help' => 'Les options affichées plus bas s’adaptent automatiquement au type choisi.',
      ])

      ->add('required', CheckboxType::class, [
        'label' => 'Obligatoire',
        'required' => false,
      ])

      ->add('help', TextareaType::class, [
        'label' => 'Aide (optionnel)',
        'required' => false,
        'attr' => ['rows' => 2],
      ])

      ->add('placeholder', TextType::class, [
        'label' => 'Placeholder (optionnel)',
        'required' => false,
      ])

      // ✅ Champ “simple” pour l’admin
      ->add('choicesText', TextareaType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Choix (une ligne = une option)',
        'attr' => ['rows' => 4],
      ])

      ->add('metricKey', ChoiceType::class, [
        'label' => 'Clé KPI (optionnel)',
        'required' => false,
        'placeholder' => '- Aucune KPI -',
        'choices' => array_combine(
          array_map(fn(SatisfactionMetricKey $k) => $k->label(), SatisfactionMetricKey::cases()),
          array_map(fn(SatisfactionMetricKey $k) => $k->value, SatisfactionMetricKey::cases())
        ),
        'help' => 'Permet d’alimenter une statistique (formateur, site, contenu, recommandation…).',
        'attr' => ['class' => 'form-select'],
      ])

      ->add('position', HiddenType::class, ['empty_data' => '1'])
    ;

    // Pré-remplir choicesText depuis JSON (choices)
    $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) {
      $q = $e->getData();
      if (!$q instanceof SatisfactionQuestion) return;

      $choices = $q->getChoices() ?? [];
      $lines = [];

      foreach ($choices as $c) {
        if (is_string($c) && trim($c) !== '') $lines[] = $c;
        if (is_array($c) && isset($c['label'])) $lines[] = (string) $c['label'];
      }

      $e->getForm()->get('choicesText')->setData(implode("\n", $lines));
    });

    // Convertir choicesText => JSON array dans choices
    $b->addEventListener(FormEvents::SUBMIT, function (FormEvent $e) {
      $q = $e->getData();
      if (!$q instanceof SatisfactionQuestion) return;

      $txt = (string) ($e->getForm()->get('choicesText')->getData() ?? '');
      $lines = array_values(array_filter(array_map('trim', preg_split("/\R/u", $txt) ?: [])));
      $q->setChoices($lines ?: null);
    });
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => SatisfactionQuestion::class]);
  }
}
