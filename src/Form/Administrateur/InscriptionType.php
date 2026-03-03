<?php

namespace App\Form\Administrateur;

use App\Entity\Inscription;
use App\Entity\Session;
use App\Entity\Utilisateur;
use App\Entity\Entreprise;
use App\Entity\Entite;
use App\Enum\StatusInscription;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, ChoiceType, MoneyType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class InscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var Entite|null $entite */
        $entite = $o['entite'] ?? null;

        $b
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => fn(Session $s) => sprintf('%s - %s', $s->getFormation()?->getTitre(), $s->getCode()),
                'placeholder' => 'Sélectionner une session',
                'label' => '*Session',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                // si tu veux aussi filtrer les sessions par entité :
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('s')
                        ->leftJoin('s.formation', 'f')->addSelect('f')
                        ->orderBy('s.id', 'DESC');

                    if ($entite) {
                        $qb->andWhere('s.entite = :entite')->setParameter('entite', $entite);
                    }
                    return $qb;
                },
            ])

            ->add('stagiaire', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => fn(Utilisateur $u) => sprintf('%s %s - %s', $u->getNom(), $u->getPrenom(), $u->getEmail()),
                'placeholder' => 'Sélectionner un stagiaire',
                'label' => '*Stagiaire',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                // optionnel : filtrer par entité si tes utilisateurs sont rattachés à une entité
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('u')
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');

                    if ($entite) {
                        $qb->andWhere('u.entite = :entite')->setParameter('entite', $entite);
                    }
                    return $qb;
                },
            ])

            // ✅ AJOUT ENTREPRISE
            ->add('entreprise', EntityType::class, [
                'class' => Entreprise::class,
                'label' => 'Entreprise (si financement entreprise)',
                'required' => false,
                'placeholder' => '- Aucune / particulier -',
                'choice_label' => fn(Entreprise $e) => $e->getRaisonSociale() ?? ('Entreprise #' . $e->getId()),
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('e')
                        ->orderBy('e.raisonSociale', 'ASC');

                    if ($entite) {
                        $qb->andWhere('e.entite = :entite')->setParameter('entite', $entite);
                    }
                    return $qb;
                },
            ])

            ->add('status', ChoiceType::class, [
                'label' => '*Statut de l’inscription',
                'choices' => StatusInscription::cases(),
                'choice_label' => fn(StatusInscription $s) => $s->label(),
                'choice_value' => fn(?StatusInscription $s) => $s?->value,
                'attr' => ['class' => 'form-select'],
            ])


            ->add('montantDuCents', MoneyType::class, [
                'divisor' => 100,
                'currency' => 'EUR',
                'required' => false,
                'label' => 'Montant dû (TTC)',
                'attr' => ['class' => 'form-control', 'placeholder' => '0,00'],
            ])

            ->add('montantRegleCents', MoneyType::class, [
                'divisor' => 100,
                'currency' => 'EUR',
                'required' => false,
                'label' => 'Montant réglé (TTC)',
                'attr' => ['class' => 'form-control', 'placeholder' => '0,00'],
            ]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Inscription::class,
            'entite' => null, // ✅ option custom
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
