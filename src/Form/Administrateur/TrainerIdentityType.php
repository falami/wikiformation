<?php
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{EmailType, TextType, ChoiceType, DateType, CheckboxType};
use Symfony\Component\Validator\Constraints as Assert;

final class TrainerIdentityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, ['label' => 'Adresse e-mail du formateur', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)]]);
        $builder->add('reuse', CheckboxType::class, ['label' => 'Reprendre cet utilisateur et ses informations', 'required' => false]);
        $builder->add('civilite', ChoiceType::class, ['label' => 'Civilité', 'required' => false, 'choices' => ['Monsieur' => 'Monsieur', 'Madame' => 'Madame']]);
        foreach (['nom' => ['Nom', 100], 'prenom' => ['Prénom', 100], 'telephone' => ['Téléphone', 20], 'adresse' => ['Adresse', 255], 'complement' => ['Complément d’adresse', 255], 'codePostal' => ['Code postal', 255], 'ville' => ['Ville', 255]] as $name => [$label, $length]) {
            $builder->add($name, TextType::class, ['label' => $label, 'required' => false, 'constraints' => [new Assert\Length(max: $length)]]);
        }
        $builder->add('dateNaissance', DateType::class, ['label' => 'Date de naissance', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false]);
    }
}
