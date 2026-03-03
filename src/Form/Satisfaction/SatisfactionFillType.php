<?php
// src/Form/Satisfaction/SatisfactionFillType.php
namespace App\Form\Satisfaction;

use App\Entity\Formation;
use App\Entity\SatisfactionTemplate;
use App\Enum\SatisfactionQuestionType as QType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SatisfactionFillType extends AbstractType
{
  public function __construct(private EntityManagerInterface $em) {}

  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var SatisfactionTemplate $template */
    $template = $o['template'];

    foreach ($template->getChapters() as $chapter) {
      foreach ($chapter->getQuestions() as $q) {
        $qid = $q->getId();
        if (!$qid) continue;

        $name     = 'q_' . $qid;
        $label    = $q->getLibelle();
        $required = $q->isRequired();
        $help     = $q->getHelp();
        $ph       = $q->getPlaceholder();

        switch ($q->getType()) {

          case QType::SCALE:
            // ✅ Toujours 0..10
            $choices = [];
            for ($i = 0; $i <= 10; $i++) $choices[(string)$i] = $i;

            $b->add($name, ChoiceType::class, [
              'label' => $label,
              'required' => $required,
              'choices' => $choices,
              'expanded' => true,
              'multiple' => false,
              'help' => $help,
              'attr' => ['class' => 'js-scale-0-10'],
            ]);
            break;

          case QType::YES_NO:
            $b->add($name, ChoiceType::class, [
              'label' => $label,
              'required' => $required,
              'choices' => ['Oui' => 1, 'Non' => 0],
              'expanded' => true,
              'multiple' => false,
              'help' => $help,
              // IMPORTANT : pas de form-select ici (expanded => fieldset)
              'attr' => ['class' => 'js-yesno-radios'],
            ]);
            break;


          case QType::TEXT:
            $b->add($name, TextType::class, [
              'label' => $label,
              'required' => $required,
              'help' => $help,
              'attr' => ['class' => 'form-control', 'placeholder' => $ph ?? 'Votre réponse…'],
            ]);
            break;

          case QType::TEXTAREA:
            $b->add($name, TextareaType::class, [
              'label' => $label,
              'required' => $required,
              'help' => $help,
              'attr' => [
                'class' => 'form-control',
                'rows' => 4,
                'placeholder' => $ph ?? 'Votre réponse…'
              ],
            ]);
            break;

          case QType::CHOICE:
          case QType::MULTICHOICE:
            $rawChoices = $q->getChoices() ?? [];
            $choices = [];

            foreach ($rawChoices as $c) {
              if (is_string($c) && trim($c) !== '') {
                $choices[$c] = $c;
              } elseif (is_array($c)) {
                $lab = (string)($c['label'] ?? $c['value'] ?? '');
                $val = (string)($c['value'] ?? $c['label'] ?? '');
                if ($lab !== '' && $val !== '') $choices[$lab] = $val;
              }
            }

            if (!$choices) $choices = ['(Aucun choix défini)' => ''];

            $isMulti = $q->getType() === QType::MULTICHOICE;

            $b->add($name, ChoiceType::class, [
              'label' => $label,
              'required' => $required,
              'choices' => $choices,
              'expanded' => false,
              'multiple' => $isMulti,
              'help' => $help,
              'attr' => ['class' => 'form-select ' . ($isMulti ? 'js-tomselect' : '')],
            ]);
            break;

          case QType::MULTI_FORMATIONS:
            $b->add($name, EntityType::class, [
              'class' => Formation::class,
              'choice_label' => 'titre',
              'multiple' => true,
              'expanded' => false,
              'required' => false,
              'label' => $label,
              'help' => $help ?? 'Sélectionnez une ou plusieurs formations.',
              'query_builder' => function ($repo) use ($o) {
                $qb = $repo->createQueryBuilder('f')->orderBy('f.titre', 'ASC');
                if (!empty($o['entite_id'])) {
                  $qb->andWhere('f.entite = :e')->setParameter('e', $o['entite_id']);
                }
                if (!empty($o['only_public'])) {
                  $qb->andWhere('f.isPublic = 1');
                }
                return $qb;
              },
              'attr' => ['class' => 'js-tomselect'],
            ]);
            break;

          default:
            break;
        }
      }
    }
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'csrf_protection' => true,
      'template' => null,
      'entite_id' => null,
      'only_public' => false,
    ]);
    $r->setRequired(['template']);
    $r->setAllowedTypes('template', SatisfactionTemplate::class);
  }
}
