<?php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\Entite;
use Doctrine\ORM\EntityManagerInterface;

final class EntrepriseType extends AbstractType
{

  public function __construct(private readonly EntityManagerInterface $em) {}
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'] ?? null;

    $b
      // --- Identité ---
      ->add('raisonSociale', TextType::class, [
        'label' => '*Raison sociale',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : WIKI Formation'],
      ])
      ->add('siret', TextType::class, [
        'label' => 'SIRET',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => '14 chiffres (sans espace)'],
      ])
      ->add('numeroTVA', TextType::class, [
        'label' => 'N° TVA intracommunautaire',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : FRXX123456789'],
      ])

      // --- Emails ---
      ->add('emailFacturation', EmailType::class, [
        'label' => 'Email facturation',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'facturation@entreprise.fr'],
      ])
      ->add('email', EmailType::class, [
        'label' => 'Email (contact)',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'contact@entreprise.fr'],
      ])

      // --- Adresse ---
      ->add('adresse', TextType::class, [
        'label' => 'Adresse',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 12 rue Victor Hugo'],
      ])
      ->add('complement', TextType::class, [
        'label' => 'Complément',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Bâtiment, étage, etc.'],
      ])
      ->add('codePostal', TextType::class, [
        'label' => 'Code postal',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : 75001'],
      ])
      ->add('ville', TextType::class, [
        'label' => 'Ville',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Paris'],
      ])
      ->add('departement', TextType::class, [
        'label' => 'Département',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Gard'],
      ])
      ->add('region', TextType::class, [
        'label' => 'Région',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Occitanie'],
      ])
      ->add('pays', TextType::class, [
        'label' => 'Pays',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : France'],
      ])

      ->add('representant', EntityType::class, [
        'class' => Utilisateur::class,
        'choice_label' => fn(Utilisateur $u) => $u->getNom() . ' ' . $u->getPrenom() . ' - ' . $u->getEmail(),
        'label' => 'Représentant',
        'placeholder' => '— Choisir —',
        'required' => false,
        'attr' => ['class' => 'form-select'],

        'query_builder' => function () use ($entite) {
          $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(Utilisateur::class, 'u')
            ->innerJoin('u.utilisateurEntites', 'ue');

          if ($entite) {
            $qb->andWhere('ue.entite = :entite')->setParameter('entite', $entite);
          } else {
            // Sécurité multi-tenant : si pas d'entité, on ne propose personne
            $qb->andWhere('1 = 0');
          }

          return $qb
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');
        },
      ])

    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Entreprise::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
