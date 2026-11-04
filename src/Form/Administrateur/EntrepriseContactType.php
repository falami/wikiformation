<?php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{EmailType, TextType, ChoiceType, CheckboxType};
use Symfony\Component\Validator\Constraints as Assert;

/** Optional until the administrator chooses to create a representative. */
final class EntrepriseContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail du représentant', 'required' => false,
            'label_attr' => ['class' => 'form-label'], 'row_attr' => ['class' => 'mb-3'],
            'attr' => ['class' => 'form-control', 'autocomplete' => 'off', 'placeholder' => 'prenom.nom@entreprise.fr'],
            'constraints' => [new Assert\Email(), new Assert\Length(max: 180)],
        ]);
        $builder->add('reuse', CheckboxType::class, [
            'label' => 'Utiliser ce compte existant comme représentant', 'required' => false,
            'attr' => ['class' => 'form-check-input'], 'label_attr' => ['class' => 'form-check-label'], 'row_attr' => ['class' => 'form-check mb-3'],
        ]);
        $builder->add('civilite', ChoiceType::class, [
            'label' => 'Civilité', 'required' => false, 'placeholder' => 'Non précisée',
            'attr' => ['class' => 'form-select'], 'label_attr' => ['class' => 'form-label'], 'row_attr' => ['class' => 'mb-3'],
            'choices' => ['Madame' => 'Madame', 'Monsieur' => 'Monsieur'],
        ]);
        foreach (['prenom' => ['Prénom', 100], 'nom' => ['Nom', 100], 'telephone' => ['Téléphone', 20]] as $name => [$label, $length]) {
            $builder->add($name, TextType::class, [
                'label' => $label, 'required' => false,
                'attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label'], 'row_attr' => ['class' => 'mb-3'],
                'constraints' => [new Assert\Length(max: $length)],
            ]);
        }
    }
}
