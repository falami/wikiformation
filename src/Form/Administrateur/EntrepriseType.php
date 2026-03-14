<?php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\Entite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    CheckboxType,
    EmailType,
    FileType,
    TextType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class EntrepriseType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var Entite|null $entite */
        $entite = $o['entite'] ?? null;
        $locked = (bool) ($o['locked'] ?? false);

        $b
            ->add('raisonSociale', TextType::class, [
                'label' => '*Raison sociale',
                'disabled' => $locked,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : WIKI Formation',
                ],
            ])
            ->add('siret', TextType::class, [
                'label' => 'SIRET',
                'required' => false,
                'disabled' => $locked,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '14 chiffres (sans espace)',
                ],
            ])
            ->add('numeroTVA', TextType::class, [
                'label' => 'N° TVA intracommunautaire',
                'required' => false,
                'disabled' => $locked,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : FRXX123456789',
                ],
            ])
            ->add('emailFacturation', EmailType::class, [
                'label' => 'Email facturation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'facturation@entreprise.fr',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (contact)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'contact@entreprise.fr',
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : 12 rue Victor Hugo',
                ],
            ])
            ->add('complement', TextType::class, [
                'label' => 'Complément',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Bâtiment, étage, etc.',
                ],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : 75001',
                ],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Paris',
                ],
            ])
            ->add('departement', TextType::class, [
                'label' => 'Département',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Gard',
                ],
            ])
            ->add('region', TextType::class, [
                'label' => 'Région',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Occitanie',
                ],
            ])
            ->add('pays', TextType::class, [
                'label' => 'Pays',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : France',
                ],
            ])
            ->add('representant', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => fn (Utilisateur $u) => $u->getNom() . ' ' . $u->getPrenom() . ' - ' . $u->getEmail(),
                'label' => 'Représentant',
                'placeholder' => '— Choisir —',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'query_builder' => function () use ($entite) {
                    $qb = $this->em->createQueryBuilder()
                        ->select('u')
                        ->from(Utilisateur::class, 'u')
                        ->innerJoin('u.utilisateurEntites', 'ue');

                    if ($entite) {
                        $qb->andWhere('ue.entite = :entite')
                            ->setParameter('entite', $entite);
                    } else {
                        $qb->andWhere('1 = 0');
                    }

                    return $qb
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'd-none',
                    'accept' => 'image/png,image/jpeg,image/webp,image/svg+xml',
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
                        'mimeTypesMessage' => 'Merci de téléverser une image valide (jpg, png, webp, svg).',
                    ]),
                ],
            ])
            ->add('deleteLogo', CheckboxType::class, [
                'label' => 'Supprimer le logo actuel',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Entreprise::class,
            'entite' => null,
            'locked' => false,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
        $r->setAllowedTypes('locked', ['bool']);
    }
}