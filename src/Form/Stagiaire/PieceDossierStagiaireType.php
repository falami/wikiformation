<?php

namespace App\Form\Stagiaire;

use App\Entity\PieceDossier;
use App\Enum\PieceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
    CheckboxType,
    ChoiceType,
    FileType
};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

class PieceDossierStagiaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('type', ChoiceType::class, [
                'label' => 'Type de pièce',
                'placeholder' => 'Sélectionner un type de pièce',
                'choices' => [
                    PieceType::CNI_RECTO,
                    PieceType::CNI_VERSO,
                    PieceType::CONVOCATION_SIGNEE,
                    PieceType::REGLEMENT_INTERIEUR_SIGNE,
                    PieceType::OPCO_PEC,
                    PieceType::JUSTIF_DOMICILE,
                    PieceType::CERTIF_MEDICAL,
                ],
                'choice_label' => fn(PieceType $v) => $v->label(),
                'choice_value' => fn(?PieceType $v) => $v?->value,
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-2 mb-md-0',
                ],
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'Ex : CNI, règlement intérieur signé, PEC OPCO…',
            ])

            ->add('filename', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Fichier (PDF / Image)',
                'help' => 'PDF, JPG, PNG, WEBP ou GIF - 10 Mo max.',
                'label_attr' => [
                    'class' => 'form-label fw-semibold',
                ],
                'row_attr' => [
                    'class' => 'mb-2 mb-md-0',
                ],
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'application/pdf,image/*',
                ],
                'constraints' => [
                    new FileConstraint(
                        maxSize: '10M',
                        mimeTypes: [
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/gif',
                        ],
                        mimeTypesMessage: 'Format invalide (PDF ou image requis).',
                    )
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => PieceDossier::class,
        ]);
    }
}
