<?php

namespace App\Form\Administrateur;

use App\Entity\{ConventionContrat, Entreprise, Utilisateur, Session, Entite, Inscription};
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, DateType, TextType, IntegerType};
use Symfony\Component\Form\{FormBuilderInterface, FormEvent, FormEvents, FormInterface};
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConventionContratType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entite = $options['entite'];
        if (!$entite instanceof Entite) {
            throw new \InvalidArgumentException('Option "entite" obligatoire pour ConventionContratType.');
        }

        /** @var ConventionContrat|null $convention */
        $convention = $builder->getData();
        $sessionId = $convention?->getSession()?->getId();
        $entrepriseId = $convention?->getEntreprise()?->getId();
        $stagiaireId = $convention?->getStagiaire()?->getId();

        $builder
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => fn(Session $s) => sprintf('%s - %s', $s->getCode(), $s->getFormation()?->getTitre() ?? ''),
                'label' => 'Session',
                'placeholder' => 'Sélectionner une session',
                'disabled' => $options['lock_session'],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('s')
                    ->leftJoin('s.formation', 'f')->addSelect('f')
                    ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
                    ->orderBy('s.id', 'DESC'),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('entreprise', EntityType::class, [
                'class' => Entreprise::class,
                'choice_label' => 'raisonSociale',
                'label' => 'Entreprise destinataire',
                'placeholder' => '- Aucune -',
                'required' => false,
                'disabled' => $options['lock_entreprise'],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('e')
                    ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
                    ->orderBy('e.raisonSociale', 'ASC'),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('stagiaire', EntityType::class, [
                'class' => Utilisateur::class,
                'label' => 'Stagiaire destinataire',
                'choice_label' => fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom() . ' - ' . $u->getEmail()),
                'placeholder' => '- Aucun -',
                'required' => false,
                'disabled' => $options['lock_stagiaire'],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('u')
                    ->distinct()
                    ->leftJoin('u.utilisateurEntites', 'ue', Join::WITH, 'ue.entite = :entite')
                    ->leftJoin('u.inscriptions', 'i')
                    ->leftJoin('i.session', 's', Join::WITH, 's.entite = :entite')
                    ->andWhere('ue.id IS NOT NULL OR s.id IS NOT NULL')
                    ->setParameter('entite', $entite)
                    ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC'),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('intituleFormation', TextType::class, [
                'label' => 'Intitulé sur la convention',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => $convention?->getSession()?->getFormation()?->getTitre() ?? 'Intitulé de la formation'],
                'help' => 'Personnalisez le titre pour ce document. Vide : l’intitulé du catalogue est utilisé.',
            ])
            ->add('dureeFormation', TextType::class, [
                'label' => 'Durée sur la convention',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => $convention?->getDureeFormationEffective() ?? 'Ex. : 1 jour / 7 heures'],
                'help' => 'Ex. : 1 jour / 7 heures. Vide : la durée du catalogue est utilisée.',
            ])
            ->add('conditionsFinancieres', TextareaType::class, [
                'label' => 'Conditions financières',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 6],
                'help' => 'Modalités de règlement et échéancier figurant sur la convention.',
            ]);

        if (!$convention?->getStagiaire()) {
            $builder
                ->add('participantsLibres', TextareaType::class, [
                    'label' => 'Stagiaires sans compte ou sans e-mail',
                    'required' => false,
                    'attr' => ['rows' => 4, 'placeholder' => "Camille Durand\nAlex Martin"],
                    'help' => 'Un nom complet par ligne. Ces noms apparaissent dans la convention sans créer de compte ni d’inscription. Retirez la ligne lorsque vous rattachez l’inscription correspondante.',
                ])
                ->add('effectifPrevisionnel', IntegerType::class, [
                    'label' => 'Nombre total de stagiaires prévu',
                    'required' => false,
                    'attr' => ['min' => 1, 'placeholder' => 'Calculé à partir des stagiaires renseignés'],
                    'help' => 'Ce total inclut les inscriptions, les noms saisis et les stagiaires encore inconnus. Vous pouvez renseigner uniquement ce nombre. Vide : calcul automatique.',
                ]);
        }

        foreach (['dateSignatureStagiaire', 'dateSignatureEntreprise', 'dateSignatureOf'] as $field) {
            $builder->add($field, DateType::class, [
                'widget' => 'single_text', 'input' => 'datetime_immutable',
                'required' => false, 'disabled' => true,
                'attr' => ['class' => 'form-control'],
            ]);
        }

        $this->addInscriptionsField($builder, $entite, $sessionId, $entrepriseId, $stagiaireId);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use (
            $entite, $options, $sessionId, $entrepriseId, $stagiaireId
        ): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            // Les champs verrouillés conservent leur contexte, même en cas de requête falsifiée.
            $submittedId = static fn(mixed $value): ?int => is_scalar($value) && ctype_digit((string) $value)
                && (int) $value > 0 ? (int) $value : null;
            $this->addInscriptionsField(
                $event->getForm(),
                $entite,
                $options['lock_session'] ? $sessionId : $submittedId($data['session'] ?? null),
                $options['lock_entreprise'] ? $entrepriseId : $submittedId($data['entreprise'] ?? null),
                $options['lock_stagiaire'] ? $stagiaireId : $submittedId($data['stagiaire'] ?? null),
            );
        });
    }

    private function addInscriptionsField(
        FormBuilderInterface|FormInterface $form,
        Entite $entite,
        ?int $sessionId,
        ?int $entrepriseId,
        ?int $stagiaireId
    ): void {
        $form->add('inscriptions', EntityType::class, [
            'class' => Inscription::class,
            'label' => 'Stagiaires couverts par la convention',
            'multiple' => true,
            'required' => false,
            'by_reference' => false,
            'choice_label' => static function (Inscription $inscription): string {
                $u = $inscription->getStagiaire();
                return sprintf('#%d — %s (%s)', $inscription->getId(), trim($u?->getPrenom() . ' ' . $u?->getNom()), $u?->getEmail());
            },
            'query_builder' => static function (EntityRepository $er) use ($entite, $sessionId, $entrepriseId, $stagiaireId) {
                $qb = $er->createQueryBuilder('i')
                    ->innerJoin('i.session', 's')
                    ->leftJoin('i.stagiaire', 'u')->addSelect('u')
                    ->andWhere('s.entite = :entite')->andWhere('i.entite = :entite')
                    ->setParameter('entite', $entite)
                    ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
                if (!$sessionId || (!$entrepriseId && !$stagiaireId)) {
                    return $qb->andWhere('1 = 0');
                }
                $qb->andWhere('s.id = :session')->setParameter('session', $sessionId);
                if ($entrepriseId) {
                    $qb->andWhere('i.entreprise = :entreprise')->setParameter('entreprise', $entrepriseId);
                } else {
                    // Un devis adressé au stagiaire peut couvrir son inscription, même si son employeur est renseigné.
                    $qb->andWhere('i.stagiaire = :stagiaire')->setParameter('stagiaire', $stagiaireId);
                }
                return $qb;
            },
            'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélectionner les inscriptions'],
            'help' => 'Rattachez les inscriptions existantes de cette session. Pour une entreprise, vous pouvez compléter cette sélection plus tard.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConventionContrat::class,
            'entite' => null,
            'lock_session' => false,
            'lock_entreprise' => false,
            'lock_stagiaire' => false,
        ]);
        $resolver->setAllowedTypes('entite', [Entite::class, 'null']);
        foreach (['lock_session', 'lock_entreprise', 'lock_stagiaire'] as $option) {
            $resolver->setAllowedTypes($option, 'bool');
        }
    }
}
