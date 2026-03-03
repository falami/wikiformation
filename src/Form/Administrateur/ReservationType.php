<?php

namespace App\Form\Administrateur;

use App\Entity\Entite;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\Utilisateur;
use App\Enum\StatusReservation;
use App\Form\DataTransformer\FrenchToDateTransformer;
use App\Form\DataTransformer\EurosToCentsTransformer;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{EnumType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ReservationType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
        private EurosToCentsTransformer $eurosToCents
    ) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var Entite|null $entite */
        $entite = $o['entite'] ?? null;

        $b
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => 'code',
                'label' => '*Session',
                'attr' => ['class' => 'form-select'],

                // ✅ uniquement les sessions de l'entité
                'query_builder' => static function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('s');
                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('s.entite = :e')->setParameter('e', $entite)
                        ->orderBy('s.id', 'DESC');
                },
            ])
            ->add('utilisateur', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => fn(Utilisateur $u) => $u->getNom() . ' ' . $u->getPrenom() . ' - ' . $u->getEmail(),
                'label' => '*Stagiaire',
                'attr' => ['class' => 'form-select'],

                // ✅ uniquement les utilisateurs (stagiaires) de l'entité
                'query_builder' => static function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('u');
                    if (!$entite) return $qb->andWhere('1 = 0');

                    return $qb
                        ->andWhere('u.entite = :e')->setParameter('e', $entite)
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
            ])
            ->add('status', EnumType::class, [
                'class' => StatusReservation::class,
                'label' => 'Statut',
                'attr'  => ['class' => 'form-select'],
                'choice_label' => fn($e) => match ($e) {
                    StatusReservation::PENDING => 'En attente',
                    StatusReservation::CONFIRMED => 'Confirmée',
                    StatusReservation::CANCELED => 'Annulée',
                    StatusReservation::REFUNDED => 'Remboursée',
                    StatusReservation::WAITING_LIST => 'Liste d’attente',
                    StatusReservation::PAID => 'Payé',
                    default => $e->name,
                },
            ])
            ->add('dateReservation', TextType::class, [
                'label' => '*Date de réservation',
                'attr' => ['class' => 'form-control', 'placeholder' => 'JJ/MM/AAAA'],
            ])
            ->add('montantCents', TextType::class, [
                'label' => 'Montant payé (€)',
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'decimal',
                    'placeholder' => '790,00',
                ],
            ])
            ->add('devise', TextType::class, [
                'label' => 'Devise',
                'attr' => ['class' => 'form-control', 'maxlength' => 3, 'placeholder' => 'EUR'],
            ])
            ->add('paymentIntentId', TextType::class, [
                'label' => 'Stripe PaymentIntent ID',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'pi_...'],
            ])
        ;

        $b->get('dateReservation')->addModelTransformer($this->dateFr);
        $b->get('montantCents')->addModelTransformer($this->eurosToCents);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Reservation::class,
            'entite' => null, // ✅ option custom
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
