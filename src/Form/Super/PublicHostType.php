<?php

declare(strict_types=1);

namespace App\Form\Super;

use App\Entity\Formation;
use App\Entity\PublicHost;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use App\Entity\Entite;

final class PublicHostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, [
                'label' => 'Host',
                'required' => true,
                'trim' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'formations.itforyou.fr',
                    'autocomplete' => 'off',
                    'spellcheck' => 'false',
                ],
                'help' => 'Exemple : formations.itforyou.fr',
            ])

            ->add('name', TextType::class, [
                'label' => 'Nom affiché',
                'required' => true,
                'trim' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'IT FOR YOU',
                ],
            ])

            ->add('logoFile', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => '.jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml',
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new File([
                        'maxSize' => '4M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/svg+xml',
                        ],
                        'mimeTypesMessage' => 'Veuillez sélectionner une image valide (JPG, PNG, WebP ou SVG).',
                    ]),
                ],
                'help' => 'Formats acceptés : JPG, PNG, WebP, SVG.',
            ])

            ->add('removeLogo', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])

            ->add('primaryColor', ColorType::class, [
                'label' => 'Couleur principale',
                'required' => false,
                'attr' => [
                    'class' => 'form-control form-control-color',
                ],
            ])

            ->add('secondaryColor', ColorType::class, [
                'label' => 'Couleur secondaire',
                'required' => false,
                'attr' => [
                    'class' => 'form-control form-control-color',
                ],
            ])

            ->add('tertiaryColor', ColorType::class, [
                'label' => 'Couleur tertiaire',
                'required' => false,
                'attr' => [
                    'class' => 'form-control form-control-color',
                ],
            ])

            ->add('quaternaryColor', ColorType::class, [
                'label' => 'Couleur quaternaire',
                'required' => false,
                'attr' => [
                    'class' => 'form-control form-control-color',
                ],
            ])

            ->add('isActive', CheckboxType::class, [
                'label' => 'Host actif',
                'required' => false,
            ])

            ->add('catalogueEnabled', CheckboxType::class, [
                'label' => 'Catalogue activé',
                'required' => false,
            ])

            ->add('calendarEnabled', CheckboxType::class, [
                'label' => 'Calendrier activé',
                'required' => false,
            ])

            ->add('elearningEnabled', CheckboxType::class, [
                'label' => 'E-learning activé',
                'required' => false,
            ])

            ->add('shopEnabled', CheckboxType::class, [
                'label' => 'Vente activée',
                'required' => false,
            ])

            ->add('restrictToAssignedFormations', CheckboxType::class, [
                'label' => 'Limiter aux formations sélectionnées',
                'required' => false,
                'help' => 'Si décoché, toutes les formations publiques seront visibles sur ce host.',
            ])

            ->add('formations', EntityType::class, [
                'class' => Formation::class,
                'label' => 'Formations autorisées',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choice_label' => static fn (Formation $formation): string => sprintf(
                    '#%d - %s%s',
                    $formation->getId(),
                    (string) $formation->getTitre(),
                    $formation->getEntite() ? ' - ' . $formation->getEntite()->getNom() : ''
                ),
                'attr' => [
                    'class' => 'form-select js-ts',
                    'data-ts-placeholder' => 'Sélectionner les formations autorisées',
                    'data-conditional-target' => 'restrict-formations',
                ],
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('f')
                    ->leftJoin('f.entite', 'e')
                    ->addSelect('e')
                    ->andWhere('f.isPublic = 1')
                    ->orderBy('e.nom', 'ASC')
                    ->addOrderBy('f.titre', 'ASC'),
                'help' => 'Utilisé uniquement si "Limiter aux formations sélectionnées" est activé.',
            ])
            ->add('entite', EntityType::class, [
                'class' => Entite::class,
                'label' => 'Entité rattachée',
                'required' => false,
                'placeholder' => 'Sélectionner une entité',
                'choice_label' => static fn (Entite $entite): string => sprintf(
                    '#%d - %s',
                    $entite->getId(),
                    $entite->getNom()
                ),
                'attr' => [
                    'class' => 'form-select js-ts',
                    'data-ts-placeholder' => 'Sélectionner une entité',
                ],
                'query_builder' => static fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('e')
                    ->orderBy('e.nom', 'ASC'),
                'help' => 'Permet de rattacher ce host public à une entité précise.',
            ])
            ->add('showAllPublicFormations', CheckboxType::class, [
                'label' => 'Afficher toutes les formations publiques',
                'required' => false,
                'help' => 'À activer pour le host global Wikiformation. Si coché, ce host affichera toutes les formations publiques de toutes les entités, sauf celles exclues manuellement du catalogue global.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublicHost::class,
        ]);
    }
}