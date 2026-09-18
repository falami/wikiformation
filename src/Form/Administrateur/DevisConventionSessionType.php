<?php

namespace App\Form\Administrateur;

use App\Entity\{Devis, Formation, SessionJour, Site};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CollectionType, HiddenType, IntegerType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class DevisConventionSessionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Devis $devis */
        $devis = $options['devis'];
        $entite = $devis->getEntite();
        $builder
            ->add('formation', EntityType::class, [
                'label' => 'Formation', 'class' => Formation::class, 'choice_label' => 'titre',
                'placeholder' => 'Sélectionnez une formation', 'disabled' => $devis->getFormation() !== null,
                'constraints' => [new Assert\NotNull(message: 'Choisissez une formation.')],
                'attr' => ['class' => 'js-convention-select'],
                'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('f')->andWhere('f.entite = :e')->setParameter('e', $entite)->orderBy('f.titre', 'ASC'),
            ])
            ->add('site', EntityType::class, [
                'label' => 'Lieu de formation', 'class' => Site::class, 'choice_label' => 'nom',
                'placeholder' => 'Sélectionnez un site', 'constraints' => [new Assert\NotNull(message: 'Choisissez un site.')],
                'attr' => ['class' => 'js-convention-select', 'data-quick-target' => 'site', 'data-entity' => 'site'],
                'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('s')->andWhere('s.entite = :e')->setParameter('e', $entite)->orderBy('s.nom', 'ASC'),
            ])
            ->add('capacite', IntegerType::class, [
                'label' => 'Nombre de places', 'attr' => ['min' => 1],
                'constraints' => [new Assert\NotNull(), new Assert\Positive(), new Assert\LessThanOrEqual(100000)],
            ])
            ->add('jours', CollectionType::class, [
                'label' => false, 'entry_type' => DevisSessionJourType::class, 'entry_options' => ['label' => false],
                'allow_add' => true, 'allow_delete' => true,
                'constraints' => [new Assert\Count(min: 1, max: 365, minMessage: 'Ajoutez au moins un créneau.'), new Assert\Valid(), new Assert\Callback(static function (mixed $jours, ExecutionContextInterface $context): void {
                    $slots = $jours instanceof \Traversable ? iterator_to_array($jours) : (array) $jours;
                    usort($slots, static fn(SessionJour $a, SessionJour $b) => $a->getDateDebut() <=> $b->getDateDebut());
                    $end = null;
                    foreach ($slots as $slot) {
                        if ($end && $slot->getDateDebut() && $slot->getDateDebut() < $end) {
                            $context->buildViolation('Les créneaux ne doivent pas se chevaucher.')->addViolation();
                            return;
                        }
                        $end = $slot->getDateFin();
                    }
                })],
            ])
            ->add('operation', HiddenType::class, ['constraints' => [new Assert\NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('devis');
        $resolver->setAllowedTypes('devis', Devis::class);
    }
}
