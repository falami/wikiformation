<?php
// src/Form/Administrateur/ElearningNode.php
declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\Elearning\ElearningNode;
use App\Enum\NodeType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  CheckboxType,
  IntegerType,
  TextareaType,
  TextType,
  EnumType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

class ElearningNodeType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var ElearningNode|null $node */
    $node = $b->getData();

    $b
      ->add('type', EnumType::class, [
        'class'       => NodeType::class,
        'label'       => 'Type',
        'placeholder' => '- Sélectionner -',
        'attr'        => ['class' => 'form-select'],
        'choice_label' => fn(NodeType $t) => match ($t) {
          NodeType::CHAPITRE      => 'Chapitre',
          NodeType::SOUS_CHAPITRE => 'Sous-chapitre',
          default                 => $t->name,
        },
      ])

      // Parent (optionnel) - on propose seulement les CHAPITRES de la même formation
      ->add('parent', EntityType::class, [
        'class'         => ElearningNode::class,
        'label'         => 'Parent (si sous-chapitre)',
        'required'      => false,
        'placeholder'   => '- Aucun (racine) -',
        'attr'          => ['class' => 'form-select'],
        'choice_label'  => 'titre',
        'disabled' => (bool) $o['lock_parent'],
        'query_builder' => function (EntityRepository $er) use ($node) {
          // Si on n’a pas encore de formation (nouvelle création), on renvoie une liste vide
          if (!$node || !$node->getCourse()) {
            return $er->createQueryBuilder('n')->where('1 = 0');
          }
          return $er->createQueryBuilder('n')
            ->andWhere('n.course = :f')
            ->andWhere('n.type = :chapitre')
            ->setParameter('f', $node->getCourse())
            ->setParameter('chapitre', NodeType::CHAPITRE)
            ->orderBy('n.position', 'ASC');
        },
      ])

      ->add('titre', TextType::class, [
        'label' => '*Titre',
        'attr'  => ['class' => 'form-control', 'placeholder' => 'Ex. : Manoeuvres de base'],
      ])
      ->add('slug', TextType::class, [
        'label' => '*Slug',
        'attr'  => ['class' => 'form-control', 'placeholder' => 'manoeuvres-de-base'],
        'help'  => 'Sans espaces ni accents. Généré automatiquement si laissé vide côté contrôleur.',
        'required' => false,
      ])

      ->add('dureeMinutes', IntegerType::class, [
        'label'    => 'Durée (minutes)',
        'required' => false,
        'attr'     => ['class' => 'form-control', 'min' => 0, 'placeholder' => 'Ex. : 45'],
      ])
      ->add('position', HiddenType::class, [
        'empty_data' => '0',
      ])
      ->add('isPublished', CheckboxType::class, [
        'label'    => 'Publié',
        'required' => false,
        'label_attr' => ['class' => 'form-check-label'],
        'row_attr'   => ['class' => 'form-check form-switch'], // joli switch Bootstrap
        'attr'       => ['class' => 'form-check-input'],
      ])
      ->add('parentIdLock', HiddenType::class, [
        'mapped' => false,
        'required' => false,
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ElearningNode::class,
      'lock_parent' => false,
    ]);
  }
}
