<?php

declare(strict_types=1);

namespace App\Form\Formateur;

use App\Entity\PositioningAttempt;
use App\Enum\SuggestedLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositioningConclusionType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $options): void
  {
    $b
      ->add('suggestedLevel', EnumType::class, [
        'class' => SuggestedLevel::class,
        'required' => false,
        'placeholder' => '- Non défini -',
        'label' => 'Niveau suggéré',
        'help' => 'Choisis le niveau global recommandé pour le stagiaire.',
        'choice_label' => static function ($case): string {
          // ✅ Supporte UnitEnum + BackedEnum
          if (method_exists($case, 'label')) {
            return $case->label();
          }
          // fallback : "INITIAL" -> "Initial"
          $name = $case->name ?? (string)$case;
          $name = strtolower(str_replace('_', ' ', $name));
          return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        },
        'attr' => [
          'class' => 'form-select',
        ],
      ])
      ->add('formateurConclusion', TextareaType::class, [
        'required' => false,
        'label' => 'Conclusion',
        'help' => 'Points forts, points à travailler, recommandation (ex: parcours conseillé).',
        'attr' => [
          'class' => 'form-control',
          'rows' => 7,
          'placeholder' => "Ex:\n- Bon socle sur les bases\n- Manque d’aisance sur ...\n- Recommandation: ...",
        ],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => PositioningAttempt::class,
      // ✅ CSRF id sera défini depuis le controller (csrf_token_id)
      'csrf_protection' => true,
    ]);
  }
}
