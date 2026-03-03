<?php
// src/Form/Administrateur/QuizChoiceType.php
namespace App\Form\Administrateur;

use App\Entity\QuizChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, IntegerType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class QuizChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('label', TextareaType::class, [
                'label' => 'Réponse',
                'required'   => false,              // on tolère vide pendant l’autosave
                'empty_data' => '',
                'attr' => ['rows' => 2, 'class' => 'form-control']
            ])
            ->add('isCorrect', CheckboxType::class, [
                'label' => 'Bonne réponse',
                'required' => false,
                'row_attr' => ['class' => 'form-check form-switch'],
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
            ])

            ->add('position', HiddenType::class, [
                'required' => false,
            ]);
    }
    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => QuizChoice::class,
            'allow_extra_fields' => true,
            'empty_data' => static fn() => new QuizChoice(),
        ]);
    }
}
