<?php

namespace App\Form\Automation;

use App\Entity\{Entite, Entreprise, Formation, Formateur, Qcm, Site};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, EmailType, IntegerType, NumberType, TextType};
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class QuickWorkflowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $entity = fn(string $class, string $label, callable $choiceLabel) => ['class' => $class, 'label' => $label, 'placeholder' => 'Sélectionner…', 'constraints' => [new Assert\NotNull()], 'choice_label' => $choiceLabel, 'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('x')->where('x.entite = :e')->setParameter('e', $o['entite']), 'attr' => ['class' => 'form-select js-workflow-select']];
        $b->add('formation', EntityType::class, $entity(Formation::class, 'Formation', fn($v) => $v->getTitre()))
            ->add('entreprise', EntityType::class, $entity(Entreprise::class, 'Entreprise cliente', fn($v) => $v->getRaisonSociale()))
            ->add('formateur', EntityType::class, $entity(Formateur::class, 'Formateur référent', fn($v) => trim($v->getUtilisateur()?->getPrenom() . ' ' . $v->getUtilisateur()?->getNom())))
            ->add('site', EntityType::class, $entity(Site::class, 'Lieu de formation', fn($v) => $v->getNom()))
            ->add('postQcm', EntityType::class, ['class' => Qcm::class, 'label' => 'Évaluation de fin de formation', 'required' => false,
                'placeholder' => 'Choisir un QCM adapté — facultatif', 'choice_label' => 'titre',
                'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('q')->where('q.entite = :e')->andWhere('q.isActive = true')->setParameter('e', $o['entite'])->orderBy('q.titre', 'ASC'),
                'help' => 'Le QCM choisi sera affecté automatiquement aux participants. Sans choix, l’étape attendra votre validation.',
                'attr' => ['class' => 'form-select js-workflow-select']])
            ->add('dates', TextType::class, ['label' => 'Dates de formation', 'help' => 'Sélectionnez une ou plusieurs journées.', 'attr' => ['class' => 'form-control js-workflow-dates', 'placeholder' => 'Choisir les dates'], 'constraints' => [new Assert\NotBlank()]])
            ->add('startTime', TextType::class, ['label' => 'Heure de début', 'data' => '08:30', 'constraints' => [new Assert\NotBlank()], 'attr' => ['class' => 'form-control js-workflow-time']])
            ->add('hours', NumberType::class, ['label' => 'Heures de cours par jour', 'data' => 7, 'scale' => 2, 'help' => '90 min de pause ajoutées au-delà de 4 h. 08:30 + 7 h de cours = 17:00.', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 12)], 'attr' => ['class' => 'form-control']])
            ->add('price', NumberType::class, ['label' => 'Prix total du groupe HT (€)', 'scale' => 2, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 1000000)], 'attr' => ['class' => 'form-control', 'placeholder' => '1 100,00']])
            ->add('vat', ChoiceType::class, ['label' => 'TVA', 'choices' => ['20 %' => 20, '10 %' => 10, '5,5 %' => 5.5, '0 % — vérifier le motif d’exonération' => 0], 'attr' => ['class' => 'form-select js-workflow-select']])
            ->add('participants', IntegerType::class, ['label' => 'Nombre de stagiaires', 'data' => 8, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 1000)], 'attr' => ['class' => 'form-control']])
            ->add('contactEmail', EmailType::class, ['label' => 'Email du contact entreprise', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)], 'attr' => ['class' => 'form-control']])
            ->add('validityMonths', ChoiceType::class, ['label' => 'Échéance de recyclage', 'required' => false, 'placeholder' => 'Sans échéance', 'choices' => ['12 mois' => 12, '24 mois — SST salarié, à confirmer' => 24, '36 mois' => 36, '60 mois' => 60], 'help' => 'Calculée uniquement après un certificat métier déposé et validé. Les rappels sont activables séparément.', 'attr' => ['class' => 'form-select js-workflow-select']]);
    }
    public function configureOptions(OptionsResolver $r): void { $r->setRequired('entite')->setAllowedTypes('entite', Entite::class); }
}
