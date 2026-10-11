<?php

declare(strict_types=1);
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{TextType, EmailType};
use Symfony\Component\Validator\Constraints as Assert;

final class QualityIdentityType extends AbstractType
{
    public const FIELDS = [
        'nom' => ['Nom de l’organisme', 255], 'formeJuridique' => ['Forme juridique', 100],
        'siret' => ['SIRET', 30], 'numeroDeclarant' => ['Numéro de déclaration d’activité', 14],
        'adresse' => ['Adresse', 255], 'complement' => ['Complément d’adresse', 255],
        'codePostal' => ['Code postal', 255], 'ville' => ['Ville', 255], 'region' => ['Région', 255],
        'pays' => ['Pays', 255], 'email' => ['E-mail de contact', 255],
        'prenomRepresentant' => ['Prénom du représentant', 100], 'nomRepresentant' => ['Nom du représentant', 100],
        'fonction' => ['Fonction du représentant', 100],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::FIELDS as $key => [$label, $length]) {
            $constraints = [new Assert\Length(max: $length)];
            if ($key === 'nom') $constraints[] = new Assert\NotBlank();
            if ($key === 'email') $constraints[] = new Assert\Email();
            if (in_array($key, ['siret', 'numeroDeclarant'], true)) $constraints[] = new Assert\Regex(pattern: $key === 'siret' ? '/^\d{14}$/' : '/^\d{11}$/', message: $key === 'siret' ? 'Indiquez les 14 chiffres du SIRET.' : 'Indiquez les 11 chiffres du numéro de déclaration d’activité.');
            $builder->add($key, $key === 'email' ? EmailType::class : TextType::class, ['label' => $label, 'required' => $key === 'nom', 'constraints' => $constraints, 'attr' => ['maxlength' => $length],
                'help' => match ($key) {
                    'numeroDeclarant' => 'Recopiez votre récépissé. Laissez vide si le numéro n’a pas encore été attribué. Son format ne prouve ni son enregistrement ni un agrément de l’État.',
                    'region' => 'Utilisée dans les documents de l’organisme. Vérifiez la concordance avec votre récépissé de déclaration, notamment après un déménagement.',
                    default => null,
                }]);
            if (in_array($key, ['siret', 'numeroDeclarant'], true)) $builder->get($key)->addModelTransformer(new CallbackTransformer(static fn ($value) => $value, static fn ($value) => $value === null ? null : preg_replace('/[\s\p{Z}]+/u', '', $value)));
        }
    }
}
