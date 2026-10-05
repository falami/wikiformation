<?php

namespace App\Form\Administrateur;

use App\Entity\{ConventionContrat, Entreprise, Utilisateur, Session, Entite, Inscription};
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, DateType, TextType, IntegerType, CheckboxType};
use Symfony\Component\Form\{FormBuilderInterface, FormEvent, FormEvents, FormInterface};
use Symfony\Component\Form\FormError;
use App\Service\Convention\ConventionParticipants;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConventionContratType extends AbstractType
{
    public function __construct(private readonly ConventionParticipants $participants, private readonly \Doctrine\ORM\EntityManagerInterface $em) {}

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
                'disabled' => $options['link_signed'],
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => $convention?->getSession()?->getFormation()?->getTitre() ?? 'Intitulé de la formation'],
                'help' => 'Personnalisez le titre pour ce document. Vide : l’intitulé du catalogue est utilisé.',
            ])
            ->add('dureeFormation', TextType::class, [
                'label' => 'Durée sur la convention',
                'required' => false,
                'disabled' => $options['link_signed'],
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => $convention?->getDureeFormationEffective() ?? 'Ex. : 1 jour / 7 heures'],
                'help' => 'Ex. : 1 jour / 7 heures. Vide : la durée pédagogique de la session est utilisée.',
            ])
            ->add('devis', EntityType::class, [
                'class' => \App\Entity\Devis::class, 'required' => false, 'disabled' => $options['link_signed'],
                'choice_label' => static fn(\App\Entity\Devis $quote): string => implode(' — ', self::quoteDetails($quote)),
                'placeholder' => 'Sans devis', 'label' => 'Devis rattaché',
                'choice_attr' => static fn(\App\Entity\Devis $quote): array => [
                    'data-details' => json_encode(self::quoteDetails($quote), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'data-ht' => $quote->getMontantHtCents(),
                    'data-tva' => self::quoteRate($quote),
                    'data-currency' => $quote->getDevise(),
                ],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('d')
                    ->leftJoin('d.entrepriseDestinataire', 'company')->addSelect('company')
                    ->leftJoin('d.destinataire', 'recipient')->addSelect('recipient')
                    ->leftJoin('d.prospect', 'prospect')->addSelect('prospect')
                    ->leftJoin('d.formation', 'formation')->addSelect('formation')
                    ->andWhere('d.entite = :e')->setParameter('e', $entite)
                    ->orderBy('d.dateEmission', 'DESC')->addOrderBy('d.id', 'DESC'),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('montantHtCents', \Symfony\Component\Form\Extension\Core\Type\MoneyType::class, [
                'label' => 'Montant total de la convention HT ('.($convention?->getDevis()?->getDevise() ?? 'EUR').')', 'divisor' => 100, 'input' => 'integer',
                'currency' => false,
                'data' => $convention?->getMontantHtCents() ?? $convention?->getDevis()?->getMontantHtCents(),
                'required' => false, 'disabled' => $options['link_signed'], 'attr' => ['class' => 'form-control'],
                'help' => 'Le montant HT et la TVA du devis sont repris par défaut. Vous pouvez les personnaliser sans modifier le devis. Vide : montant du devis ou estimation tarif × effectif.',
            ])
            ->add('tauxTva', \Symfony\Component\Form\Extension\Core\Type\NumberType::class, [
                'label' => 'TVA (%)', 'scale' => 6,
                'data' => $convention?->getMontantHtCents() === null && $convention?->getDevis()
                    ? self::quoteRate($convention->getDevis()) : ($convention?->getTauxTva() ?? 0),
                'help' => 'Si le devis contient plusieurs taux, ce champ affiche leur taux moyen. Sans personnalisation, les montants exacts du devis sont conservés.',
                'empty_data' => '0', 'disabled' => $options['link_signed'],
                'attr' => ['class' => 'form-control', 'min' => 0, 'max' => 100],
            ])
            ->add('conditionsFinancieres', TextareaType::class, [
                'label' => 'Conditions financières',
                'disabled' => $options['link_signed'],
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 6],
                'help' => 'Modalités de règlement et échéancier figurant sur la convention.',
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($options): void {
            if ($options['link_signed']) return;
            $form = $event->getForm();
            if (!$form->get('montantHtCents')->isSynchronized() || !$form->get('tauxTva')->isSynchronized()) return;
            $document = $event->getData();
            $quote = $document->getDevis();
            // Keep inheritance: do not convert an unchanged quote into a custom price.
            // This also preserves the exact VAT cents on quotes with multiple rates.
            if ($quote && $document->getMontantHtCents() === $quote->getMontantHtCents()
                && abs($document->getTauxTva() - self::quoteRate($quote)) < 0.0000005) {
                $document->setMontantHtCents(null);
            }
        }, 200);

        if (!$options['link_signed'] || !$convention?->getStagiaire()) {
            $builder
                ->add('participantsLibres', TextareaType::class, [
                    'label' => 'Stagiaires sans compte ou sans e-mail',
                    'disabled' => $options['link_signed'],
                    'required' => false,
                    'attr' => ['class' => 'form-control', 'rows' => 4, 'placeholder' => "Camille Durand\nAlex Martin"],
                    'help' => 'Un nom complet par ligne. Ces participants disposent de QR codes personnels pour émarger et donner leur appréciation, sans créer de compte ni renseigner d’e-mail. L’option ci-dessous permet de remplacer un nom libre identique par sa fiche stagiaire.',
                ])
                ->add('effectifPrevisionnel', IntegerType::class, [
                    'label' => 'Nombre total de stagiaires prévu',
                    'disabled' => $options['link_signed'],
                    'required' => false,
                    'attr' => ['class' => 'form-control', 'min' => 1, 'placeholder' => 'Calculé à partir des stagiaires renseignés'],
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

        if ($options['allow_new_participants'] && $convention) {
            $builder->add('stagiaires', EntityType::class, [
                'class' => Utilisateur::class, 'mapped' => false, 'multiple' => true, 'required' => false,
                'label' => 'Stagiaires couverts par la convention',
                'data' => array_map(static fn(Inscription $i) => $i->getStagiaire(), $convention->getInscriptions()->toArray()),
                'query_builder' => fn() => $this->participants->eligibleQuery($convention),
                'choice_label' => static fn(Utilisateur $u): string => trim($u->getPrenom().' '.$u->getNom()).($u->getEmail() ? ' — '.$u->getEmail() : ''),
                'choice_attr' => static fn(Utilisateur $u): array => ['data-name' => trim($u->getPrenom().' '.$u->getNom()), 'data-reverse-name' => trim($u->getNom().' '.$u->getPrenom())],
                'attr' => ['class' => 'form-select', 'data-placeholder' => 'Rechercher un stagiaire par nom, prénom ou e-mail…'],
                'help' => 'Retrouvez les stagiaires de cette entreprise et ceux de l’organisme sans entreprise. Les inscriptions et dossiers manquants seront créés à l’enregistrement.',
            ]);
            if (!$options['link_signed'] || !$convention->getStagiaire()) {
                $builder->add('remplacerNomsLibres', CheckboxType::class, [
                    'mapped' => false, 'required' => false, 'data' => true,
                    'label' => 'Remplacer les noms libres identiques par les stagiaires sélectionnés',
                    'help' => 'Évite de compter deux fois une même personne. Les autres noms restent inchangés.',
                ]);
            }
            $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options, $convention, $entite): void {
                if ($options['link_signed']) return;
                $data = $event->getData();
                if (!is_array($data)) return;
                $context = clone $convention;
                foreach (['session' => Session::class, 'entreprise' => Entreprise::class, 'stagiaire' => Utilisateur::class] as $field => $class) {
                    if ($options['lock_'.$field]) continue;
                    $id = $data[$field] ?? null;
                    $object = is_scalar($id) && ctype_digit((string) $id) ? $this->em->find($class, (int) $id) : null;
                    if ($object && $field !== 'stagiaire' && $object->getEntite() !== $entite) return;
                    $context->{'set'.ucfirst($field)}($object);
                }
                // This clone only supplies choice filters; it must not become a session document.
                $context->getSession()?->getConventionContrats()->removeElement($context);
                $fieldOptions = $event->getForm()->get('stagiaires')->getConfig()->getOptions();
                unset($fieldOptions['choice_loader']);
                $fieldOptions['query_builder'] = fn() => $this->participants->eligibleQuery($context);
                $event->getForm()->add('stagiaires', EntityType::class, $fieldOptions);
            });
            $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($options): void {
                $form = $event->getForm();
                if (!$form->get('stagiaires')->isSynchronized() || count($form->get('stagiaires')->getErrors(true)) > 0) {
                    return;
                }
                try {
                    $this->participants->prepare($event->getData(), $form->get('stagiaires')->getData() ?? [],
                        $form->has('remplacerNomsLibres') && $form->get('remplacerNomsLibres')->getData(), $options['link_signed']);
                } catch (\DomainException $error) {
                    $form->get('stagiaires')->addError(new FormError($error->getMessage()));
                }
            }, 100);
            return;
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

    /** Number, recipient, training, issue date, net amount and status, also searchable without JavaScript. */
    private static function quoteDetails(\App\Entity\Devis $quote): array
    {
        $recipient = $quote->getEntrepriseDestinataire()?->getRaisonSociale();
        if (!$recipient && $user = $quote->getDestinataire()) {
            $recipient = trim($user->getPrenom().' '.$user->getNom());
        }
        if (!$recipient && $prospect = $quote->getProspect()) {
            $recipient = $prospect->getSociete() ?: trim($prospect->getPrenom().' '.$prospect->getNom());
        }
        return [
            $quote->getNumero() ?: 'Devis #'.$quote->getId(),
            $recipient ?: 'Destinataire non renseigné',
            $quote->getFormation()?->getTitre() ?: 'Formation non renseignée',
            $quote->getDateEmission()?->format('d/m/Y') ?? 'Date non renseignée',
            number_format(($quote->getMontantHtCents() ?? 0) / 100, 2, ',', ' ').' '.($quote->getDevise() ?: 'EUR').' HT',
            ucfirst($quote->getStatus()->label()),
        ];
    }

    private static function quoteRate(\App\Entity\Devis $quote): float
    {
        $rates = [];
        foreach ($quote->getLignes() as $line) {
            $rates[(string) $line->getTva()] = (float) $line->getTva();
        }
        if (count($rates) === 1) return reset($rates);
        $ht = $quote->getMontantHtCents();
        return $ht > 0 ? round($quote->getMontantTvaCents() * 100 / $ht, 6) : 0.0;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConventionContrat::class,
            'link_signed' => false,
            'entite' => null,
            'lock_session' => false,
            'lock_entreprise' => false,
            'lock_stagiaire' => false,
            'allow_new_participants' => false,
        ]);
        $resolver->setAllowedTypes('entite', [Entite::class, 'null']);
        foreach (['lock_session', 'lock_entreprise', 'lock_stagiaire', 'allow_new_participants'] as $option) {
            $resolver->setAllowedTypes($option, 'bool');
        }
    }
}
