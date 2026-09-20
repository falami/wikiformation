<?php

namespace App\Form\Administrateur;

use App\Entity\{ConventionContrat, Devis};
use App\Repository\ConventionContratRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class DevisConventionLinkType extends AbstractType
{
    public function __construct(private ConventionContratRepository $conventions) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('convention', EntityType::class, [
            'class' => ConventionContrat::class,
            'choices' => $this->conventions->findAttachableToDevis($options['devis']),
            'choice_label' => static fn(ConventionContrat $c) => sprintf('%s — %s — %s', $c->getNumero(), $c->getSession()?->getCode(), $c->getIntituleFormationEffectif()),
            'label' => 'Convention existante', 'placeholder' => 'Choisir une convention à rattacher…',
            'constraints' => [new Assert\NotNull(message: 'Sélectionnez une convention.')],
            'invalid_message' => 'Cette convention ne peut pas être rattachée à ce devis.',
        ])->add('confirmation', CheckboxType::class, [
            'label' => 'Je confirme que cette convention correspond à ce devis. Son PDF reprendra la référence et les montants du devis.',
            'constraints' => [new Assert\IsTrue(message: 'Confirmez le rattachement après avoir vérifié les documents.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('devis')->setAllowedTypes('devis', Devis::class);
        $resolver->setDefaults(['data_class' => null, 'csrf_token_id' => 'devis_convention_link']);
    }
}
