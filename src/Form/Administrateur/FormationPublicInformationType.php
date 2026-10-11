<?php

declare(strict_types=1);
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints\Length;

final class FormationPublicInformationType extends AbstractType
{
    public const FIELDS = [
        'accessDelay' => ['Modalités et délais d’accès', 'Étapes d’inscription, positionnement, délai minimum et modalités pédagogiques.'],
        'handicap' => ['Accessibilité et handicap', 'Contact du référent, procédure d’étude des besoins, adaptations et orientation possibles.'],
        'contact' => ['Contact public', 'Coordonnées professionnelles et horaires du contact pour cette formation.'],
        'recognition' => ['Reconnaissance de la formation', 'Attestation ou certification réellement préparée. Si RNCP/RS : intitulé, code, certificateur, validité, habilitation et suites de parcours. Ne pas confondre avec Qualiopi.'],
        'results' => ['Résultats publiés', 'Indicateurs adaptés, période, effectif, nombre de répondants, définition et calcul. Si aucune donnée exploitable, le préciser sans inventer de taux.'],
    ];
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::FIELDS as $key => [$label, $help]) $builder->add($key, TextareaType::class, [
            'label' => $label, 'help' => $help, 'required' => false,
            'constraints' => [new Length(max: 8000)],
            'attr' => ['class' => 'form-control', 'rows' => 4, 'maxlength' => 8000],
        ]);
    }
}
