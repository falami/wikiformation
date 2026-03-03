<?php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\UtilisateurEntite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  CheckboxType,
  ChoiceType,
  EmailType,
  TextType,
  FileType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{
  Image,
};
use App\Form\DataTransformer\FrenchToDateTransformer;
use Symfony\Component\Form\FormInterface;

final class UtilisateurType extends AbstractType
{
  public function __construct(
    private FrenchToDateTransformer $dateFr,
  ) {}

  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $locked = $o['locked'] ?? false;
    $entite = $o['entite'] ?? null;
    /** @var Utilisateur $utilisateur */
    $utilisateur = $b->getData();

    // ✅ Rôle “dans l’entité” (UtilisateurEntite.role)
    $choices = [
      'Stagiaire'      => UtilisateurEntite::TENANT_STAGIAIRE,
      'Formateur'      => UtilisateurEntite::TENANT_FORMATEUR,
      'Entreprise'     => UtilisateurEntite::TENANT_ENTREPRISE,
      'OPCO'           => UtilisateurEntite::TENANT_OPCO,
      'Organisme (OF)' => UtilisateurEntite::TENANT_OF,
      'Commercial'     => UtilisateurEntite::TENANT_COMMERCIAL,
    ];

    if (($o['can_set_high_roles'] ?? false) === true) {
      $choices['Administrateur'] = UtilisateurEntite::TENANT_ADMIN;
      $choices['Dirigeant']      = UtilisateurEntite::TENANT_DIRIGEANT;
    }



    $b
      ->add('civilite', ChoiceType::class, [
        'required' => false,
        'choices' => ['-' => null, 'Monsieur' => 'Monsieur', 'Madame' => 'Madame'],
        'attr' => ['class' => 'form-select']
      ])
      ->add('prenom', TextType::class, [
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])
      ->add('nom', TextType::class, [
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])
      ->add('email', EmailType::class, [
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])

      ->add('photo', FileType::class, [
        'mapped' => false,
        'required' => false,
        'label'  => 'Photo de profil',
        'constraints' => [new Image(maxSize: '8M', mimeTypesMessage: 'Image invalide')],
        'attr' => ['accept' => 'image/*']
      ])
      ->add('dateNaissance', TextType::class, [
        'required' => false,
        'disabled' => $locked,
        'attr' => ['class' => 'form-control js-flatpickr-date']
      ])

      ->add('entreprise', EntityType::class, [
        'required' => false,
        'class' => Entreprise::class,
        'choice_label' => 'raisonSociale',
        'placeholder' => '- Aucune -',
        'attr' => ['class' => 'form-select js-tomselect-entreprise'],

        'query_builder' => fn(EntityRepository $er) =>
        $er->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('e.raisonSociale', 'ASC')
      ])
      ->add('telephone', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('adresse', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('complement', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('codePostal', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('ville', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('isVerified', CheckboxType::class, [
        'required' => false,
        'disabled' => $locked,
      ])
      ->add('newsletter', CheckboxType::class, [
        'required' => false,
        'disabled' => $locked,
      ])


      ->add('ueRoles', ChoiceType::class, [
        'mapped' => false,
        'required' => true,
        'multiple' => true,
        'expanded' => false,
        'data' => $o['ueRoles'] ?? [UtilisateurEntite::TENANT_STAGIAIRE],
        'choices' => $choices,
        'attr' => ['class' => 'form-select js-ts-ueroles'],
      ])

      ->add('formateurData', FormateurInlineType::class, [
        'mapped' => false,
        'required' => false
      ])
      ->add('entrepriseData', EntrepriseInlineType::class, [
        'mapped' => false,
        'required' => false,
        'locked' => $locked,
        'data' => $utilisateur->getEntreprise() ?: new Entreprise(), // ✅ PREFILL
        'empty_data' => fn(FormInterface $form) => new Entreprise(), // ✅ si vide
      ]);
    $b->get('dateNaissance')->addModelTransformer($this->dateFr);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Utilisateur::class,
      'locked' => false,
      'entite' => null,
      'ueRoles' => [UtilisateurEntite::TENANT_STAGIAIRE],
      'can_set_high_roles' => false,
    ]);
  }
}
