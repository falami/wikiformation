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
            'attr' => ['autocomplete' => 'off', 'placeholder' => 'prenom.nom@entreprise.fr'],
            'constraints' => [new Assert\Email(), new Assert\Length(max: 180)],
        ]);
        $builder->add('reuse', CheckboxType::class, [
            'label' => 'Utiliser ce compte existant comme représentant', 'required' => false,
        ]);
        $builder->add('civilite', ChoiceType::class, [
            'label' => 'Civilité', 'required' => false, 'placeholder' => 'Non précisée',
            'choices' => ['Madame' => 'Madame', 'Monsieur' => 'Monsieur'],
        ]);
        foreach (['prenom' => ['Prénom', 100], 'nom' => ['Nom', 100], 'telephone' => ['Téléphone', 20]] as $name => [$label, $length]) {
            $builder->add($name, TextType::class, [
                'label' => $label, 'required' => false,
                'constraints' => [new Assert\Length(max: $length)],
            ]);
        }
    }
}
