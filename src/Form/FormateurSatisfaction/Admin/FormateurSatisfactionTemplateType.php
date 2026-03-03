<?php

namespace App\Form\FormateurSatisfaction\Admin;

use App\Entity\Entite;
use App\Entity\FormateurSatisfactionTemplate;
use App\Entity\Formation;
use App\Repository\FormationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FormateurSatisfactionTemplateType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    /** @var Entite|null $entite */
    $entite = $options['entite'] ?? null;

    $builder
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'required' => true,
        'empty_data' => '',
        'constraints' => [
          new NotBlank(message: 'Le titre est obligatoire.'),
        ],
        'attr' => ['class' => 'form-control'],
      ])
      ->add('isActive', CheckboxType::class, [
        'label' => 'Actif',
        'required' => false,
        'row_attr' => ['class' => 'form-check form-switch'],
      ])
      ->add('formations', EntityType::class, [
        'class' => Formation::class,
        'choice_label' => 'titre',
        'multiple' => true,
        'required' => false,
        'label' => 'Formations ciblées (vide = générique)',
        'placeholder' => false, // (multi) pas de placeholder natif
        'attr' => [
          'class' => 'form-select tomselect',
          'data-ts-placeholder' => 'Choisir une ou plusieurs formations…',
        ],
        'query_builder' => function (FormationRepository $repo) use ($entite) {
          $qb = $repo->createQueryBuilder('f')
            ->orderBy('f.titre', 'ASC');

          // ✅ sécurité multi-tenant : si on n’a pas l’entité, on n’affiche rien
          if (!$entite) {
            return $qb->andWhere('1 = 0');
          }

          return $qb
            ->andWhere('f.entite = :entite')
            ->setParameter('entite', $entite);
        },
      ])
      ->add('chapters', CollectionType::class, [
        'label' => false,
        'entry_type' => FormateurSatisfactionChapterType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__chapter__',
      ])
    ;

    // ✅ Ton PRE_SUBMIT inchangé
    $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
      $data = $event->getData() ?? [];

      if (!isset($data['chapters']) || !is_array($data['chapters'])) {
        $event->setData($data);
        return;
      }

      unset($data['chapters']['__name__']);

      foreach ($data['chapters'] as $ck => $chapter) {
        if (!is_array($chapter)) {
          unset($data['chapters'][$ck]);
          continue;
        }

        if (isset($chapter['questions']) && is_array($chapter['questions'])) {
          unset($chapter['questions']['__name__']);

          foreach ($chapter['questions'] as $qk => $q) {
            if (!is_array($q) || trim((string)($q['libelle'] ?? '')) === '') {
              unset($chapter['questions'][$qk]);
            }
          }

          $data['chapters'][$ck]['questions'] = $chapter['questions'];
        }

        if (trim((string)($chapter['titre'] ?? '')) === '') {
          unset($data['chapters'][$ck]);
        }
      }

      $event->setData($data);
    });
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => FormateurSatisfactionTemplate::class,
      'entite' => null,
    ]);

    $resolver->setAllowedTypes('entite', ['null', Entite::class, 'int']);
  }
}
