<?php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, EmailType, HiddenType, TelType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class DevisConventionClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('civilite', ChoiceType::class, [
                'label' => 'Civilité', 'required' => false, 'placeholder' => 'Choisir',
                'choices' => ['Madame' => 'Madame', 'Monsieur' => 'Monsieur'],
                'attr' => ['class' => 'js-convention-select'],
            ])
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)], 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)], 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)], 'attr' => ['autocomplete' => 'email']])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false, 'constraints' => [new Assert\Length(max: 20)], 'attr' => ['autocomplete' => 'tel', 'maxlength' => 20]])
            ->add('operation', HiddenType::class, ['constraints' => [new Assert\NotBlank()]]);
    }
}
