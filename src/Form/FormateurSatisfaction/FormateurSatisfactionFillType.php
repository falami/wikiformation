<?php

namespace App\Form\FormateurSatisfaction;

use App\Entity\FormateurSatisfactionTemplate;
use App\Enum\AcquisitionLevel;
use App\Enum\SatisfactionQuestionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Validator\Constraints as Assert;




final class FormateurSatisfactionFillType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    /** @var FormateurSatisfactionTemplate $template */
    $template = $options['template'];
    $stagiaires = $options['stagiaires'];   // Utilisateur[]
    $objectives = $options['objectives'];   // FormationObjective[]

    // ===== Questions dynamiques =====
    foreach ($template->getChapters() as $chapter) {
      foreach ($chapter->getQuestions() as $q) {
        $qid = $q->getId();
        if (!$qid) continue;

        $name = 'q_' . $qid;
        $label = $q->getLibelle();
        $help = $q->getHelp();
        $required = $q->isRequired();
        $type = $q->getType();

        if ($type === SatisfactionQuestionType::SCALE || $type === SatisfactionQuestionType::STARS) {
          // ✅ comme stagiaire : radios (le JS étoiles les remplace)
          $min = $q->getMinValue() ?? 0;
          $max = $q->getMaxValeur() ?? ($q->getMetricMax() ?? 10);

          // si tu veux forcer 0..10 quoi qu’il arrive :
          // $min = 0; $max = 10;

          $choices = [];
          for ($i = $min; $i <= $max; $i++) {
            $choices[(string)$i] = $i;
          }

          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\NotBlank(['message' => 'Veuillez répondre à cette question.']);
          }

          $builder->add($name, ChoiceType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'choices' => $choices,
            'expanded' => true,
            'multiple' => false,
            'constraints' => $constraints,
          ]);
          continue;
        }


        if ($type === SatisfactionQuestionType::YES_NO) {
          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\NotBlank(['message' => 'Veuillez sélectionner Oui ou Non.']);
          }

          $builder->add($name, ChoiceType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'choices' => ['Oui' => 1, 'Non' => 0],
            'expanded' => true,
            'multiple' => false,
            'constraints' => $constraints,
          ]);

          continue;
        }

        if ($type === SatisfactionQuestionType::TEXT) {
          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\NotBlank(['message' => 'Ce champ est obligatoire.']);
          }

          $builder->add($name, TextType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'attr' => [
              'placeholder' => $q->getPlaceholder() ?? '',
              'class' => 'form-control',
            ],
            'constraints' => $constraints,
          ]);

          continue;
        }

        if ($type === SatisfactionQuestionType::TEXTAREA) {
          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\NotBlank(['message' => 'Ce champ est obligatoire.']);
          }

          $builder->add($name, TextareaType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'attr' => [
              'rows' => 4,
              'placeholder' => $q->getPlaceholder() ?? '',
              'class' => 'form-control',
            ],
            'constraints' => $constraints,
          ]);

          continue;
        }


        if ($type === SatisfactionQuestionType::CHOICE) {
          $choices = $q->getChoices() ?? [];
          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\NotBlank(['message' => 'Veuillez sélectionner une valeur.']);
          }

          $builder->add($name, ChoiceType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'choices' => array_combine($choices, $choices) ?: [],
            'expanded' => false,
            'multiple' => false,
            'placeholder' => '-',
            'constraints' => $constraints,
          ]);

          continue;
        }

        if ($type === SatisfactionQuestionType::MULTICHOICE) {
          $choices = $q->getChoices() ?? [];
          $constraints = [];
          if ($required) {
            $constraints[] = new Assert\Count([
              'min' => 1,
              'minMessage' => 'Veuillez sélectionner au moins une option.',
            ]);
          }

          $builder->add($name, ChoiceType::class, [
            'label' => $label,
            'required' => $required,
            'help' => $help,
            'choices' => array_combine($choices, $choices) ?: [],
            'expanded' => true,
            'multiple' => true,
            'constraints' => $constraints,
          ]);

          continue;
        }
      }
    }






    // ✅ Si aucun objectif n'existe encore : on demande au formateur de les saisir
    if (empty($objectives)) {
      $builder->add('competences_points', TextareaType::class, [
        'label' => 'Points / compétences vus durant la formation',
        'required' => true,
        'mapped' => false,
        'attr' => [
          'rows' => 5,
          'placeholder' => "Un point par ligne...",
          'class' => 'form-control',
        ],
        'constraints' => [
          new Assert\NotBlank(['message' => 'Veuillez saisir au moins un point / compétence.']),
        ],
      ]);

      $builder->add('obj_matrix_json', HiddenType::class, [
        'mapped' => false,
        'required' => false,
      ]);
    }



    // ✅ Si aucun objectif n'existe encore : saisir points + JSON matrix
    // Dans buildForm()

    if (empty($objectives)) {
      $builder->add('competences_points', TextareaType::class, [
        'label' => 'Points / compétences vus durant la formation',
        'required' => true,
        'mapped' => false,
        'attr' => [
          'rows' => 5,
          'placeholder' => "Un point par ligne...",
          'class' => 'form-control',
        ],
      ]);

      // ✅ AJOUT ICI
      $builder->add('obj_matrix_json', HiddenType::class, [
        'mapped' => false,
        'required' => false,
      ]);
    }


    // ✅ Si objectifs existent : grille classique
    if (!empty($objectives)) {
      foreach ($stagiaires as $s) {
        $sid = $s->getId();
        if (!$sid) continue;

        foreach ($objectives as $o) {
          $oid = $o->getId();
          if (!$oid) continue;

          $builder->add('obj_' . $sid . '_' . $oid, ChoiceType::class, [
            'label' => false,
            'required' => false,
            'choices' => [
              AcquisitionLevel::ACQUIRED->label() => AcquisitionLevel::ACQUIRED->value,
              AcquisitionLevel::PARTIAL->label() => AcquisitionLevel::PARTIAL->value,
              AcquisitionLevel::NOT_ACQUIRED->label() => AcquisitionLevel::NOT_ACQUIRED->value,
            ],
            'placeholder' => '-',
            'expanded' => false,
            'multiple' => false,
            'attr' => ['class' => 'form-select form-select-sm'],
          ]);
        }

        $builder->add('obj_comment_' . $sid, TextareaType::class, [
          'label' => false,
          'required' => false,
          'attr' => [
            'rows' => 2,
            'placeholder' => 'Commentaire sur le stagiaire…',
            'class' => 'form-control form-control-sm',
          ],
        ]);
      }
    }


    // Dans buildForm()

    if (empty($objectives)) {
      $builder->add('competences_points', TextareaType::class, [
        'label' => 'Points / compétences vus durant la formation',
        'required' => true,
        'mapped' => false,
        'attr' => [
          'rows' => 5,
          'placeholder' => "Un point par ligne...",
          'class' => 'form-control',
        ],
      ]);

      // ✅ AJOUT ICI
      $builder->add('obj_matrix_json', HiddenType::class, [
        'mapped' => false,
        'required' => false,
      ]);
    }
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => null,
      'template' => null,
      'stagiaires' => [],
      'objectives' => [],
    ]);

    $resolver->setRequired(['template', 'stagiaires', 'objectives']);
    $resolver->setAllowedTypes('template', FormateurSatisfactionTemplate::class);
    $resolver->setAllowedTypes('stagiaires', 'array');
    $resolver->setAllowedTypes('objectives', 'array');
  }
}
