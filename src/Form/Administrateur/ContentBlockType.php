<?php
// src/Form/Administrateur/ContentBlockType.php
declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\{ContentBlock, Utilisateur, Entite};
use App\Enum\BlockType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    CheckboxType,
    EnumType,
    FileType,
    IntegerType,
    TextareaType,
    TextType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use App\Form\Administrateur\QuizType;
use App\Entity\Quiz;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class ContentBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('type', EnumType::class, [
                'class'       => BlockType::class,
                'label'       => 'Type de bloc',
                'placeholder' => '- Sélectionner -',
                'attr'        => ['class' => 'form-select'],
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
                'help' => "Pour une vidéo, utilisez plutôt « URL vidéo ». Pour quiz/checklist, utilisez le champ JSON ci-dessous.",
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
                'help'     => 'JPG/PNG pour une image, PDF/ZIP pour un fichier.',
            ])
            ->add('position', HiddenType::class, ['empty_data' => '0'])
            ->add('isRequired', CheckboxType::class, [
                'label'      => 'Obligatoire',
                'required'   => false,
                'label_attr' => ['class' => 'form-check-label'],
                'row_attr'   => ['class' => 'form-check form-switch'],
                'attr'       => ['class' => 'form-check-input'],
            ]);

        // ✅ Toujours ajouter quiz (sinon ton Twig/JS ne peut pas fonctionner)
        $b->add('quiz', QuizType::class, [
            'label' => false,
            'required' => false,
        ]);

        // ✅ S'assurer d'avoir un objet Quiz pour rendre le sous-form (sinon data_class Quiz + null => soucis)
        $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) use ($o) {
            /** @var ContentBlock|null $block */
            $block = $e->getData();
            if (!$block) return;

            if ($block->getType() === BlockType::QUIZ && !$block->getQuiz()) {
                $quiz = new Quiz();
                $quiz->setCreateur($o['createur']);
                $quiz->setEntite($o['entite']);
                $block->setQuiz($quiz);
            }
        });


        // ✅ Nettoyage côté submit : si ce n’est pas un quiz, on ignore quiz et on laisse le contrôleur faire setQuiz(null)
        $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) {
            $data = $e->getData();
            $typeValue = strtoupper((string)($data['type'] ?? ''));

            $isQuiz = str_contains($typeValue, 'QUIZ');

            if (!$isQuiz) {
                // on vire tout ce qui pourrait polluer
                unset($data['quiz']);
                $e->setData($data);
                return;
            }

            // si quiz : on peut virer les champs non utiles (optionnel)
            unset($data['content'], $data['mediaUrl'], $data['upload']);
            $e->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => ContentBlock::class,
            'method' => 'POST', // force POST
            'allow_extra_fields' => true,
            'createur' => null,
            'entite' => null,
        ]);

        $r->setAllowedTypes('createur', ['null', Utilisateur::class]);
        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
