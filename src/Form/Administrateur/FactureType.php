<?php

namespace App\Form\Administrateur;

use App\Entity\{Entite, Entreprise, Facture, Inscription, Formation, Utilisateur};
use App\Enum\FactureStatus;
use App\Repository\{FormationRepository, InscriptionRepository};
use App\Form\Administrateur\LigneFactureType;
use App\Form\DataTransformer\FrenchToDateTransformer;
use App\Repository\EntrepriseRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    ChoiceType,
    CollectionType,
    CurrencyType,
    MoneyType,
    TextType,
    NumberType
};
use App\Entity\LigneFacture;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class FactureType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
    ) {}

    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('destinataire', EntityType::class, [
                'class' => Utilisateur::class,
                'required' => false,
                'choice_label' => fn(Utilisateur $u) => sprintf('%s %s - %s', $u->getNom(), $u->getPrenom(), $u->getEmail()),
                'label' => 'Destinataire (personne)',
                'placeholder' => '- Personne -',
                'attr' => ['class' => 'form-select'],

                'query_builder' => function ($repo) use ($opt) {
                    $qb = $repo->createQueryBuilder('u')
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');

                    if (empty($opt['entite'])) {
                        return $qb->andWhere('1 = 0'); // sécurité multi-tenant
                    }

                    return $qb
                        ->andWhere('u.entite = :entite')
                        ->setParameter('entite', $opt['entite']);
                },
            ])


            ->add('formation', EntityType::class, [
                'class' => Formation::class,
                'required' => false,
                'placeholder' => '- Formation -',
                'choice_label' => fn(Formation $f) =>
                sprintf('%s - %s - %sj', $f->getTitre(), $f->getNiveau()->label(), (string)($f->getDuree() ?? '-')),
                'choice_attr' => function (Formation $f) {
                    $jours  = (int)($f->getDuree() ?? 0);
                    $heures = $jours * 7;

                    return [
                        'data-title' => $f->getTitre(),
                        'data-days'  => (string)$jours,
                        'data-hours' => (string)$heures, // ✅ 7h par jour
                        'data-prix-base-cents'   => (string)$f->getPrixBaseCents(),
                        'data-prix-reduit-cents' => (string)($f->getPrixReduitCents() ?? 0),
                    ];
                },
                'query_builder' => function (FormationRepository $repo) use ($opt) {
                    $qb = $repo->createQueryBuilder('f')->orderBy('f.titre', 'ASC');
                    if (!empty($opt['entite'])) {
                        $qb->andWhere('f.entite = :entite')->setParameter('entite', $opt['entite']);
                    }
                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])

            ->add('entrepriseDestinataire', EntityType::class, [
                'class' => Entreprise::class,
                'required' => false,
                'label' => 'Destinataire (entreprise)',
                'placeholder' => '- Entreprise -',
                'query_builder' => function (EntrepriseRepository $repo) use ($opt) {
                    return $repo->createQueryBuilder('e')
                        ->andWhere('e.entite = :entite')
                        ->setParameter('entite', $opt['entite'])
                        ->orderBy('e.raisonSociale', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])


            ->add('inscriptions', EntityType::class, [
                'class' => Inscription::class,
                'required' => false,
                'multiple' => true,
                'choice_label' => function (Inscription $i) {
                    $sess = $i->getSession();
                    $nomComplet = $i->getStagiaire()
                        ? trim(($i->getStagiaire()->getNom() ?? '') . ' ' . ($i->getStagiaire()->getPrenom() ?? ''))
                        : '-';
                    return sprintf(
                        'Inscription #%d - %s - %s',
                        $i->getId(),
                        $sess ? $sess->getCode() : '-',
                        $nomComplet ?: '-'
                    );
                },
                'label' => 'Inscriptions rattachées (optionnel)',
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (InscriptionRepository $repo) use ($opt) {
                    $qb = $repo->createQueryBuilder('i')
                        ->leftJoin('i.session', 's')
                        ->addSelect('s')
                        ->orderBy('i.id', 'DESC');

                    // adapte selon ton modèle : inscription -> session -> entite
                    if (!empty($opt['entite'])) {
                        $qb->andWhere('s.entite = :entite')->setParameter('entite', $opt['entite']);
                    }

                    return $qb;
                },
            ])

            ->add('dateEmission', TextType::class, [
                'label' => '*Date d’émission',
                'attr'  => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
            ])

            ->add('devise', CurrencyType::class, [
                'label' => '*Devise',
                'data' => 'EUR',
                'attr' => ['class' => 'form-select'],
            ])

            ->add('montantHtCents', MoneyType::class, [
                'label' => 'Montant HT',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('montantTvaCents', MoneyType::class, [
                'label' => 'Montant TVA',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('montantTtcCents', MoneyType::class, [
                'label' => 'Montant TTC',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])

            ->add('status', ChoiceType::class, [
                'label' => '*Statut',
                'choices' => FactureStatus::cases(),
                'choice_label' => fn(FactureStatus $s) => $s->label(),
                'choice_value' => fn(?FactureStatus $s) => $s?->value,
                'attr' => ['class' => 'form-select'],
            ])

            ->add('lignes', CollectionType::class, [
                'entry_type' => LigneFactureType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,

                // ✅ IMPORTANT : valeurs par défaut pour le prototype (AJAX/JS)
                'prototype_data' => (new LigneFacture())
                    ->setTva(20.0)
                    ->setQte(1),

                'label' => 'Lignes',
                'attr' => ['data-collection' => 'lignes'],
            ])


            ->add('remiseGlobalePourcent', NumberType::class, [
                'label' => 'Remise globale %',
                'required' => false,
                'scale' => 2,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('remiseGlobaleMontantCents', MoneyType::class, [
                'label' => 'Remise globale €',
                'required' => false,
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note / libellé interne',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 2,
                    'placeholder' => 'Ex: Formation Excel – Total / Session S2026-01 / Acompte…',
                ],
                'help' => 'Interne : pour retrouver rapidement à quoi correspond la facture (pas obligatoire sur le PDF).',
            ])
        ;

        $b->get('dateEmission')->addModelTransformer($this->dateFr);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Facture::class,
            'entite' => null, // IMPORTANT pour le query_builder
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class, 'int']);
    }
}
