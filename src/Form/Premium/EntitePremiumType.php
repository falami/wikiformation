<?php

namespace App\Form\Premium;

use App\Entity\Entite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

class EntitePremiumType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => '*Nom du club',
                'attr' => ['class' => 'form-control'],
                'required'   => true,
            ])
            ->add('couleurPrincipal', ColorType::class, [
                'label' => 'Couleur principale',
                'attr' => ['class' => 'form-select'],
                'required'   => false,
            ])
            ->add('couleurSecondaire', ColorType::class, [
                'label' => 'Couleur secondaire',
                'attr' => ['class' => 'form-select'],
                'required'   => false,
            ])
            ->add('couleurTertiaire', ColorType::class, [
                'label' => 'Couleur tertiaire',
                'attr' => ['class' => 'form-select'],
                'required'   => false,
            ])
            ->add('couleurQuaternaire', ColorType::class, [
                'label' => 'Couleur quaternaire',
                'attr' => ['class' => 'form-select'],
                'required'   => false,
            ])
            ->add('logoMenu', FileType::class, [
                'label' => 'Logo du menu (jpg ou png), le logo sera automatiquement transformé en 100x40',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new File([
                        'maxSize' => '8192k',
                        'mimeTypes' => [
                            'image/jpg',
                            'image/png',
                            'image/jpeg',
                        ],
                        'mimeTypesMessage' => 'Veuillez télécharger un fichier valide (jpg ou png)',
                    ])
                ],
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo du club (jpg ou png)',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new File([
                        'maxSize' => '2048k',
                        'mimeTypes' => [
                            'image/jpg',
                            'image/png',
                            'image/jpeg',
                        ],
                        'mimeTypesMessage' => 'Veuillez télécharger un fichier valide (jpg ou png)',
                    ])
                ],
            ])
            ->add('email', TextType::class, [
                'label' => '*Email du club',
                'attr' => ['class' => 'form-control'],
                'required'   => true,
                'constraints' => [
                    new Length([
                        'min' => 2,
                        'max' => 255
                    ])
                ]
            ])
            ->add('telephone', TextType::class, [
                'label' => 'N° de Téléphone',
                'required' => false,      // facultatif
                'trim' => true,
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'tel',
                    'autocomplete' => 'tel',
                    'placeholder' => '+33612345678',
                    // HTML5 côté client : E.164 (optionnel, c'est un garde-fou UX)
                    'pattern' => '^\+?[1-9]\d{6,14}$',
                    'title' => 'Format international attendu (ex : +33612345678)',
                ],
                'constraints' => [
                    // Longueur DB/format
                    new Assert\Length(max: 20, maxMessage: 'Numéro trop long.'),
                    // Si non vide, doit ressembler à de l’E.164 (le transformer fera la normalisation)
                    new Assert\Regex(
                        pattern: '/^\+?[1-9]\d{6,14}$/',
                        match: true,
                        message: 'Numéro invalide. Utilisez un format international (ex : +33612345678).',
                        // IMPORTANT : ce Regex ne doit PAS s’appliquer si le champ est vide
                    ),
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])
            ->add('complement', TextType::class, [
                'label' => 'Complément',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code Postal',
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new Assert\Length([
                        'max' => 20,
                        'maxMessage' => 'Code postal trop long.'
                    ]),
                    new Assert\Regex([
                        // lettres/chiffres + espaces + tirets (international)
                        'pattern' => '/^[0-9A-Za-z][0-9A-Za-z \-]{1,18}[0-9A-Za-z]$/',
                        'message' => 'Code postal invalide.',
                        'match' => true
                    ]),
                ],
            ])

            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])

            ->add('departement', TextType::class, [
                'label' => 'Département',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])
            ->add('region', TextType::class, [
                'label' => 'Région',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])
            ->add('pays', TextType::class, [
                'label' => 'Pays',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])
            ->add('description', TextareaType::class, [
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Écrivez votre texte d\'approche ici...',
                ],
                'required' => false,
                'label' => 'Description : ',
            ])
            ->add('texteAccueil', TextareaType::class, [
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Écrivez votre texte de bienvenue ici...',
                ],
                'required' => false,
                'label' => 'Texte d\'accueil : ',
            ])

            ->add('removeLogo', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])
            ->add('removeLogoMenu', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entite::class,
        ]);
    }
}
