<?php
// src/Form/Public/ReservationType.php
namespace App\Form\Public;

use App\Entity\Reservation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{IntegerType, TextType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use App\Form\DataTransformer\FrenchToDateTransformer;

class ReservationType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
    ) {}
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('places', IntegerType::class, [
                'label' => 'Nombre de places',
                'attr'  => ['class' => 'form-control', 'min' => 1, 'placeholder' => '1'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ])
            // src/Form/Public/ReservationType.php

            ->add('dateSouhaitee', TextType::class, [
                'required' => false,
                'label' => 'Date souhaitée (facultatif)',
                'attr'  => [
                    'class' => 'flatpickr-date form-control',
                    'placeholder' => 'jj/mm/aaaa',
                    'autocomplete' => 'off',
                ],
            ])

            ->add('commentaire', TextareaType::class, [
                'label' => 'Informations complémentaires',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Précisez vos contraintes, le niveau des stagiaires, etc. Dans l\'éventualité ou vous souhaitez réserver plusieurs place, n\'hésitez pas à renseigner le nom, prénom et email des participants pur leur création de compte',
                ],
            ])
        ;
        $builder->get('dateSouhaitee')->addModelTransformer($this->dateFr);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
