<?php

namespace App\Form\Administrateur;

use App\Entity\Attestation;
use App\Entity\Inscription;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AttestationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('inscription', EntityType::class, [
                'class' => Inscription::class,
                'required' => true,
                'query_builder' => fn(\App\Repository\InscriptionRepository $repo) => $repo->createQueryBuilder('ins')->andWhere('ins.entite = :entite')->setParameter('entite', $opt['entite']),
                'choice_label' => fn(Inscription $i) => sprintf(
                    '#%d - %s - %s',
                    $i->getId(),
                    $i->getSession()?->getCode(),
                    trim(($i->getStagiaire()?->getPrenom() ?? '') . ' ' . ($i->getStagiaire()?->getNom() ?? '')) ?: '-'
                ),
                'label' => 'Inscription concernée',
                'placeholder' => 'Lier une inscription',
                'attr' => ['class' => 'form-select'],
            ])

            ->add('dureeHeures', NumberType::class, [
                'label' => 'Durée réellement suivie (heures)',
                'disabled' => true,
                'help' => 'Le PDF reprend la durée justifiée par les présences et les créneaux, hors pauses. Le taux de présence seul ne suffit pas.',
                'html5' => true, 'scale' => 2,
                'constraints' => [new Assert\PositiveOrZero()],
                'attr'  => ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'placeholder' => '0'],
            ])
            ->add('reussi', CheckboxType::class, [
                'label'    => 'Réussite',
                'required' => false,
                'label_attr' => ['class' => 'form-check-label'],
                'attr'       => ['class' => 'form-check-input'],
                'row_attr'   => ['class' => 'form-switch'],
            ])
            ->add('dateDelivrance', DateType::class, [
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'html5'  => false,
                'format' => 'dd/MM/yyyy',
                'label'  => '*Date de délivrance',
                'attr'   => [
                    'class' => 'form-control flatpickr-date',
                    'placeholder' => 'jj/mm/aaaa',
                    'autocomplete' => 'off',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Attestation::class,
        ]);
        $r->setRequired('entite');
        $r->setAllowedTypes('entite', \App\Entity\Entite::class);
    }
}
