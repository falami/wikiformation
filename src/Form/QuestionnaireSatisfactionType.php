<?php
// src/Form/QuestionnaireSatisfactionType.php
namespace App\Form;

use App\Entity\QuestionnaireSatisfaction;
use App\Entity\Session;
use App\Entity\Inscription;
use App\Entity\Utilisateur;
use App\Enum\SatisfactionType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class QuestionnaireSatisfactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        /** @var QuestionnaireSatisfaction|null $qs */
        $qs = $o['data'] ?? null;

        $b
            // =====================
            // Session
            // =====================
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => 'code',
                'required' => false,
                'label' => 'Session',
                'placeholder' => '- Sélectionner une session -',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            // =====================
            // Inscription
            // =====================
            ->add('inscription', EntityType::class, [
                'class' => Inscription::class,
                'choice_label' => fn(Inscription $i) => 'Inscription #' . $i->getId(),
                'required' => false,
                'label' => 'Inscription',
                'placeholder' => '- Sélectionner une inscription -',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            // =====================
            // Stagiaire
            // =====================
            ->add('stagiaire', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => fn(Utilisateur $u) => $u->getPrenom() . ' ' . $u->getNom(),
                'required' => false,
                'label' => 'Stagiaire',
                'placeholder' => '- Sélectionner un stagiaire -',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            // =====================
            // Type (A_CHAUD / A_FROID)
            // =====================
            ->add('type', EnumType::class, [
                'class' => SatisfactionType::class,
                'label' => 'Type de questionnaire',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            // =====================
            // Note globale
            // =====================
            ->add('noteGlobale', IntegerType::class, [
                'label' => 'Note globale',
                'required' => false,
                'help' => 'Optionnel - calculée automatiquement si vide.',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 20,
                    'placeholder' => 'Ex : 15',
                ],
            ])

            // =====================
            // Réponses (JSON)
            // =====================
            ->add('reponses', TextareaType::class, [
                'label' => 'Réponses (JSON)',
                'mapped' => false,              // ✅ IMPORTANT
                'required' => false,
                'help' => 'Format JSON - ex : {"12":5,"13":"Très bon formateur","14":[1,2]}',
                'attr' => [
                    'class' => 'form-control font-monospace',
                    'rows' => 10,
                    'placeholder' => '{"12":5,"13":"Très bon formateur","14":[1,2]}',
                ],
                // Pré-remplissage lisible si édition
                'data' => json_encode(
                    $qs?->getReponses() ?? [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                ),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => QuestionnaireSatisfaction::class,
        ]);
    }
}
