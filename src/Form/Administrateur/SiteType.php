<?php

namespace App\Form\Administrateur;

use App\Entity\Site;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextType, NumberType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;


class SiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('nom', TextType::class, [
                'label' => '*Nom du site',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Base Marseille Vieux-Port'],
            ])
            ->add('slug', TextType::class, [
                'label' => '*Slug',
                'attr' => ['class' => 'form-control', 'placeholder' => 'marseille'],
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('complement', TextType::class, [
                'label' => 'Complément',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 10],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('departement', TextType::class, [
                'label' => 'Département',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('region', TextType::class, [
                'label' => 'Région',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('pays', TextType::class, [
                'label' => 'Pays',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'France'],
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'required' => false,
                'attr' => ['class' => 'form-control', 'step' => 'any'],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'required' => false,
                'attr' => ['class' => 'form-control', 'step' => 'any'],
            ])
            ->add('timezone', TextType::class, [
                'label' => 'Fuseau horaire',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Europe/Paris'],
            ])
            ->add('googlePlaceId', HiddenType::class, [
                'required' => false,
            ])
            ->add('formattedAddress', HiddenType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => Site::class]);
    }
}
