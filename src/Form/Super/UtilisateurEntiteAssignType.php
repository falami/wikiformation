<?php
// src/Form/Super/UtilisateurEntiteAssignType.php

namespace App\Form\Super;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\EntiteRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UtilisateurEntiteAssignType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'query_builder' => fn(UtilisateurRepository $r) => $r->createQueryBuilder('u')->orderBy('u.email', 'ASC'),
        'choice_label' => fn(Utilisateur $u) => trim(sprintf('%s %s — %s', $u->getPrenom(), $u->getNom(), $u->getEmail())),
        'placeholder' => '— Choisir un utilisateur —',
        'label' => 'Utilisateur',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('entite', EntityType::class, [
        'class' => Entite::class,
        'query_builder' => fn(EntiteRepository $r) => $r->createQueryBuilder('e')->orderBy('e.nom', 'ASC'),
        'choice_label' => fn(Entite $e) => (string) $e->getNom(),
        'placeholder' => '— Choisir une entité —',
        'label' => 'Entité',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('roles', ChoiceType::class, [
        'label' => 'Rôles dans l’entité',
        'mapped' => true,          // <- important : on mappe sur UtilisateurEntite::roles
        'required' => true,
        'multiple' => true,        // <- JSON roles
        'expanded' => false,
        'choices' => [
          'Stagiaire'      => UtilisateurEntite::TENANT_STAGIAIRE,
          'Formateur'      => UtilisateurEntite::TENANT_FORMATEUR,
          'OPCO'           => UtilisateurEntite::TENANT_OPCO,
          'Entreprise'     => UtilisateurEntite::TENANT_ENTREPRISE,
          'Organisme (OF)' => UtilisateurEntite::TENANT_OF,
          'Commercial'     => UtilisateurEntite::TENANT_COMMERCIAL,
          'Administrateur' => UtilisateurEntite::TENANT_ADMIN,
          'Dirigeant'      => UtilisateurEntite::TENANT_DIRIGEANT,
        ],
        'attr' => ['class' => 'form-select'],
      ])
      ->add('fonction', TextType::class, [
        'label' => 'Fonction (optionnel)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'ex: Responsable pédagogique'],
      ])
      ->add('couleur', TextType::class, [
        'label' => 'Couleur (hex)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => '#0d6efd'],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => UtilisateurEntite::class,
    ]);
  }
}
