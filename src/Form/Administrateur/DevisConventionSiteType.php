<?php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{HiddenType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class DevisConventionSiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du site',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 140)],
                'attr' => ['placeholder' => 'Ex. Centre de formation — Paris', 'maxlength' => 140],
            ])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'required' => false, 'constraints' => [new Assert\Length(max: 255)]])
            ->add('codePostal', TextType::class, ['label' => 'Code postal', 'required' => false, 'constraints' => [new Assert\Length(max: 5)], 'attr' => ['maxlength' => 5]])
            ->add('ville', TextType::class, ['label' => 'Ville', 'required' => false, 'constraints' => [new Assert\Length(max: 140)]])
            ->add('pays', TextType::class, ['label' => 'Pays', 'required' => false, 'constraints' => [new Assert\Length(max: 140)]])
            ->add('operation', HiddenType::class, ['constraints' => [new Assert\NotBlank()]]);
    }
}
