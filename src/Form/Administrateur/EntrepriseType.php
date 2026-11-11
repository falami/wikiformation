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
    ChoiceType,
    EmailType,
    FileType,
    TextType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\{FormEvent, FormEvents};

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
                'label' => 'Raison sociale',
                'constraints' => [new Assert\NotBlank(message: 'Renseignez le nom de l’entreprise.'), new Assert\Length(max: 255)],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : WIKI Formation',
                ],
            ])
            ->add('siret', TextType::class, [
                'label' => 'SIRET',
                'help' => '14 chiffres. Les espaces sont acceptés ; la clé de contrôle est vérifiée.',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '14 chiffres (sans espace)',
                ],
            ])
            ->add('numeroTVA', TextType::class, [
                'label' => 'N° TVA intracommunautaire',
                'help' => 'Un identifiant différent du SIRET : préfixe pays, clé et identifiant fiscal. Laissez vide si non applicable.',
                'required' => false,
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
                    new File(
                        maxSize: '4M',
                        mimeTypes: [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/svg+xml',
                        ],
                        mimeTypesMessage: 'Merci de téléverser une image valide (jpg, png, webp, svg).',
                    ),
                ],
            ])
            ->add('deleteLogo', CheckboxType::class, [
                'label' => 'Supprimer le logo actuel',
                'mapped' => false,
                'required' => false,
            ]);
        if ($o['client_workflow']) {
            /** @var Entreprise $company */
            $company = $b->getData();
            $b->add('contactMode', ChoiceType::class, [
                'mapped' => false, 'expanded' => true, 'label' => false,
                'choices' => ['Plus tard' => 'later', 'Choisir un utilisateur' => 'existing', 'Créer un contact' => 'new'],
                'data' => $company?->getRepresentant() ? 'existing' : 'later',
                'constraints' => [new Assert\NotBlank(message: 'Choisissez comment ajouter votre représentant.')],
            ]);
            $b->add('newContact', EntrepriseContactType::class, ['mapped' => false, 'label' => false]);
            $linked = $company?->getId() ? $this->em->createQueryBuilder()
                ->select('DISTINCT u')->from(Utilisateur::class, 'u')
                ->innerJoin('u.utilisateurEntites', 'ue')
                ->leftJoin('u.entreprisesAssociees', 'company')
                ->where('ue.entite = :tenant')->andWhere('company = :company OR u.entreprise = :company')
                ->setParameter('tenant', $entite)->setParameter('company', $company)
                ->getQuery()->getResult() : [];
            $b->add('associatedUsers', EntityType::class, [
                'class' => Utilisateur::class, 'mapped' => false, 'multiple' => true, 'required' => false,
                'label' => 'Utilisateurs rattachés', 'data' => $linked,
                'choice_label' => fn(Utilisateur $u) => $u->getPrenom().' '.$u->getNom().' — '.$u->getEmail(),
                'attr' => ['class' => 'form-select', 'data-company-users' => ''],
                'query_builder' => fn() => $this->em->createQueryBuilder()
                    ->select('DISTINCT u')->from(Utilisateur::class, 'u')
                    ->innerJoin('u.utilisateurEntites', 'ue')->where('ue.entite = :tenant')
                    ->setParameter('tenant', $entite)->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC'),
            ]);
            // Ignore inactive panels on the server too, including stale/forged hidden inputs.
            $b->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) return;
                if (($data['contactMode'] ?? '') !== 'new') $data['newContact'] = [];
                if (($data['contactMode'] ?? '') !== 'existing') $data['representant'] = null;
                $event->setData($data);
            });
        }

    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'client_workflow' => false,
            'data_class' => Entreprise::class,
            'entite' => null,
            'locked' => false,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
        $r->setAllowedTypes('locked', ['bool']);
    }
}