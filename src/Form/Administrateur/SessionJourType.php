<?php

namespace App\Form\Administrateur;

use App\Entity\SessionJour;
use App\Form\DataTransformer\FrenchToDateTimeTransformer;
use App\Entity\Formateur;
use App\Repository\FormateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SessionJourType extends AbstractType
{
    public function __construct(
        private FrenchToDateTimeTransformer $dateTimeFr
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entite = $options['entite'] ?? null;

        // ✅ champ texte FR (flatpickr) + transformer => DateTimeImmutable en entité
        $dateAttrs = [
            'class' => 'form-control flatpickr-datetime',
            'placeholder' => 'JJ/MM/AAAA HH:mm',
            'autocomplete' => 'off',
            'inputmode' => 'numeric',
        ];

        $builder
            ->add('dateDebut', TextType::class, [
                'label' => 'Début (jour)',
                'required' => true,
                'attr' => $dateAttrs,
            ])
            ->add('dateFin', TextType::class, [
                'label' => 'Fin (jour)',
                'required' => true,
                'attr' => $dateAttrs,
            ])
            ->add('formateur', EntityType::class, [
                'class' => Formateur::class,
                'required' => false,
                'placeholder' => 'Formateur (par défaut celui de la session)',
                'choice_label' => static function (Formateur $f): string {
                    $u = $f->getUtilisateur();
                    return trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''));
                },
                'query_builder' => static function (FormateurRepository $repo) use ($entite) {
                    $qb = $repo->createQueryBuilder('f')
                        ->leftJoin('f.utilisateur', 'u')->addSelect('u')
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');

                    if ($entite) {
                        // ✅ IMPORTANT : si ton champ f.entite est une relation ManyToOne,
                        // passe l'objet Entite, pas son id.
                        $qb->andWhere('f.entite = :entite')
                            ->setParameter('entite', $entite);
                    }

                    return $qb;
                },
                'attr' => [
                    'class' => 'form-select formateur-jour-select',
                ],
            ]);

        // ✅ Transformers (FR string <-> DateTimeImmutable)
        // (doit être fait après l'ajout des champs)
        $builder->get('dateDebut')->addModelTransformer($this->dateTimeFr);
        $builder->get('dateFin')->addModelTransformer($this->dateTimeFr);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => SessionJour::class,
            'entite' => null,
        ]);

        // ✅ évite les mauvaises valeurs passées (ex: id au lieu d’objet)
        // si tu veux être strict : décommente
        // $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
