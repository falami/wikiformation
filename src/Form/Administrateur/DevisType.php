<?php

namespace App\Form\Administrateur;

use App\Entity\{Devis, Entite, Entreprise, Inscription, Utilisateur, Formation};
use App\Enum\DevisStatus;
use App\Repository\InscriptionRepository;
use App\Form\DataTransformer\FrenchToDateTransformer;
use App\Repository\EntrepriseRepository;
use App\Repository\FormationRepository;
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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\Prospect;
use App\Repository\ProspectRepository;


final class DevisType extends AbstractType
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

            ->add('prospect', EntityType::class, [
                'class' => Prospect::class,
                'required' => false,
                'label' => 'Destinataire (prospect)',
                'placeholder' => '- Prospect -',
                'choice_label' => function (Prospect $p) {
                    $name = trim($p->getPrenom() . ' ' . $p->getNom());
                    $mail = $p->getEmail() ? ' - ' . $p->getEmail() : '';
                    $soc  = $p->getSociete() ? ' (' . $p->getSociete() . ')' : '';
                    return ($name !== '' ? $name : ('Prospect #' . $p->getId())) . $soc . $mail;
                },
                'query_builder' => function (ProspectRepository $repo) use ($opt) {
                    $qb = $repo->createQueryBuilder('p')
                        ->orderBy('p.createdAt', 'DESC');

                    if (!empty($opt['entite'])) {
                        $qb->andWhere('p.entite = :entite')->setParameter('entite', $opt['entite']);
                    }

                    // optionnel: cacher les prospects "inactifs"
                    // $qb->andWhere('p.isActive = 1');

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
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
                    $qb = $repo->createQueryBuilder('e')
                        ->orderBy('e.raisonSociale', 'ASC');

                    // ✅ si Entreprise a bien un champ "entite"
                    // (tu l'as utilisé dans FactureType)
                    if (!empty($opt['entite'])) {
                        $qb->andWhere('e.entite = :entite')
                            ->setParameter('entite', $opt['entite']);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])

            ->add('inscriptions', EntityType::class, [
                'class' => Inscription::class,
                'required' => false,
                'multiple' => true,
                'choice_label' => function (Inscription $i) {
                    $sess = $i->getSession();
                    return sprintf(
                        'Inscription #%d - %s',
                        $i->getId(),
                        $sess ? $sess->getCode() : '-'
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

            // ✅ même logique que FactureType (TextType + transformer FR)
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
                'required' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('montantTvaCents', MoneyType::class, [
                'label' => 'Montant TVA',
                'divisor' => 100,
                'currency' => false,
                'required' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
            ->add('montantTtcCents', MoneyType::class, [
                'label' => 'Montant TTC',
                'divisor' => 100,
                'currency' => false,
                'required' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])

            ->add('status', ChoiceType::class, [
                'label' => '*Statut',
                'choices' => DevisStatus::cases(),
                'choice_label' => fn(DevisStatus $s) => $s->label(),
                'choice_value' => fn(?DevisStatus $s) => $s?->value,
                'attr' => ['class' => 'form-select'],
            ])

            ->add('lignes', CollectionType::class, [
                'entry_type' => LigneDevisType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
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
        ;

        $b->get('dateEmission')->addModelTransformer($this->dateFr);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Devis::class,
            'entite' => null,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class, 'int']);
    }
}
