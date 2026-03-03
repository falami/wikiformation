<?php

namespace App\Form\Administrateur;

use App\Entity\Session;
use App\Entity\Formation;
use App\Entity\Site;
use App\Entity\Engin;
use App\Entity\Entite;
use App\Entity\Formateur;
use App\Enum\StatusSession;
use App\Enum\TypeFinancement;
use App\Form\Administrateur\SessionInscriptionType;
use App\Form\DataTransformer\EurosToCentsTransformer;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    TextType,
    IntegerType,
    EnumType,
    CollectionType,
    ChoiceType,
    CheckboxType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Enum\PieceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;


class SessionType extends AbstractType
{
    public function __construct(
        private EurosToCentsTransformer $eurosToCents
    ) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var Entite|null $entite */
        $entite = $o['entite'] ?? null;
        $b
            ->add('typeFinancement', EnumType::class, [
                'class' => TypeFinancement::class,
                'label' => 'Type de financement',
                'attr'  => [
                    'class' => 'form-select',
                    'data-of-value' => TypeFinancement::OF->value, // ✅ la vraie value
                ],
                'choice_label' => fn($e) => match ($e) {
                    TypeFinancement::INDIVIDUEL => 'Individuel',
                    TypeFinancement::CPF => 'CPF',
                    TypeFinancement::ENTREPRISE => 'Entreprise',
                    TypeFinancement::OPCO => 'OPCO',
                    TypeFinancement::OF => 'Organisme de formation',
                    default => $e->name,
                },
            ])


            ->add('formation', EntityType::class, [
                'class' => Formation::class,
                'label' => '*Formation',
                'required' => false,
                'placeholder' => '- Sélectionner une formation -',
                'choice_label' => function (Formation $f) {
                    $niveau = $f->getNiveau();
                    $note = $f->getNote();

                    $titre = $f->getTitre();
                    if ($note !== null && $note !== '') {
                        $titre .= ' (' . $note . ')';
                    }

                    return sprintf('%s - %s - %dj', $titre, $niveau?->label() ?? 'Niveau non renseigné', (int) $f->getDuree());
                },
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('f');
                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('f.entite = :e')->setParameter('e', $entite)
                        ->orderBy('f.titre', 'ASC');
                },
            ])


            // ✅ Nouveau champ libre pour OF
            ->add('formationIntituleLibre', TextType::class, [
                'label' => 'Intitulé de la formation (sous-traitance OF)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : SST initial - 14h - Inter',
                    'autocomplete' => 'off',
                ],
            ])

            ->add('organismeFormation', EntityType::class, [
                'class' => Entreprise::class,
                'label' => 'Organisme de formation',
                'required' => false, // on gère le required en JS (comme formationIntituleLibre)
                'placeholder' => '- Sélectionner une entreprise -',
                'choice_label' => fn(Entreprise $e) => $e->getRaisonSociale(),
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (EntrepriseRepository $er) use ($o) {
                    $qb = $er->createQueryBuilder('e')->orderBy('e.raisonSociale', 'ASC');

                    // ✅ filtre sur l'entité courante si tu veux éviter de voir celles des autres entités
                    if (!empty($o['entite'])) {
                        $qb->andWhere('e.entite = :entite')->setParameter('entite', $o['entite']);
                    }

                    return $qb;
                },
            ])


            ->add('site', EntityType::class, [
                'class' => Site::class,
                'choice_label' => fn(Site $s) => $s->getNom() . ' - ' . $s->getVille(),
                'label' => '*Site',
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('s');
                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('s.entite = :e')->setParameter('e', $entite)
                        ->orderBy('s.nom', 'ASC');
                },
            ])
            ->add('engin', EntityType::class, [
                'class' => Engin::class,
                'choice_label' => 'nom',
                'required' => false,
                'label' => 'Engin',
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('e');
                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('e.entite = :e0')->setParameter('e0', $entite)
                        ->orderBy('e.nom', 'ASC');
                },
            ])
            ->add('formateur', EntityType::class, [
                'class' => Formateur::class,
                'choice_label' => fn(Formateur $f) => $f->getUtilisateur()->getNom() . ' ' . $f->getUtilisateur()->getPrenom(),
                'required' => false,
                'label' => 'Formateur',
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('f')
                        ->leftJoin('f.utilisateur', 'u')->addSelect('u');

                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('f.entite = :e')->setParameter('e', $entite)
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
            ])
            ->add('code', HiddenType::class, [])
            ->add('capacite', IntegerType::class, [
                'label' => 'Capacité',
                'attr' => ['class' => 'form-control', 'min' => 1],
            ])
            ->add('status', EnumType::class, [
                'class' => StatusSession::class,
                'label' => 'Statut',
                'attr'  => ['class' => 'form-select'],
                'choice_label' => fn($e) => match ($e) {
                    StatusSession::DRAFT        => 'Brouillon',
                    StatusSession::PUBLISHED    => 'Publiée',
                    StatusSession::FULL         => 'Complète',
                    StatusSession::CANCELED     => 'Annulée',
                    StatusSession::DONE         => 'Terminée',
                    default => $e->name,
                },
            ])
            ->add('montantCents', TextType::class, [
                'label' => 'Tarif spécifique (€)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'decimal',
                    'placeholder' => '1 390,00',
                ],
                'help' => 'Laisse vide pour utiliser le prix de la formation.',
            ])
            ->add('jours', CollectionType::class, [
                'entry_type' => SessionJourType::class,
                'label' => 'Journées',
                'entry_options' => [
                    'label'  => false,
                    'entite' => $o['entite'] ?? null,
                ],
                'allow_add'    => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('inscriptions', CollectionType::class, [
                'entry_type' => SessionInscriptionType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => false,
                'required' => false,
                'entry_options' => [
                    'label'  => false,
                    'entite' => $o['entite'] ?? null,   // ✅ IMPORTANT
                ],
            ])
            ->add('piecesObligatoires', ChoiceType::class, [
                'label' => 'Pièces obligatoires pour valider un dossier',
                'choices' => PieceType::cases(),
                'choice_label' => fn(PieceType $p) => $p->label(),
                'choice_value' => fn($p) => $p instanceof PieceType ? $p->value : $p,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Coche les pièces qui doivent être présentes et validées pour que le dossier soit complet.',
                'row_attr' => ['class' => 'mb-3'],
            ])

            // switches (inchangés)
            ->add('equipOrdinateurFormateur', CheckboxType::class, [
                'required' => false,
                'label' => 'Ordinateur (formateur)',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('equipVideoprojecteurEcran', CheckboxType::class, [
                'required' => false,
                'label' => 'Vidéoprojecteur / écran',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('equipInternetStable', CheckboxType::class, [
                'required' => false,
                'label' => 'Connexion Internet stable',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('equipTableauPaperboard', CheckboxType::class, [
                'required' => false,
                'label' => 'Tableau blanc / paperboard',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('equipMarqueursSupportsImprimes', CheckboxType::class, [
                'required' => false,
                'label' => 'Marqueurs, post-its, supports imprimés',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('salleAdapteeTailleGroupe', CheckboxType::class, [
                'required' => false,
                'label' => 'Salle adaptée à la taille du groupe',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('salleTablesChaisesErgo', CheckboxType::class, [
                'required' => false,
                'label' => 'Tables, chaises ergonomiques',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('salleLumiereChauffageClim', CheckboxType::class, [
                'required' => false,
                'label' => 'Lumière, chauffage/climatisation',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
            ->add('salleEauCafe', CheckboxType::class, [
                'required' => false,
                'label' => 'Eau / café',
                'row_attr' => ['class' => 'form-check form-switch mb-2'],
            ])
        ;

        $b->get('montantCents')->addModelTransformer($this->eurosToCents);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Session::class,
            'is_edit'    => false,
            'entite'     => null,
        ]);

        $r->setAllowedTypes('is_edit', 'bool');
        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
