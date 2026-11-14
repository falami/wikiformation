<?php

namespace App\Form\Administrateur;

use App\Entity\LigneFacture;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{IntegerType, MoneyType, NumberType, TextType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{GreaterThanOrEqual, Length, Positive};
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

class LigneFactureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        if ($opt['draft_mode']) {
            $b->addEventListener(\Symfony\Component\Form\FormEvents::PRE_SET_DATA, static function (\Symfony\Component\Form\FormEvent $event): void {
                if ($event->getData() === null) $event->setData((new LigneFacture())->setQte(1)->setPuHtCents(0));
            });
        }
        $b
            ->add('label', TextareaType::class, [
                'label' => '*Désignation et détails',
                'help' => 'Première ligne : titre de la prestation. Chaque ligne suivante devient un point dans le PDF. Inutile d’ajouter des tirets ; ceux déjà saisis sont pris en charge.',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => "Formation Excel Initial\nPrendre en main l’interface\nCréer et présenter un tableau",
                    'data-document-description' => '',
                    'rows' => 4,
                ],
                'constraints' => [
                    new Length(max: 2000),
                    new \Symfony\Component\Validator\Constraints\NotBlank(message: 'Renseignez la désignation de la prestation.'),
                ],
                'setter' => static function (LigneFacture $line, ?string $label): void { $line->setLabel($label ?? ''); },
            ])



            ->add('qte', IntegerType::class, [
                'label' => 'Qté',
                'empty_data' => '1',
                'attr' => ['class' => 'form-control', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new Positive()],
                'setter' => static function (LigneFacture $line, ?int $quantity): void { if ($quantity !== null) $line->setQte($quantity); },
            ])
            ->add('puHtCents', MoneyType::class, [
                'label' => 'PU HT',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '0,00'],
                'constraints' => [new GreaterThanOrEqual(0), new \Symfony\Component\Validator\Constraints\NotNull(message: 'Renseignez le prix unitaire HT.')],
                'setter' => static function (LigneFacture $line, ?int $amount): void { if ($amount !== null) $line->setPuHtCents($amount); },
            ])
            ->add('tvaBp', ChoiceType::class, [
                'label' => 'TVA %',
                'choices' => [
                    '20 %'  => 2000,
                    '10 %'  => 1000,
                    '5,5 %' => 550,
                    '0 %'   => 0,
                ],
                'placeholder' => 'Sélectionner',
                'required' => true,          // 👈 conseillé
                'empty_data' => '2000',      // 👈 IMPORTANT : ChoiceType renvoie une string
                'attr' => ['class' => 'form-select'],
                'setter' => static function (LigneFacture $line, ?int $rate): void { if ($rate !== null) $line->setTvaBp($rate); },
            ])


            ->add('remisePourcent', NumberType::class, [
                'label' => 'Remise %',
                'required' => false,
                'scale' => 2,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])

            ->add('remiseMontantCents', MoneyType::class, [
                'label' => 'Remise €',
                'required' => false,
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('isDebours', CheckboxType::class, [
                'label' => 'Débours',
                'required' => false,
            ])

        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => LigneFacture::class, 'draft_mode' => false]);
        $r->setAllowedTypes('draft_mode', 'bool');
    }
}
