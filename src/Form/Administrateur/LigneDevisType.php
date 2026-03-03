<?php

namespace App\Form\Administrateur;

use App\Entity\LigneDevis;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, IntegerType, MoneyType, TextareaType, NumberType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{GreaterThanOrEqual, Length, Positive};

final class LigneDevisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('label', TextareaType::class, [
                'label' => '*Libellé / Détails',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => "Ex: Formation Excel Initial\n- Prendre en main l’interface de travail\n- Créer et présenter un tableau\n- …",
                    'rows' => 4,
                ],
                'constraints' => [new Length(max: 2000)], // tu peux garder 255 si tu préfères
            ])

            ->add('qte', IntegerType::class, [
                'label' => 'Qté',
                'empty_data' => '1',
                'attr' => [
                    'class' => 'form-control text-end',
                    'min' => 1,
                    'step' => 1,
                    'inputmode' => 'numeric'
                ],
                'constraints' => [new Positive()],
            ])

            ->add('puHtCents', MoneyType::class, [
                'label' => 'PU HT',
                'divisor' => 100,        // ✅ l’utilisateur tape en € ; DB stocke en cents
                'currency' => false,     // tu gères le "€" en input-group dans Twig
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
                'constraints' => [new GreaterThanOrEqual(0)],
            ])

            ->add('tva', ChoiceType::class, [
                'label' => 'TVA %',
                'choices' => [
                    '20 %'  => 20.0,
                    '10 %'  => 10.0,
                    '5,5 %' => 5.5,
                    '0 %'   => 0.0,
                ],
                'placeholder' => '-',
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
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => LigneDevis::class,
        ]);
    }
}
