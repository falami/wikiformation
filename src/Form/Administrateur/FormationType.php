<?php

namespace App\Form\Administrateur;

use App\Entity\Formation;
use App\Entity\Formateur;
use App\Entity\Engin;
use App\Entity\Categorie;
use App\Enum\NiveauFormation;
use Doctrine\ORM\EntityRepository;
use App\Entity\SatisfactionTemplate;
use App\Entity\FormateurSatisfactionTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Form\DataTransformer\EurosToCentsTransformer;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\{FileType, TextType, TextareaType, IntegerType, EnumType, CheckboxType};
use Symfony\Component\Validator\Constraints\{Image, All, Length};
use App\Entity\Entite;
use App\Entity\PublicHost;

class FormationType extends AbstractType
{
    public function __construct(private EurosToCentsTransformer $eurosToCents) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        // Permet de filtrer par entité si fournie dans l'option du formulaire
        /** @var Entite|null $entite */
        $entite = $o['entite'] ?? null;
        $b
            ->add('titre', TextType::class, [
                'label' => '*Titre',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Excel initial, Caces R489'],
            ])
            ->add('slug', TextType::class, [
                'label' => '*Slug',
                'required' => false, // ✅ laisse le serveur générer si vide
                'attr' => ['class' => 'form-control', 'placeholder' => 'excel-initial'],
            ])

            ->add('niveau', EnumType::class, [
                'class' => NiveauFormation::class,
                'label' => 'Niveau',
                'attr'  => ['class' => 'form-select'],
                'choice_label' => fn($e) => match ($e) {
                    NiveauFormation::INITIAL            => 'Initial',
                    NiveauFormation::INTERMEDIAIRE      => 'Intermédiaire',
                    NiveauFormation::AVANCEE            => 'Avancée',
                    NiveauFormation::PERFECTIONNEMENT   => 'Perfectionnement',
                    default => $e->name,
                },
            ])
            ->add('duree', IntegerType::class, [
                'label' => 'Durée (jours)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1],
            ])

            // --- NOUVEAU : ENGIN (optionnel)
            ->add('engin', EntityType::class, [
                'class' => Engin::class,
                'label' => 'Engin',
                'required' => false,
                'placeholder' => '- Sélectionner un engin -',
                'choice_label' => fn(Engin $e) => trim(($e->getNom() ?? '') . ($e->getSite() ? ' - ' . $e->getSite()->getNom() : '')),
                'attr' => ['class' => 'form-select js-ts', 'data-ts-placeholder' => '- Sélectionner un engin -'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('e')
                        ->andWhere('e.entite = :e')->setParameter('e', $entite)
                        ->orderBy('e.nom', 'ASC');
                },
            ])

            // --- NOUVEAU : FORMATEUR (optionnel)
            ->add('formateur', EntityType::class, [
                'class' => Formateur::class,
                'label' => 'Formateur attitré par défaut',
                'required' => false,
                'placeholder' => '- Sélectionner un formateur -',
                'choice_label' => fn(Formateur $f) => trim(
                    $f->getUtilisateur()->getPrenom() . ' ' . $f->getUtilisateur()->getNom()
                        . ($f->getUtilisateur()->getEmail() ? ' - ' . $f->getUtilisateur()->getEmail() : '')
                ),
                'attr' => ['class' => 'form-select js-ts', 'data-ts-placeholder' => '- Sélectionner un formateur -'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('f')
                        ->leftJoin('f.utilisateur', 'u')->addSelect('u')
                        ->andWhere('f.entite = :e')->setParameter('e', $entite)
                        ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
                },
            ])

            // >>> prixBaseCents (int) saisi en euros
            ->add('prixBaseCents', TextType::class, [
                'label' => 'Prix de base (€)',
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'decimal',
                    'placeholder' => '1 299,90',
                ],
                'help' => 'Saisissez en euros (2 décimales).',
                'required' => true,
            ])


            ->add('modalitesPratiques', TextareaType::class, [
                'label' => 'Modalités Pratiques',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])

            ->add('modalitesEvaluation', TextareaType::class, [
                'label' => 'Modalités Evaluation',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])
            ->add('note', TextType::class, [
                'label' => 'Note',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: Nom de la société',
                ],
                'help' => 'Permet d\'avoir une petite description au niveau des formations.',
                'required' => false,
            ])
            ->add('prixReduitCents', TextType::class, [
                'label' => 'Prix réduit (€)',
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'decimal',
                    'placeholder' => '1 299,90',
                ],
                'help' => 'Saisissez en euros (2 décimales).',
                'required' => true,
            ])
            ->add('codeQualiopi', TextType::class, [
                'label' => 'Code Qualiopi',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('conditionPrealable', TextareaType::class, [
                'label' => 'Pré-requis',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'Objectifs',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])
            ->add('pedagogie', TextareaType::class, [
                'label' => 'Pédagogie',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ])
            ->add('photoBanniere', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de bannière (affichée à gauche)',
                'constraints' => [new Image(maxSize: '8M', mimeTypesMessage: 'Image invalide')],
                'attr' => ['accept' => 'image/*']
            ])
            ->add('photoCouverture', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de couverture (affichée en plein écran)',
                'constraints' => [new Image(maxSize: '8M', mimeTypesMessage: 'Image invalide')],
                'attr' => ['accept' => 'image/*']
            ])
            ->add('galleryFiles', FileType::class, [
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'label'  => 'Galerie (plusieurs images)',
                'constraints' => [new All([new Image(maxSize: '8M')])],
                'attr' => ['accept' => 'image/*', 'multiple' => 'multiple']
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
                'attr' => ['class' => 'form-control'],
                'required'   => false,
                'constraints' => [
                    new Length(min: 5, max: 5)
                ]
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
            ->add('satisfactionTemplate', EntityType::class, [
                'class' => SatisfactionTemplate::class,
                'required' => false,
                'placeholder' => 'Aucun template',
                'choice_label' => 'titre',
                'attr' => ['class' => 'form-select js-ts', 'data-ts-placeholder' => 'Aucun template'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('t')
                        ->andWhere('t.entite = :e')->setParameter('e', $entite)
                        ->orderBy('t.titre', 'ASC');
                },
            ])

            ->add('formateurSatisfactionTemplate', EntityType::class, [
                'class' => FormateurSatisfactionTemplate::class,
                'required' => false,
                'placeholder' => 'Aucun template',
                'choice_label' => 'titre', // adapte si ton champ s’appelle autrement
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('t')
                        ->andWhere('t.entite = :e')->setParameter('e', $entite)
                        ->orderBy('t.titre', 'ASC');
                },
            ])

            ->add('isPublic', CheckboxType::class, [
                'label' => 'Rendre cette formation publique',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
            ])
            ->add('publicHosts', EntityType::class, [
                'class' => PublicHost::class,
                'label' => 'Hosts publics autorisés',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choice_label' => fn(PublicHost $h) => $h->getName() . ' — ' . $h->getHost(),
                'attr' => [
                    'class' => 'form-select js-ts',
                    'data-ts-placeholder' => 'Sélectionner les hosts publics',
                ],
                'help' => 'La formation sera visible sur ces hosts si le host est configuré en restriction.',
            ])

            ->add('public', TextType::class, [
                'label' => 'Public',
                'attr' => ['class' => 'form-control'],
                'required'   => false,
            ])

            ->add('financementIndividuel', CheckboxType::class, [
                'required' => false,
                'label' => 'Individuel',
                'row_attr' => ['class' => 'form-check form-switch'],
            ])
            ->add('financementCpf', CheckboxType::class, [
                'required' => false,
                'label' => 'CPF',
                'row_attr' => ['class' => 'form-check form-switch'],
            ])
            ->add('financementEntreprise', CheckboxType::class, [
                'required' => false,
                'label' => 'Entreprise',
                'row_attr' => ['class' => 'form-check form-switch'],
            ])
            ->add('financementOpco', CheckboxType::class, [
                'required' => false,
                'label' => 'OPCO',
                'row_attr' => ['class' => 'form-check form-switch'],
            ])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'required' => false,
                'placeholder' => '- Sélectionner -',
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('c')
                        ->leftJoin('c.parent', 'p')->addSelect('p')
                        ->andWhere('c.entite = :e')->setParameter('e', $entite)
                        ->orderBy('p.nom', 'ASC')
                        ->addOrderBy('c.nom', 'ASC');
                },
                'attr' => ['class' => 'form-select rounded-3 shadow-sm js-ts', 'data-ts-placeholder' => '- Sélectionner -'],
            ])
        ;


        // Transformer euros <-> cents
        $b->get('prixBaseCents')->addModelTransformer($this->eurosToCents);
        $b->get('prixReduitCents')->addModelTransformer($this->eurosToCents);
    }


    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Formation::class,
            'entite' => null,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
