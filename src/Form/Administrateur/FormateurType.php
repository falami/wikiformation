<?php

namespace App\Form\Administrateur;

use App\Entity\Formateur;
use App\Entity\Utilisateur;
use App\Entity\Engin;
use App\Entity\Site;
use App\Entity\Entite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{FileType, TextareaType, TextType, CheckboxType, ChoiceType, MoneyType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class FormateurType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var Entite|null $entite */
        $entite = $o['entite'];

        $b
            ->add('utilisateur', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => fn(Utilisateur $u) => $u->getNom() . ' ' . $u->getPrenom() . ' - ' . $u->getEmail(),
                'label' => '*Utilisateur',
                'attr' => ['class' => 'form-select'],
                'query_builder' => function () use ($entite) {
                    $qb = $this->em->createQueryBuilder()
                        ->select('u')
                        ->from(Utilisateur::class, 'u')
                        ->innerJoin('u.utilisateurEntites', 'ue');

                    if ($entite) {
                        $qb->andWhere('ue.entite = :entite')->setParameter('entite', $entite);
                    } else {
                        // sécurité : si pas d'entité, on n'affiche rien
                        $qb->andWhere('1 = 0');
                    }

                    return $qb->orderBy('u.nom', 'ASC');
                },
            ])

            ->add('qualificationEngins', EntityType::class, [
                'class' => Engin::class,
                'choice_label' => fn(Engin $e) => $e->getNom() . ' (' . $e->getSite()->getNom() . ')',
                'label' => 'Engins qualifiés',
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélection multiple'],
                'query_builder' => function () use ($entite) {
                    $qb = $this->em->createQueryBuilder()
                        ->select('e', 's')
                        ->from(Engin::class, 'e')
                        ->innerJoin('e.site', 's');

                    if ($entite) {
                        // ⚠️ adapte le champ selon ton modèle :
                        // - si Site a une propriété "entite" -> s.entite
                        // - si Engin a une propriété "entite" -> e.entite
                        $qb->andWhere('s.entite = :entite')->setParameter('entite', $entite);
                    } else {
                        $qb->andWhere('1 = 0');
                    }

                    return $qb->orderBy('e.nom', 'ASC');
                },
            ])

            ->add('sitePreferes', EntityType::class, [
                'class' => Site::class,
                'choice_label' => fn(Site $s) => $s->getNom() . ' - ' . $s->getVille(),
                'label' => 'Sites préférés',
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélection multiple'],
                'query_builder' => function () use ($entite) {
                    $qb = $this->em->createQueryBuilder()
                        ->select('s')
                        ->from(Site::class, 's');

                    if ($entite) {
                        // ⚠️ adapte le champ selon ton modèle : s.entite ou s.organisme etc.
                        $qb->andWhere('s.entite = :entite')->setParameter('entite', $entite);
                    } else {
                        $qb->andWhere('1 = 0');
                    }

                    return $qb->orderBy('s.nom', 'ASC');
                },
            ])

            ->add('certifications', TextareaType::class, [
                'label' => 'Certifications',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'STCW, Médical, etc.'],
            ])
            ->add('assujettiTva', CheckboxType::class, [
                'label' => 'Formateur assujetti à la TVA',
                'required' => false,
            ])
            ->add('tauxTvaParDefaut', ChoiceType::class, [
                'label' => 'Taux de TVA appliqué',
                'required' => false,
                'placeholder' => 'Choisir un taux',
                'choices' => [
                    '0 % (non assujetti / exonéré)' => 0,
                    '5,5 %' => 5.5,
                    '10 %'  => 10.0,
                    '20 %'  => 20.0,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('numeroTvaIntra', TextType::class, [
                'label' => 'N° de TVA',
                'attr'  => ['class' => 'form-control', 'placeholder' => 'FR00000000000'],
                'required' => false,
            ])
            ->add('modeRemuneration', ChoiceType::class, [
                'label' => 'Mode de rémunération',
                'required' => false,
                'placeholder' => 'Non défini',
                'choices' => [
                    'À l’heure'   => 'HEURE',
                    'À la journée' => 'JOUR',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('tauxHoraireCents', MoneyType::class, [
                'label' => 'Taux horaire HT',
                'required' => false,
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 60,00'],
            ])
            ->add('tauxJournalierCents', MoneyType::class, [
                'label' => 'Taux journalier HT',
                'required' => false,
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 400,00'],
            ])
            ->add('photo', FileType::class, [
                'label' => 'Photo (optionnel)',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new Assert\File([
                        'maxSize' => '4M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Formats acceptés : JPG, PNG, WEBP',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Formateur::class,
            'entite' => null,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
