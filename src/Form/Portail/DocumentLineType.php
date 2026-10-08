<?php

namespace App\Form\Portail;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{TextType, IntegerType, MoneyType, ChoiceType, NumberType, CheckboxType};
use Symfony\Component\Validator\Constraints as Assert;
final class DocumentLineType extends AbstractType
{
    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['quote' => false]);
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['label' => 'Désignation', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2000)]])->add('quantity', IntegerType::class, ['label' => 'Quantité', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 10000)]])->add('price', MoneyType::class, ['label' => 'Prix unitaire HT', 'divisor' => 100, 'currency' => 'EUR', 'input' => 'integer', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 100000000)]])->add('vat', ChoiceType::class, ['label' => 'TVA', 'choices' => ['0 %' => 0, '5,5 %' => 5.5, '10 %' => 10, '20 %' => 20]]);
        if ($options['quote']) {
            $builder->add('discountPercent', NumberType::class, ['label' => 'Remise (%)', 'required' => false, 'constraints' => [new Assert\Range(min: 0, max: 100)]])->add('discountAmount', MoneyType::class, ['label' => 'Ou remise HT (€)', 'required' => false, 'divisor' => 100, 'input' => 'integer', 'constraints' => [new Assert\Range(min: 0, max: 100000000)]])->add('debours', CheckboxType::class, ['label' => 'Débours', 'required' => false]);
        }
    }
}
