<?php
// src/Form/Super/Billing/EntiteSubscriptionType.php

namespace App\Form\Super\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\Plan;
use App\Repository\Billing\PlanRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextType, DateTimeType, CheckboxType, IntegerType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use App\Form\DataTransformer\JsonToArrayTransformer;

final class EntiteSubscriptionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('plan', EntityType::class, [
        'class' => Plan::class,
        'query_builder' => fn(PlanRepository $r) => $r->createQueryBuilder('p')
          ->andWhere('p.isActive = 1')
          ->orderBy('p.ordre', 'ASC'),
        'choice_label' => fn(Plan $p) => $p->getName() . ' — ' . $p->getCode(),
        'placeholder' => '— Sélectionner un plan —',
        'required' => false,
        'label' => 'Plan',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('status', ChoiceType::class, [
        'label' => 'Statut',
        'choices' => [
          'Incomplete' => EntiteSubscription::STATUS_INCOMPLETE,
          'Trialing (essai)' => EntiteSubscription::STATUS_TRIALING,
          'Active' => EntiteSubscription::STATUS_ACTIVE,
          'Past due' => EntiteSubscription::STATUS_PAST_DUE,
          'Unpaid' => EntiteSubscription::STATUS_UNPAID,
          'Canceled' => EntiteSubscription::STATUS_CANCELED,
        ],
        'attr' => ['class' => 'form-select'],
      ])
      ->add('intervale', ChoiceType::class, [
        'label' => 'Périodicité',
        'choices' => [
          'Mensuel' => 'month',
          'Annuel' => 'year',
        ],
        'attr' => ['class' => 'form-select'],
      ])
      ->add('currentPeriodEnd', DateTimeType::class, [
        'label' => 'Fin de période',
        'required' => false,
        'widget' => 'single_text',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('trialEndsAt', DateTimeType::class, [
        'label' => 'Fin d’essai',
        'required' => false,
        'widget' => 'single_text',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('stripeCustomerId', TextType::class, [
        'label' => 'Stripe Customer ID',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'cus_...'],
      ])
      ->add('stripeSubscriptionId', TextType::class, [
        'label' => 'Stripe Subscription ID',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'sub_...'],
      ]);

    // Addons JSON : chez toi c’est array nullable.
    // Pour rester simple, on laisse l’édition manuelle (string JSON) si tu veux.
    // Si tu as déjà une UI addons, remplace ce champ.
    $builder->add('addons', TextareaType::class, [
      'label' => 'Addons (JSON)',
      'required' => false,
      'attr' => ['class' => 'form-control', 'rows' => 3, 'placeholder' => '["addon_a","addon_b"]'],
    ]);

    $builder->get('addons')->addModelTransformer(new JsonToArrayTransformer());
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => EntiteSubscription::class,
      'is_super' => true,
    ]);

    $resolver->setAllowedTypes('is_super', 'bool');
  }
}
