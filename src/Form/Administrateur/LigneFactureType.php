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
        $b
            ->add('label', TextareaType::class, [
                'label' => '*Libellé / Détails',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => "Ex: Formation Excel Initial\n- Prendre en main l’interface\n- Tableaux + graphiques\n- …",
                    'rows' => 4,
                ],
                'constraints' => [
                    new Length(max: 2000),
                    // optionnel: new NotBlank()
                ],
            ])



            ->add('qte', IntegerType::class, [
                'label' => 'Qté',
                'empty_data' => '1',
                'attr' => ['class' => 'form-control', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new Positive()],
            ])
            ->add('puHtCents', MoneyType::class, [
                'label' => 'PU HT',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '0,00'],
                'constraints' => [new GreaterThanOrEqual(0)],
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
        $r->setDefaults(['data_class' => LigneFacture::class]);
    }
}
