<?php
// src/Form/Administrateur/ElearningBlockType.php
declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\Elearning\ElearningBlock;
use App\Entity\{Utilisateur, Entite, Quiz};
use App\Enum\BlockType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ElearningBlockType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('type', EnumType::class, [
        'class'        => BlockType::class,
        'label'        => 'Type',
        'placeholder'  => '- Sélectionner -',
        'attr'         => ['class' => 'form-select'],
        'choice_label' => fn(BlockType $t) => match ($t) {
          BlockType::RICHTEXT  => 'Texte riche',
          BlockType::IMAGE     => 'Image',
          BlockType::VIDEO     => 'Vidéo (URL)',
          BlockType::FILE      => 'Fichier (document)',
          BlockType::QUIZ      => 'Quiz',
          BlockType::CODE      => 'Code',
          BlockType::CHECKLIST => 'Checklist',
          default              => $t->name,
        },
      ])

      ->add('content', TextareaType::class, [
        'label'    => 'Contenu',
        'required' => false,
        'attr'     => [
          'class'       => 'form-control',
          'rows'        => 8,
          'placeholder' => 'HTML / texte… (ou code brut)',
        ],
      ])

      ->add('mediaUrl', TextType::class, [
        'label'    => 'URL vidéo',
        'required' => false,
        'attr'     => [
          'class'       => 'form-control',
          'placeholder' => 'https://youtu.be/… ou https://vimeo.com/…',
        ],
      ])

      ->add('upload', FileType::class, [
        'mapped'   => false,
        'required' => false,
        'label'    => 'Fichier / Photo (upload)',
        'attr'     => ['class' => 'form-control'],
      ])

      ->add('position', HiddenType::class, ['empty_data' => '0'])

      ->add('isRequired', CheckboxType::class, [
        'label'      => 'Obligatoire',
        'required'   => false,
        'label_attr' => ['class' => 'form-check-label'],
        'row_attr'   => ['class' => 'form-check form-switch'],
        'attr'       => ['class' => 'form-check-input'],
      ])

      // ✅ On rend TOUJOURS le sous-form quiz (pour ton JS / ton wrapper),
      // mais on garde l'entité propre via les listeners ci-dessous.
      ->add('quiz', QuizType::class, [
        'label'    => false,
        'required' => false,
      ]);

    // ✅ IMPORTANT : on ne crée PAS un Quiz par défaut sur un bloc non-quiz.
    $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) use ($o) {
      $block = $e->getData();
      if (!$block instanceof ElearningBlock) return;

      if ($block->getType() === BlockType::QUIZ && !$block->getQuiz()) {
        $quiz = new Quiz();
        $quiz->setCreateur($o['createur']);
        $quiz->setEntite($o['entite']);
        $block->setQuiz($quiz);
      }


      // Si ce n'est pas un quiz, on s'assure que l'entité n'a pas de quiz
      if ($block->getType() !== BlockType::QUIZ) {
        $block->setQuiz(null);
      }
    });

    // ✅ Si ce n'est pas un quiz, on jette les données quiz (pour éviter tout binding)
    $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) {
      $data = $e->getData() ?? [];
      $typeValue = strtoupper((string)($data['type'] ?? ''));

      $isQuiz = str_contains($typeValue, 'QUIZ');

      if (!$isQuiz) {
        unset($data['quiz']);            // ✅ ignore quiz
        $e->setData($data);
      }
    });



    $b->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e) use ($o) {
      $block = $e->getData();
      if (!$block instanceof ElearningBlock) return;

      if ($block->getType() !== BlockType::QUIZ) {
        $block->setQuiz(null);
        return;
      }

      if (!$block->getQuiz()) {
        $quiz = new Quiz();
        $quiz->setCreateur($o['createur']);
        $quiz->setEntite($o['entite']);
        $block->setQuiz($quiz);
      }
    });
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ElearningBlock::class,
      'method' => 'POST',
      'allow_extra_fields' => true,
      'createur' => null,
      'entite' => null,
    ]);

    $r->setAllowedTypes('createur', ['null', Utilisateur::class]);
    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
