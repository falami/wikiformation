<?php
// src/Form/Administrateur/QuizQuestionType.php
namespace App\Form\Administrateur;

use App\Entity\QuizQuestion;
use App\Enum\QuestionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{EnumType, IntegerType, TextareaType, TextType, CollectionType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class QuizQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b->add('type', EnumType::class, [
            'class' => QuestionType::class,
            'label' => 'Type',
            'choice_label' => fn(QuestionType $t) => match ($t) {
                QuestionType::SINGLE => 'Choix unique',
                QuestionType::MULTIPLE => 'Choix multiples',
                QuestionType::BOOLEAN => 'Vrai/Faux',
                QuestionType::TEXT => 'Réponse libre',
            },
            'attr' => ['class' => 'form-select']
        ])
            ->add('text', TextareaType::class, [
                'label' => 'Question',
                'required'   => false,   // tolère vide pendant autosave
                'empty_data' => '',
                'attr' => ['rows' => 2, 'class' => 'form-control']
            ])
            ->add('position', HiddenType::class, [
                'required' => false,
            ])

            ->add('explanation', TextareaType::class, [
                'label' => 'Explication (facultatif)',
                'required' => false,
                'attr' => ['rows' => 2, 'class' => 'form-control']
            ])
            ->add('expectedText', TextType::class, [
                'label' => 'Réponse attendue (si “réponse libre”)',
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('options', CollectionType::class, [
                'entry_type' => QcmOptionType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__o__',   // ✅ IMPORTANT
                'required' => false,
            ])

        ;

        // cacher choices pour TEXT ; pour BOOLEAN on pré-remplira deux choix
        $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) {
            /** @var QuizQuestion|null $q */
            $q = $e->getData();
            $form = $e->getForm();
            $type = $q?->getType();

            if ($type === QuestionType::TEXT) {
                $form->remove('choices');
            }
        });



        // ✅ SUPPRIMER ce bloc COMPLETEMENT
        // $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) {
        //     $q = $e->getData();
        //     $form = $e->getForm();
        //     if ($q?->getType() === QuestionType::TEXT) {
        //         $form->remove('choices');
        //     }
        // });

        $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) {
            $data = $e->getData() ?? [];
            $typeValue = $data['type'] ?? null;

            if ($typeValue === QuestionType::TEXT->value) {
                $data['choices'] = [];
                $e->setData($data);
                return;
            }

            if (isset($data['choices']) && is_array($data['choices'])) {
                $clean = [];
                foreach ($data['choices'] as $ch) {
                    if (!is_array($ch)) continue;
                    $label = trim((string)($ch['label'] ?? ''));
                    $isCorrect = !empty($ch['isCorrect']);
                    if ($label !== '' || $isCorrect) $clean[] = $ch;
                }
                $data['choices'] = array_values($clean);
                $e->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => QuizQuestion::class,
            'allow_extra_fields' => true,
        ]);
    }
}
