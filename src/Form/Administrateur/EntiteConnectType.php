<?php

namespace App\Form\Administrateur\Billing;

use App\Entity\Billing\EntiteConnect;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, IntegerType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EntiteConnectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $options): void
    {
        $b
            ->add('onlinePaymentEnabled', CheckboxType::class, [
                'required' => false,
                'label' => 'Activer le paiement en ligne (Stripe Checkout)',
            ])
            ->add('feePercentBp', IntegerType::class, [
                'required' => false,
                'label' => 'Frais de service (%) en basis points (ex: 250 = 2.50%)',
                'attr' => ['min' => 0],
            ])
            ->add('feeFixedCents', IntegerType::class, [
                'required' => false,
                'label' => 'Frais fixes (cents) (ex: 30 = 0.30€)',
                'attr' => ['min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EntiteConnect::class]);
    }
}