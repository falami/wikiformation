<?php

namespace App\Form\Administrateur;

use App\Entity\{Devis, Formation, Session, Site, Utilisateur};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CollectionType, HiddenType, IntegerType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class DevisConventionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Devis $devis */
        $devis = $options['devis'];
        $entite = $devis->getEntite();

        if ($options['create_session']) {
            $builder
                ->add('formation', EntityType::class, [
                    'class' => Formation::class,
                    'choice_label' => 'titre',
                    'placeholder' => 'Choisir la formation',
                    'attr' => ['class' => 'js-convention-select', 'data-entity' => 'formation'],
                    'disabled' => $devis->getFormation() !== null,
                    'constraints' => [new Assert\NotNull(message: 'Choisissez une formation.')],
                    'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('f')
                        ->andWhere('f.entite = :e')->setParameter('e', $entite)->orderBy('f.titre', 'ASC'),
                ])
                ->add('site', EntityType::class, [
                    'class' => Site::class,
                    'choice_label' => 'nom',
                    'placeholder' => 'Choisir le lieu de formation',
                    'attr' => ['class' => 'js-convention-select', 'data-entity' => 'site'],
                    'constraints' => [new Assert\NotNull(message: 'Choisissez un lieu de formation.')],
                    'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('s')
                        ->andWhere('s.entite = :e')->setParameter('e', $entite)->orderBy('s.nom', 'ASC'),
                ])
                ->add('capacite', IntegerType::class, [
                    'label' => 'Capacité de la session',
                    'constraints' => [new Assert\NotNull(), new Assert\Positive()],
                    'attr' => ['min' => 1],
                ])
                ->add('jours', CollectionType::class, [
                    'label' => false,
                    'entry_type' => DevisSessionJourType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'constraints' => [new Assert\Count(min: 1, minMessage: 'Ajoutez au moins un créneau de formation.'), new Assert\Valid()],
                ]);
        } else {
            $builder->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => static fn(Session $s) => sprintf('%s — %s (%s)', $s->getCode(), $s->getFormationLabel(), $s->getDateDebut()?->format('d/m/Y') ?? 'dates à définir'),
                'placeholder' => 'Choisir une session',
                'attr' => ['class' => 'js-convention-select', 'data-entity' => 'session'],
                'choice_attr' => static function (Session $session): array {
                    $active = $session->getInscriptions()->filter(static fn($i) => $i->getStatus() !== \App\Enum\StatusInscription::ANNULE);
                    return ['data-session' => json_encode([
                        'code' => $session->getCode(),
                        'title' => $session->getFormationLabel(),
                        'startLabel' => $session->getDateDebut()?->format('d/m/Y à H:i'),
                        'endLabel' => $session->getDateFin()?->format('d/m/Y à H:i'),
                        'site' => $session->getSite()?->getNom(),
                        'capacity' => $session->getCapacite(),
                        'remaining' => max(0, $session->getCapacite() - $active->count()),
                        'enrolled' => array_values($active->map(static fn($i) => (string) $i->getStagiaire()?->getId())->toArray()),
                    ], JSON_THROW_ON_ERROR)];
                },
                'constraints' => [new Assert\NotNull(message: 'Choisissez une session.')],
                'query_builder' => static function (EntityRepository $r) use ($entite, $devis) {
                    $qb = $r->createQueryBuilder('s')->leftJoin('s.formation', 'f')->addSelect('f')
                        ->andWhere('s.entite = :e')->setParameter('e', $entite)
                        ->andWhere('s.status != :canceled')->setParameter('canceled', StatusSession::CANCELED)
                        ->orderBy('s.id', 'DESC');
                    if ($devis->getFormation()) {
                        $qb->andWhere('s.formation = :f')->setParameter('f', $devis->getFormation());
                    }
                    return $qb;
                },
            ]);
        }

        if ($devis->getEntrepriseDestinataire()) {
            $builder->add('stagiaires', EntityType::class, [
                'class' => Utilisateur::class,
                'label' => 'Stagiaires couverts par cette convention',
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'js-convention-select', 'data-entity' => 'client', 'data-placeholder' => 'Rechercher un client par nom ou adresse e-mail…'],
                'choice_label' => static fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()) . ' — ' . $u->getEmail(),
                'choice_attr' => static fn(Utilisateur $u) => ['data-client' => json_encode([
                    'firstName' => $u->getPrenom(), 'lastName' => $u->getNom(), 'email' => $u->getEmail(),
                    'company' => $u->getEntreprise()?->getRaisonSociale(),
                ], JSON_THROW_ON_ERROR)],
                'constraints' => [new Assert\Count(min: 1, minMessage: 'Sélectionnez au moins un stagiaire.')],
                'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('u')
                    ->leftJoin('u.utilisateurEntites', 'ue')
                    ->andWhere('(ue.entite = :e OR u.entite = :e)')->setParameter('e', $entite)
                    ->distinct()->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC'),
                'help' => 'Les inscriptions existantes seront réutilisées. Les stagiaires absents de la session seront inscrits automatiquement.',
            ]);
        }

        $builder
            ->add('conditionsFinancieres', TextareaType::class, [
                'label' => 'Conditions financières',
                'required' => false,
                'attr' => ['rows' => 5],
                'help' => 'Précisez les modalités de règlement ou de prise en charge. Les montants et la référence du devis figurent sur la convention.',
            ])
            ->add('operation', HiddenType::class, ['constraints' => [new Assert\NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('devis');
        $resolver->setAllowedTypes('devis', Devis::class);
        $resolver->setDefaults(['create_session' => false]);
        $resolver->setAllowedTypes('create_session', 'bool');
    }
}
