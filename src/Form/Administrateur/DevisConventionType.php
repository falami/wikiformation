<?php

namespace App\Form\Administrateur;

use App\Entity\{Devis, Formation, Session, Site, Utilisateur};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, CollectionType, HiddenType, IntegerType, TextareaType, TextType};
use Symfony\Component\Form\{FormEvent, FormEvents, FormError};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
                    'choice_attr' => static fn(Formation $formation) => ['data-formation' => json_encode([
                        'title' => $formation->getTitre(),
                        'duration' => $formation->getDuree() ? $formation->getDuree() . ' jour' . ($formation->getDuree() > 1 ? 's' : '') : '',
                    ], JSON_THROW_ON_ERROR)],
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
                'group_by' => static fn(Session $s) => !$devis->getFormation() || $s->getFormation()?->getId() === $devis->getFormation()->getId() ? 'Formation du devis' : 'Autres formations de l’organisme',
                'placeholder' => 'Choisir une session',
                'attr' => ['class' => 'js-convention-select', 'data-entity' => 'session'],
                'choice_attr' => static function (Session $session) use ($devis): array {
                    $active = $session->getInscriptions()->filter(static fn($i) => $i->getStatus() !== \App\Enum\StatusInscription::ANNULE);
                    return ['data-session' => json_encode([
                        'code' => $session->getCode(),
                        'differentFormation' => $devis->getFormation() !== null && $session->getFormation()?->getId() !== $devis->getFormation()->getId(),
                        'title' => $session->getFormationLabel(),
                        'duration' => $session->getDureeFormationMinutes() > 0 ? rtrim(rtrim(number_format($session->getDureeFormationHeures(), 2, ',', ' '), '0'), ',') . ' heures' : '',
                        'startLabel' => $session->getDateDebut()?->format('d/m/Y à H:i'),
                        'endLabel' => $session->getDateFin()?->format('d/m/Y à H:i'),
                        'site' => $session->getSite()?->getNom(),
                        'capacity' => $session->getCapacite(),
                        'remaining' => max(0, $session->getCapacite() - $active->count()),
                        'enrolled' => array_values($active->map(static fn($i) => (string) $i->getStagiaire()?->getId())->toArray()),
                    ], JSON_THROW_ON_ERROR)];
                },
                'constraints' => [new Assert\NotNull(message: 'Choisissez une session.')],
                'query_builder' => static function (EntityRepository $r) use ($entite) {
                    return $r->createQueryBuilder('s')->innerJoin('s.formation', 'f')->addSelect('f')
                        ->innerJoin('s.site', 'site')->addSelect('site')
                        ->andWhere('s.entite = :e AND f.entite = :e AND site.entite = :e')->setParameter('e', $entite)
                        ->andWhere('s.status != :canceled')->setParameter('canceled', StatusSession::CANCELED)
                        ->orderBy('s.id', 'DESC');
                },
            ]);
            $builder->add('confirmerFormationDifferente', CheckboxType::class, [
                'label' => 'Je confirme le choix de cette formation différente de celle du devis.',
                'required' => false,
                'attr' => ['data-confirm-training' => ''],
            ]);
            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($devis): void {
                $data = $event->getData();
                $session = $data['session'] ?? null;
                if ($session instanceof Session && $devis->getFormation()
                    && $session->getFormation()?->getId() !== $devis->getFormation()->getId()
                    && empty($data['confirmerFormationDifferente'])) {
                    $event->getForm()->get('confirmerFormationDifferente')->addError(new FormError('La formation de cette session diffère de celle du devis. Confirmez ce choix ou sélectionnez une autre session.'));
                }
            });
        }

        if ($devis->getEntrepriseDestinataire()) {
            $builder->add('stagiaires', EntityType::class, [
                'class' => Utilisateur::class,
                'label' => 'Stagiaires couverts par cette convention',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr' => ['class' => 'js-convention-select', 'data-entity' => 'client', 'data-placeholder' => 'Rechercher un client par nom ou adresse e-mail…'],
                'choice_label' => static fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()) . ' — ' . $u->getEmail(),
                'choice_attr' => static fn(Utilisateur $u) => ['data-client' => json_encode([
                    'firstName' => $u->getPrenom(), 'lastName' => $u->getNom(), 'email' => $u->getEmail(),
                    'company' => $u->getEntreprise()?->getRaisonSociale(),
                ], JSON_THROW_ON_ERROR)],
                'query_builder' => fn(EntityRepository $r) => $r->createQueryBuilder('u')
                    ->leftJoin('u.utilisateurEntites', 'ue')
                    ->andWhere('(ue.entite = :e OR u.entite = :e)')->setParameter('e', $entite)
                    ->distinct()->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC'),
                'help' => 'Les inscriptions existantes seront réutilisées. Les stagiaires absents de la session seront inscrits automatiquement.',
            ])
                ->add('participantsLibres', TextareaType::class, [
                    'label' => 'Stagiaires sans fiche client ou sans e-mail',
                    'required' => false,
                    'constraints' => [new Assert\Length(max: 20000)],
                    'attr' => ['rows' => 3, 'data-free-participants' => '', 'placeholder' => "Camille Durand\nAlex Martin"],
                    'help' => 'Un nom complet par ligne. Ces noms apparaîtront sur la convention ; vous pourrez créer leurs fiches et rattacher leurs inscriptions plus tard.',
                ])
                ->add('effectifPrevisionnel', IntegerType::class, [
                    'label' => 'Nombre total de stagiaires prévu',
                    'required' => false,
                    'constraints' => [new Assert\Positive(message: 'Indiquez un effectif supérieur à zéro.'), new Assert\LessThanOrEqual(100000)],
                    'attr' => ['min' => 1, 'max' => 100000, 'data-planned-count' => '', 'placeholder' => 'Calculé à partir des noms renseignés'],
                    'help' => 'Inclut les clients sélectionnés, les noms saisis et les personnes encore inconnues. Renseignez seulement ce nombre si vous n’avez pas encore la liste.',
                ]);
        }

        $builder
            ->add('intituleFormation', TextType::class, [
                'label' => 'Intitulé de la formation sur la convention',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
                'attr' => ['maxlength' => 255, 'data-document-title' => '', 'placeholder' => 'Intitulé du catalogue si laissé vide'],
            ])
            ->add('dureeFormation', TextType::class, [
                'label' => 'Durée indiquée sur la convention',
                'required' => false,
                'help' => 'Laissez vide pour reprendre la durée de formation hors pauses calculée à partir du planning. Renseignez ce champ uniquement pour un libellé personnalisé.',
                'constraints' => [new Assert\Length(max: 255)],
                'attr' => ['maxlength' => 255, 'data-document-duration' => '', 'placeholder' => 'Calculée automatiquement à partir des créneaux'],
            ])
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
        $resolver->setDefaults([
            'create_session' => false,
            'constraints' => [new Assert\Callback(static function (mixed $data, ExecutionContextInterface $context): void {
                if (!is_array($data) || !array_key_exists('stagiaires', $data)) return;
                $names = preg_split('/\R/u', trim($data['participantsLibres'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $names = array_values(array_filter(array_map('trim', $names), static fn(string $name) => $name !== ''));
                $known = count($data['stagiaires'] ?? []) + count($names);
                $total = $data['effectifPrevisionnel'] ?? $known;
                if ($total < 1) {
                    $context->buildViolation('Sélectionnez un client, saisissez un nom ou indiquez le nombre de stagiaires prévu.')
                        ->atPath('[effectifPrevisionnel]')->addViolation();
                } elseif ($total < $known) {
                    $context->buildViolation('L’effectif total ne peut pas être inférieur au nombre de clients sélectionnés et de noms saisis.')
                        ->atPath('[effectifPrevisionnel]')->addViolation();
                }
            })],
        ]);
        $resolver->setAllowedTypes('create_session', 'bool');
    }
}
