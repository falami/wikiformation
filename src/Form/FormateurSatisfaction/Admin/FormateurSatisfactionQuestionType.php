<?php

namespace App\Form\FormateurSatisfaction\Admin;

use App\Entity\FormateurSatisfactionQuestion;
use App\Enum\SatisfactionQuestionType;
use App\Enum\FormateurSatisfactionMetricKey;
use App\Enum\SatisfactionQuestionType as QType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class FormateurSatisfactionQuestionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('libelle', TextType::class, [
        'required' => true,
        'empty_data' => '',
        'label' => 'Libellé'
      ])
      ->add('type', ChoiceType::class, [
        'label' => 'Type',
        'required' => true,
        'empty_data' => SatisfactionQuestionType::SCALE->value,
        'choices' => array_combine(
          array_map(fn(QType $t) => $t->label(), QType::cases()),
          QType::cases()
        ),
        'choice_value' => fn(?QType $t) => $t?->value,
      ])

      ->add('required', CheckboxType::class, [
        'label' => 'Obligatoire',
        'required' => false,
      ])

      // ✅ Champ “simple” pour l’admin
      ->add('choicesText', TextareaType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Choix (une ligne = une option)',
        'attr' => ['rows' => 4],
      ])
      ->add('position', HiddenType::class, ['empty_data' => '1'])
      ->add('help', TextareaType::class, [
        'label' => 'Aide',
        'required' => false,
        'attr' => ['rows' => 2],
      ])
      ->add('placeholder', TextType::class, [
        'label' => 'Placeholder',
        'required' => false,
      ])
      ->add('minValue', IntegerType::class, [
        'label' => 'Min',
        'required' => false,
      ])
      ->add('maxValeur', IntegerType::class, [
        'label' => 'Max',
        'required' => false,
      ])
      ->add('metricKey', ChoiceType::class, [
        'label' => 'KPI (metricKey)',
        'required' => false,
        'placeholder' => '-',
        'choices' => array_combine(
          array_map(fn(FormateurSatisfactionMetricKey $k) => $k->label(), FormateurSatisfactionMetricKey::cases()),
          array_map(fn(FormateurSatisfactionMetricKey $k) => $k->value, FormateurSatisfactionMetricKey::cases()),
        ),
      ])
      ->add('metricMax', IntegerType::class, [
        'label' => 'KPI Max',
        'required' => false,
      ])
      ->add('choices', TextareaType::class, [
        'label' => 'Choix (1 par ligne)',
        'required' => false,
        'attr' => ['rows' => 3],
        'help' => 'Uniquement pour Choice / MultiChoice.',
      ]);

    // Pré-remplir choicesText depuis JSON (choices)
    $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) {
      $q = $e->getData();
      if (!$q instanceof FormateurSatisfactionQuestion) return;

      $choices = $q->getChoices() ?? [];
      $lines = [];

      foreach ($choices as $c) {
        if (is_string($c) && trim($c) !== '') $lines[] = $c;
        if (is_array($c) && isset($c['label'])) $lines[] = (string) $c['label'];
      }

      $e->getForm()->get('choicesText')->setData(implode("\n", $lines));
    });

    // Convertir choicesText => JSON array dans choices
    $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $e) {
      $q = $e->getData();
      if (!$q instanceof FormateurSatisfactionQuestion) return;

      $txt = (string) ($e->getForm()->get('choicesText')->getData() ?? '');
      $lines = array_values(array_filter(array_map('trim', preg_split("/\R/u", $txt) ?: [])));
      $q->setChoices($lines ?: null);
    });
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => FormateurSatisfactionQuestion::class,
    ]);
  }
}
