<?php

namespace App\Form\Administrateur;

use App\Entity\DossierInscription;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
    CheckboxType,
    CollectionType,
    TextType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class DossierInscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('employeur', TextType::class, [
                'required' => false,
                'label' => 'Employeur',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-3',
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Raison sociale / Nom de l’employeur',
                    'autocomplete' => 'organization',
                ],
                'constraints' => [new Length(max: 180)],
                'help' => 'L’employeur qui finance ou emploie le stagiaire.',
            ])

            ->add('opco', TextType::class, [
                'required' => false,
                'label' => 'OPCO',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-3',
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Nom de l’OPCO',
                    'autocomplete' => 'off',
                ],
                'constraints' => [new Length(max: 120)],
                'help' => 'Nom de l’OPCO qui prend en charge la formation le cas échéant.',
            ])

            ->add('numDossierOpco', TextType::class, [
                'required' => false,
                'label' => 'N° dossier OPCO',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-3',
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Numéro de prise en charge',
                    'autocomplete' => 'off',
                ],
                'constraints' => [new Length(max: 120)],
                'help' => 'Référence du dossier de prise en charge OPCO (facultatif).',
            ])

            ->add('amenagementHandicap', CheckboxType::class, [
                'required' => false,
                'label' => 'Aménagement lié à un handicap',
                'label_attr' => [
                    'class' => 'form-check-label',
                ],
                'row_attr' => [
                    'class' => 'form-check form-switch mb-3',
                ],
                'attr' => [
                    'class' => 'form-check-input',
                ],
                'help' => 'Cochez si des aménagements spécifiques sont nécessaires pour ce stagiaire.',
            ])

            ->add('detailsAmenagement', TextType::class, [
                'required' => false,
                'label' => 'Détails des aménagements',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-3',
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Préciser les aménagements nécessaires',
                ],
                'constraints' => [new Length(max: 255)],
                'help' => 'Exemples : accessibilité, matériel adapté, temps supplémentaire, etc.',
            ])

            ->add('pieces', CollectionType::class, [
                'entry_type' => PieceDossierType::class,
                'entry_options' => [
                    'label' => false,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => 'Pièces du dossier',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-3',
                ],
                'attr' => [
                    'data-collection' => 'pieces',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => DossierInscription::class,
        ]);
    }
}
