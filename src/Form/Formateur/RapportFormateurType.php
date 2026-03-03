<?php

namespace App\Form\Formateur;

use App\Entity\RapportFormateur;
use App\Entity\Session;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RapportFormateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => function (Session $s) {
                    $titre = $s->getFormation()?->getTitre() ?: 'Formation';
                    $code  = $s->getCode() ?: '-';
                    return sprintf('%s - %s', $titre, $code);
                },
                'label' => '*Session',
                'placeholder' => 'Sélectionner une session',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('commentaires', TextareaType::class, [
                'required' => false,
                'label' => 'Commentaires libres',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'Observations pédagogiques, incidents, points remarquables, recommandations…',
                ],
                'help' => 'Ce texte pourra apparaître dans le compte-rendu interne.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => RapportFormateur::class]);
    }
}
