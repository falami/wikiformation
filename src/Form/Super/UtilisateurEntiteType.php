<?php

namespace App\Form\Super;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UtilisateurEntiteType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $isNew = (bool) ($options['is_new'] ?? false);

    if ($isNew) {
      // On choisit l'utilisateur existant (SUPER)
      $builder->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'query_builder' => fn(UtilisateurRepository $r) => $r->createQueryBuilder('u')
          ->orderBy('u.email', 'ASC'),
        'choice_label' => fn(Utilisateur $u) => trim(sprintf('%s %s — %s', $u->getPrenom(), $u->getNom(), $u->getEmail())),
        'placeholder' => '— Sélectionner un utilisateur —',
        'label' => 'Utilisateur',
        'attr' => ['class' => 'form-select'],
        'required' => true,
      ]);
    }

    $builder
      // ✅ JSON roles (TENANT_*)
      ->add('roles', ChoiceType::class, [
        'label' => 'Rôles dans l’entité',
        'required' => true,
        'multiple' => true,
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
        // Optionnel mais pratique : au minimum Stagiaire si vide
        'empty_data' => fn() => [UtilisateurEntite::TENANT_STAGIAIRE],
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
        'help' => 'Optionnel. Laisse vide pour auto-couleur.',
      ])
    ;
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => UtilisateurEntite::class,
      'is_new' => false,
    ]);

    $resolver->setAllowedTypes('is_new', 'bool');
  }
}
