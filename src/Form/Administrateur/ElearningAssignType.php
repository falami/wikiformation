<?php
// src/Form/Administrateur/ElearningAssignType.php
namespace App\Form\Administrateur;

use App\Entity\Utilisateur;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

final class ElearningAssignType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $opts): void
  {
    /** @var Entite $entite */
    $entite = $opts['entite'];

    $b
      ->add('stagiaire', EntityType::class, [
        'class' => Utilisateur::class,
        'choice_label' => fn(Utilisateur $u) => $u->getPrenom() . ' ' . $u->getNom() . ' — ' . $u->getEmail(),
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('u')
          ->andWhere('u.entite = :e')->setParameter('e', $entite)
          ->orderBy('u.nom', 'ASC'),
      ])
      ->add('startsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text'])
      ->add('endsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text'])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['entite' => null]);
    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
