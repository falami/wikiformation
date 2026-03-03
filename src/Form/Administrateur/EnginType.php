<?php

namespace App\Form\Administrateur;

use App\Entity\Engin;
use App\Entity\Site;
use App\Enum\EnginType as EnginTypeEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    FileType,
    IntegerType,
    NumberType,
    TextType,
    TextareaType,
    EnumType,
    CheckboxType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{
    Image,
    All,
    NotBlank,
    Length,
    Positive,
    PositiveOrZero
};

class EnginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            // ---------- Base / Identité ----------
            ->add('site', EntityType::class, [
                'class' => Site::class,
                'choice_label' => fn(Site $s) => $s->getNom() . ' - ' . $s->getVille(),
                'label' => '*Base / Site',
                'placeholder' => 'Sélectionnez un site',
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank(message: 'Le site est requis.')],
            ])
            ->add('nom', TextType::class, [
                'label' => '*Nom du engin',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Lagoon 42 - Calypso',
                    'maxlength' => 140,
                ],
                'constraints' => [
                    new NotBlank(message: 'Le nom est requis.'),
                    new Length(max: 140, maxMessage: '140 caractères maximum.'),
                ],
            ])
            ->add('type', EnumType::class, [
                'class' => EnginTypeEnum::class,
                'label' => '*Type',
                'placeholder' => false,
                'required' => true,
                'choice_label' => static function (EnginTypeEnum $e): string {
                    return match ($e) {
                        EnginTypeEnum::CHARGEUR    => 'CHargeur',
                        EnginTypeEnum::BOUTEUR     => 'Bouteur',
                        EnginTypeEnum::COMPACTEUR  => 'Compacteur',
                        EnginTypeEnum::NACELLE     => 'Nacelle',
                        EnginTypeEnum::MOTOBASCULEUR   => 'Motobasculeur',
                        EnginTypeEnum::PELLE_HYDRAULIQUE     => 'Pelle-Hydraulique',
                        EnginTypeEnum::CATAMARAN   => 'Catamaran',
                        EnginTypeEnum::MONOCOQUE   => 'Monocoque',
                        EnginTypeEnum::VOILIER     => 'Voilier',
                        default                     => $e->name,
                    };
                },
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ])

            // ---------- Capacités "legacy" ----------
            ->add('capaciteCouchage', IntegerType::class, [
                'label' => 'Couchages (nuit)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('capacite', IntegerType::class, [
                'label' => 'Capacité (journée)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('cabine', IntegerType::class, [
                'label' => 'Cabines',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('douche', IntegerType::class, [
                'label' => 'Douches',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('chambre', IntegerType::class, [
                'label' => 'Chambres',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])

            // ---------- NOUVEAUX CHAMPS : Infos générales ----------
            ->add('annee', IntegerType::class, [
                'label' => 'Année',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1900, 'max' => 2100, 'step' => 1, 'placeholder' => '2025'],
                'empty_data' => '',
                'constraints' => [new Positive(message: 'Doit être > 0.')],
            ])
            ->add('personnes', IntegerType::class, [
                'label' => 'Personnes (max)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => '10'],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('cabinesDoubles', IntegerType::class, [
                'label' => 'Cabines doubles',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => '5'],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('couchagesCarres', IntegerType::class, [
                'label' => 'Couchages carrés',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => '0'],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('couchagesRecommandes', IntegerType::class, [
                'label' => 'Couchages recommandés',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => '12'],
                'empty_data' => '',
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])

            // ---------- NOUVEAUX CHAMPS : Dimensions ----------
            ->add('longueurHt', NumberType::class, [
                'label' => 'Longueur HT (m)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'placeholder' => '17.65'],
                'empty_data' => '',
                'scale' => 2,
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('largeurMax', NumberType::class, [
                'label' => 'Largeur max (m)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'placeholder' => '9.06'],
                'empty_data' => '',
                'scale' => 2,
                'constraints' => [new PositiveOrZero(message: 'Doit être ≥ 0.')],
            ])
            ->add('tirantEau', TextType::class, [
                'label' => 'Tirant d’eau',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '1.47 m'],
                'constraints' => [new Length(max: 50)],
            ])

            // ---------- NOUVEAUX CHAMPS : Capacités ----------
            ->add('reservoirFuel', TextType::class, [
                'label' => 'Réservoir fuel',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '1200 L'],
                'constraints' => [new Length(max: 50)],
            ])
            ->add('reservoirEau', TextType::class, [
                'label' => 'Réservoir eau',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '1260 L'],
                'constraints' => [new Length(max: 50)],
            ])

            // ---------- NOUVEAUX CHAMPS : Équipements ----------
            ->add('dessalinisateur', CheckboxType::class, [
                'label' => 'Dessalinisateur',
                'required' => false,
            ])
            ->add('panneauxSolaires', CheckboxType::class, [
                'label' => 'Panneaux solaires',
                'required' => false,
            ])
            ->add('refrigerateur', CheckboxType::class, [
                'label' => 'Réfrigérateur',
                'required' => false,
            ])
            ->add('prise', CheckboxType::class, [
                'label' => 'Prise',
                'required' => false,
            ])
            ->add('propulseurEtrave', CheckboxType::class, [
                'label' => 'Propulseur d’étrave',
                'required' => false,
            ])
            ->add('gps', CheckboxType::class, [
                'label' => 'GPS',
                'required' => false,
            ])

            // ---------- Médias ----------
            ->add('photoBanniere', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de bannière (affichée à gauche)',
                'constraints' => [new Image(maxSize: '16M', mimeTypesMessage: 'Image invalide')],
                'attr' => ['accept' => 'image/*']
            ])
            ->add('photoCouverture', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de couverture (affichée en plein écran)',
                'constraints' => [new Image(maxSize: '16M', mimeTypesMessage: 'Image invalide')],
                'attr' => ['accept' => 'image/*']
            ])
            ->add('galleryFiles', FileType::class, [
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'label'  => 'Galerie (plusieurs images)',
                'constraints' => [new All([new Image(maxSize: '30M')])],
                'attr' => ['accept' => 'image/*', 'multiple' => 'multiple']
            ])

            // ---------- Texte libre ----------
            ->add('caracteristique', TextareaType::class, [
                'label' => 'Caractéristiques / équipements (texte libre)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Annexe, GV lattée, panneaux solaires…',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Engin::class,
        ]);
    }
}
