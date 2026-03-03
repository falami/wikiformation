<?php

namespace App\Form\Administrateur;

use App\Entity\ConventionContrat;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Entity\Session;
use App\Entity\Entite;
use App\Entity\Inscription;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, DateType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConventionContratType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $options): void
    {
        /** @var Entite|null $entite */
        $entite = $options['entite'];

        if (!$entite instanceof Entite) {
            throw new \InvalidArgumentException('Option "entite" obligatoire pour ConventionContratType.');
        }

        /** @var ConventionContrat|null $cc */
        $cc = $b->getData();
        $currentStagiaire  = $cc?->getStagiaire();
        $currentEntreprise = $cc?->getEntreprise();
        $currentSession    = $cc?->getSession();

        // ✅ Build champs "base"
        $b
            ->add('session', EntityType::class, [
                'class' => Session::class,
                'choice_label' => fn(Session $s) => sprintf('%s - %s', $s->getCode(), $s->getFormation()?->getTitre() ?? ''),
                'label' => '*Session',
                'placeholder' => 'Sélectionner une session',
                'disabled' => (bool) $options['lock_session'],
                'query_builder' => function (EntityRepository $er) use ($entite) {
                    return $er->createQueryBuilder('s')
                        ->leftJoin('s.formation', 'f')->addSelect('f')
                        ->andWhere('s.entite = :e')
                        ->setParameter('e', $entite)
                        ->orderBy('s.id', 'DESC');
                },
                'attr' => ['class' => 'form-select'],
            ])

            ->add('entreprise', EntityType::class, [
                'class' => Entreprise::class,
                'choice_label' => 'raisonSociale',
                'label' => 'Entreprise',
                'placeholder' => '- Aucune (financement individuel) -',
                'required' => false,
                'disabled' => (bool) $options['lock_entreprise'],
                'query_builder' => function (EntityRepository $er) use ($entite, $currentEntreprise) {
                    $qb = $er->createQueryBuilder('e')
                        ->andWhere('e.entite = :entite')
                        ->setParameter('entite', $entite)
                        ->orderBy('e.raisonSociale', 'ASC');

                    // ✅ inclure l’entreprise déjà sélectionnée
                    if ($currentEntreprise) {
                        $qb->orWhere('e.id = :curE')
                            ->setParameter('curE', $currentEntreprise->getId());
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])

            ->add('stagiaire', EntityType::class, [
                'class' => Utilisateur::class,
                'label' => 'Stagiaire',
                'choice_label' => fn(Utilisateur $u) => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '') . ' - ' . ($u->getEmail() ?? '')),
                'placeholder' => '- Aucun (financement entreprise) -',
                'required' => false,
                'disabled' => (bool) $options['lock_stagiaire'],
                'query_builder' => function (EntityRepository $er) use ($entite, $currentStagiaire) {
                    $qb = $er->createQueryBuilder('u');

                    $qb->leftJoin('u.utilisateurEntites', 'ue', Join::WITH, 'ue.entite = :entite')
                        ->setParameter('entite', $entite)
                        ->andWhere('ue.id IS NOT NULL');

                    if ($currentStagiaire) {
                        $qb->orWhere('u.id = :curU')
                            ->setParameter('curU', $currentStagiaire->getId());
                    }

                    return $qb
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])

            // dates (disabled)
            ->add('dateSignatureStagiaire', DateType::class, [
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'required' => false,
                'disabled' => true,
                'attr' => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
            ])
            ->add('dateSignatureEntreprise', DateType::class, [
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'required' => false,
                'disabled' => true,
                'attr' => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
            ])
            ->add('dateSignatureOf', DateType::class, [
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'required' => false,
                'disabled' => true,
                'attr' => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
            ])

            ->add('conditionsFinancieres', TextareaType::class, [
                'label' => 'Conditions financières',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'Modalités de règlement, échéancier, OPCO, etc.',
                ],
                'help' => 'Texte libre figurant sur la convention.',
            ])
        ;

        /**
         * ✅ Champ inscriptions (multi) : on l’ajoute dynamiquement en fonction (session+entreprise)
         * - PRE_SET_DATA : affichage initial (édition)
         * - PRE_SUBMIT : validation submit (évite "This value is not valid")
         */
        $this->addInscriptionsField(
            $b,
            $entite,
            $currentSession,
            $currentEntreprise
        );

        $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($entite) {
            /** @var ConventionContrat|null $cc */
            $cc = $event->getData();
            if (!$cc) return;

            $this->addInscriptionsField(
                $event->getForm(),
                $entite,
                $cc->getSession(),
                $cc->getEntreprise()
            );
        });

        // ✅ Exclusivité + restauration entreprise/stagiaire + robustesse inscriptions
        $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($entite, $currentStagiaire, $currentEntreprise, $currentSession, $cc) {
            $data = $event->getData() ?? [];

            $entreprise = $data['entreprise'] ?? null;
            $stagiaire  = $data['stagiaire'] ?? null;

            $entrepriseEmpty = !is_string($entreprise) || trim($entreprise) === '';
            $stagiaireEmpty  = !is_string($stagiaire)  || trim($stagiaire)  === '';

            // ✅ 1) Restaurer si TomSelect envoie vide alors qu’une valeur existait déjà
            if ($stagiaireEmpty && $currentStagiaire) {
                $data['stagiaire'] = (string) $currentStagiaire->getId();
                $stagiaireEmpty = false;
            }
            if ($entrepriseEmpty && $currentEntreprise) {
                $data['entreprise'] = (string) $currentEntreprise->getId();
                $entrepriseEmpty = false;
            }

            // ✅ 2) Exclusivité entreprise / stagiaire (priorité entreprise)
            $hasEntreprise = !$entrepriseEmpty;
            $hasStagiaire  = !$stagiaireEmpty;

            if ($hasEntreprise) {
                $data['stagiaire'] = null;
            } elseif ($hasStagiaire) {
                $data['entreprise'] = null;
            }

            // ✅ 3) Inscriptions : sécurité
            // - si on est en individuel => on ignore/vidange inscriptions
            // - si entreprise => on garde celles envoyées, ou on restaure si champ absent
            if (!$hasEntreprise) {
                $data['inscriptions'] = []; // convention individuelle => pas de liste
            } else {
                // entreprise = ON
                if (!array_key_exists('inscriptions', $data)) {
                    // champ pas soumis (pas rendu / JS) => on restaure l’existant pour éviter de perdre les liens
                    $existingIds = [];
                    if ($cc) {
                        foreach ($cc->getInscriptions() as $i) {
                            $existingIds[] = (string) $i->getId();
                        }
                    }
                    $data['inscriptions'] = $existingIds;
                } else {
                    // normaliser array (TomSelect renvoie souvent array de strings)
                    $data['inscriptions'] = array_values(array_filter((array) $data['inscriptions'], static function ($v) {
                        return is_string($v) && trim($v) !== '';
                    }));
                }
            }

            // ✅ 4) Ré-injecter le champ inscriptions avec un QB cohérent (session + entreprise)
            // pour que Symfony accepte les valeurs soumises.
            // Session soumise peut être '' => on fallback sur currentSession
            $sessionId = $data['session'] ?? null;
            $session   = null;

            if (is_string($sessionId) && trim($sessionId) !== '') {
                // on ne requête pas en DB ici : on filtre par id dans le QB
                $session = (int) $sessionId;
            } elseif ($currentSession) {
                $session = $currentSession->getId();
            }

            $entrepriseId = null;
            if (is_string($data['entreprise'] ?? null) && trim((string)$data['entreprise']) !== '') {
                $entrepriseId = (int) $data['entreprise'];
            } elseif ($currentEntreprise) {
                $entrepriseId = $currentEntreprise->getId();
            }

            $this->addInscriptionsFieldForSubmit(
                $event->getForm(),
                $entite,
                $session,
                $entrepriseId
            );

            $event->setData($data);
        });
    }

    /**
     * Champ inscriptions pour affichage (avec objets Session/Entreprise)
     */
    private function addInscriptionsField(
        FormBuilderInterface|FormInterface $form,
        Entite $entite,
        ?Session $session,
        ?Entreprise $entreprise
    ): void {
        $form->add('inscriptions', EntityType::class, [
            'class' => Inscription::class,
            'label' => 'Stagiaires (inscriptions)',
            'multiple' => true,
            'required' => false,
            'by_reference' => false, // IMPORTANT ManyToMany
            'placeholder' => '',
            'choice_label' => function (Inscription $i) {
                $u = $i->getStagiaire();
                $name = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';
                $mail = $u?->getEmail() ?? '';
                return sprintf('#%d — %s%s', $i->getId(), $name, $mail ? ' (' . $mail . ')' : '');
            },
            'query_builder' => function (EntityRepository $er) use ($entite, $session, $entreprise) {
                $qb = $er->createQueryBuilder('i')
                    ->leftJoin('i.session', 's')->addSelect('s')
                    ->leftJoin('i.entreprise', 'e')->addSelect('e')
                    ->leftJoin('i.stagiaire', 'u')->addSelect('u')
                    ->andWhere('s.entite = :entite')
                    ->setParameter('entite', $entite)
                    ->orderBy('i.id', 'DESC');

                // Si session/entreprise non définies => on ne propose rien (évite mélanges)
                if (!$session || !$entreprise) {
                    return $qb->andWhere('1=0');
                }

                return $qb
                    ->andWhere('i.session = :s')->setParameter('s', $session)
                    ->andWhere('i.entreprise = :e')->setParameter('e', $entreprise);
            },
            'attr' => [
                'class' => 'form-select',
                'data-placeholder' => 'Sélectionner les inscriptions (stagiaires)',
            ],
            'help' => 'Disponible uniquement si une entreprise est sélectionnée (convention groupe).',
        ]);
    }

    /**
     * Champ inscriptions pour le submit (sans objets, en filtrant par IDs)
     * -> indispensable pour éviter "This value is not valid" quand le QB dépend du contexte.
     */
    private function addInscriptionsFieldForSubmit(
        FormInterface $form,
        Entite $entite,
        ?int $sessionId,
        ?int $entrepriseId
    ): void {
        $form->add('inscriptions', EntityType::class, [
            'class' => Inscription::class,
            'label' => 'Stagiaires (inscriptions)',
            'multiple' => true,
            'required' => false,
            'by_reference' => false,
            'choice_label' => function (Inscription $i) {
                $u = $i->getStagiaire();
                $name = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';
                $mail = $u?->getEmail() ?? '';
                return sprintf('#%d — %s%s', $i->getId(), $name, $mail ? ' (' . $mail . ')' : '');
            },
            'query_builder' => function (EntityRepository $er) use ($entite, $sessionId, $entrepriseId) {
                $qb = $er->createQueryBuilder('i')
                    ->leftJoin('i.session', 's')
                    ->leftJoin('i.entreprise', 'e')
                    ->andWhere('s.entite = :entite')
                    ->setParameter('entite', $entite)
                    ->orderBy('i.id', 'DESC');

                if (!$sessionId || !$entrepriseId) {
                    return $qb->andWhere('1=0');
                }

                return $qb
                    ->andWhere('s.id = :sid')->setParameter('sid', $sessionId)
                    ->andWhere('e.id = :eid')->setParameter('eid', $entrepriseId);
            },
            'attr' => [
                'class' => 'form-select',
                'data-placeholder' => 'Sélectionner les inscriptions (stagiaires)',
            ],
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
        $resolver->setAllowedTypes('lock_session', 'bool');
        $resolver->setAllowedTypes('lock_entreprise', 'bool');
        $resolver->setAllowedTypes('lock_stagiaire', 'bool');
    }
}
