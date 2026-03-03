<?php

namespace App\Form\Administrateur;

use App\Entity\TaxRule;
use App\Enum\TaxBase;
use App\Enum\TaxKind;
use App\Form\DataTransformer\FrenchToDateTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  ChoiceType,
  TextType,
  NumberType,
  MoneyType,
  TextareaType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TaxRuleType extends AbstractType
{
  public function __construct(private FrenchToDateTransformer $dateFr) {}

  public function buildForm(FormBuilderInterface $b, array $opt): void
  {
    $b
      ->add('code', TextType::class, [
        'label' => '*Code',
        'attr' => ['class' => 'form-control', 'placeholder' => 'ex: URSSAF_2026, CFE_2026...'],
      ])
      ->add('label', TextType::class, [
        'label' => '*Libellé',
        'attr' => ['class' => 'form-control', 'placeholder' => 'ex: URSSAF (taux), CFE forfait...'],
      ])
      ->add('kind', ChoiceType::class, [
        'label' => '*Type',
        'choices' => TaxKind::cases(),
        'choice_label' => fn(TaxKind $k) => match ($k) {
          TaxKind::CONTRIBUTION => 'Contribution (URSSAF/CFP/CCI/CMA...)',
          TaxKind::TAX          => 'Taxe',
          TaxKind::SOCIAL       => 'Social',
          TaxKind::VAT          => 'TVA',
        },
        'choice_value' => fn(?TaxKind $k) => $k?->value,
        'attr' => ['class' => 'form-select'],
      ])
      ->add('base', ChoiceType::class, [
        'label' => '*Assiette (base)',
        'choices' => TaxBase::cases(),
        'choice_label' => fn(TaxBase $b) => match ($b) {
          TaxBase::CA_ENCAISSE_TTC => 'CA encaissé TTC',
          TaxBase::CA_ENCAISSE_HT  => 'CA encaissé HT (estimé si besoin)',
          TaxBase::CA_FACTURE_TTC  => 'CA facturé TTC',
          TaxBase::CA_FACTURE_HT   => 'CA facturé HT',
          TaxBase::TVA_COLLECTEE   => 'TVA collectée',
          TaxBase::TVA_DEDUCTIBLE  => 'TVA déductible',
        },
        'choice_value' => fn(?TaxBase $b) => $b?->value,
        'attr' => ['class' => 'form-select'],
      ])

      // rate OU flat
      ->add('rate', NumberType::class, [
        'label' => 'Taux (%)',
        'required' => false,
        'scale' => 4,
        'attr' => ['class' => 'form-control text-end', 'placeholder' => 'ex: 22,50'],
        'help' => 'Si renseigné, calcule = base × taux.',
      ])
      ->add('flatCents', MoneyType::class, [
        'label' => 'Forfait (€)',
        'required' => false,
        'divisor' => 100,
        'currency' => false,
        'attr' => ['class' => 'form-control text-end', 'placeholder' => 'ex: 150,00'],
        'help' => 'Si renseigné, le forfait écrase le taux.',
      ])

      ->add('validFrom', TextType::class, [
        'label' => '*Valide à partir du',
        'attr' => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
      ])
      ->add('validTo', TextType::class, [
        'label' => 'Valide jusqu’au (optionnel)',
        'required' => false,
        'attr' => [
          'class' => 'form-control flatpickr-date',
          'placeholder' => 'jj/mm/aaaa',
          'readonly' => true, // ✅ une seule fois
        ],
      ])

      // ✅ JSON en textarea UNMAPPED (sinon string -> ?array = crash)
      ->add('conditionsJson', TextareaType::class, [
        'label' => 'Conditions (JSON, optionnel)',
        'mapped' => false,
        'required' => false,
        'attr' => ['class' => 'form-control', 'rows' => 4],
        'help' => 'Optionnel : JSON libre (minBaseCents, maxBaseCents, currency...).',
      ])
      ->add('metaJson', TextareaType::class, [
        'label' => 'Meta (JSON, optionnel)',
        'mapped' => false,
        'required' => false,
        'attr' => ['class' => 'form-control', 'rows' => 4],
      ])
    ;

    // dates FR
    $b->get('validFrom')->addModelTransformer($this->dateFr);
    $b->get('validTo')->addModelTransformer($this->dateFr);

    // ✅ Remplit les textareas depuis les arrays
    $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) {
      $r = $e->getData();
      $f = $e->getForm();
      if (!$r) return;

      $f->get('conditionsJson')->setData(
        $r->getConditions() ? json_encode($r->getConditions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''
      );
      $f->get('metaJson')->setData(
        $r->getMeta() ? json_encode($r->getMeta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''
      );
    });

    // ✅ Decode JSON -> array sur submit (AVANT flush)
    $b->addEventListener(FormEvents::SUBMIT, function (FormEvent $e) {
      /** @var TaxRule $r */
      $r = $e->getData();
      $f = $e->getForm();

      foreach (['conditionsJson' => 'setConditions', 'metaJson' => 'setMeta'] as $field => $setter) {
        $raw = trim((string) $f->get($field)->getData());

        if ($raw === '') {
          $r->$setter(null);
          continue;
        }

        $decoded = json_decode($raw, true);
        $r->$setter(is_array($decoded) ? $decoded : null);
      }

      if (($r->getFlatCents() ?? 0) > 0) {
        $r->setRate(0.0);
      }
    });
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => TaxRule::class,
    ]);
  }
}
