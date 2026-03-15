<?php
// src/Form/Administrateur/SessionPieceUploadType.php

namespace App\Form\Administrateur;

use App\Entity\SessionPiece;
use App\Enum\SessionPieceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, CheckboxType};
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

final class SessionPieceUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => '*Type de pièce',
                'choices' => SessionPieceType::cases(),
                'choice_value' => fn (?SessionPieceType $t) => $t?->value,
                'choice_label' => fn ($t) => $t instanceof SessionPieceType ? $t->label() : (string) $t,
                'placeholder' => 'Choisir le type…',
                'required' => true,
                'constraints' => [
                    new NotNull(message: 'Veuillez choisir un type de pièce.'),
                ],
                'attr' => ['class' => 'form-select'],
                'row_attr' => ['class' => 'mb-3'],
                'help' => 'Choisis la catégorie correspondant au document.',
            ])

            ->add('commentaireControle', TextareaType::class, [
                'label' => 'Commentaire',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'class' => 'form-control',
                ],
            ])

            ->add('valide', CheckboxType::class, [
                'required' => false,
                'label' => 'Valide',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])

            ->add('file', FileType::class, [
                'label' => '*Fichier',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'application/pdf,image/jpeg,image/png,image/webp',
                ],
                'row_attr' => ['class' => 'mb-3'],
                'help' => 'Formats acceptés : PDF, JPG, PNG, WEBP (15 Mo max).',
                'constraints' => [
                    new File(
                        maxSize: '15M',
                        mimeTypes: [
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ],
                        mimeTypesMessage: 'Formats acceptés : PDF, JPG, PNG, WEBP.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SessionPiece::class,
        ]);
    }
}