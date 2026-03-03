<?php

namespace App\Form\Administrateur;

use App\Entity\{Paiement, Entite, Facture};
use App\Enum\ModePaiement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use App\Form\DataTransformer\FrenchToDateTransformer;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextType, FileType, MoneyType, HiddenType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Doctrine\ORM\EntityRepository;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;

class PaiementType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
    ) {}

    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('facture', EntityType::class, [
                'class' => Facture::class,
                'required' => false,
                'query_builder' => function (EntityRepository $er) use ($opt) {
                    $qb = $er->createQueryBuilder('f')
                        ->leftJoin('f.destinataire', 'd')->addSelect('d')
                        ->leftJoin('f.entrepriseDestinataire', 'en')->addSelect('en')
                        ->leftJoin('f.paiements', 'fp')->addSelect('fp'); // ✅ évite N+1 pour paid/reste

                    if ($opt['entite']) {
                        $qb->andWhere('f.entite = :e')->setParameter('e', $opt['entite']);
                    }

                    return $qb->orderBy('f.id', 'DESC');
                },

                // ✅ Affiche le bon email selon particulier vs entreprise
                'choice_label' => function (Facture $f): string {
                    $label = $f->getDestinataireLabel(); // entreprise ou user
                    $email = $this->getFactureEmail($f);
                    return sprintf('%s - %s - %s', $f->getNumero(), $label, $email);
                },

                'choice_value' => 'id',

                // ✅ data-* : TTC/restant + infos nécessaires pour ventiler en JS
                'choice_attr' => function (Facture $f) {
                    $ttcTotal = $this->getTtcTotalCents($f);
                    $restant  = $this->getResteAPayerTotalCents($f);

                    // ✅ pour ventilation live côté JS
                    $ttcHd = method_exists($f, 'getMontantTtcHorsDeboursCents')
                        ? (int) ($f->getMontantTtcHorsDeboursCents() ?? 0)
                        : (int) ($f->getMontantTtcCents() ?? 0); // fallback si ton getter diffère

                    $htHd = method_exists($f, 'getMontantHtHorsDeboursCents')
                        ? (int) ($f->getMontantHtHorsDeboursCents() ?? 0)
                        : 0;

                    $debTtc = method_exists($f, 'getMontantDeboursTtcCents')
                        ? (int) ($f->getMontantDeboursTtcCents() ?? 0)
                        : 0;

                    return [
                        // ✅ pour montant auto (restant dû)
                        'data-ttc-cents'          => (string) $ttcTotal,
                        'data-restant-cents'      => (string) $restant,

                        // ✅ pour ventilation live
                        'data-ttc-hd-cents'       => (string) $ttcHd,
                        'data-ht-hd-cents'        => (string) $htHd,
                        'data-debours-ttc-cents'  => (string) $debTtc,

                        // (optionnel)
                        'data-email'              => (string) $this->getFactureEmail($f),
                        'data-destinataire'       => (string) $f->getDestinataireLabel(),
                    ];
                },

                'label' => 'Facture liée (optionnel)',
                'placeholder' => 'Sélectionner une facture',
                'attr' => ['class' => 'form-select'],
            ])

            ->add('montantCents', MoneyType::class, [
                'label' => '*Montant',
                'divisor' => 100, // euros en UI, centimes en entité
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
                'constraints' => [new GreaterThan(0)],
            ])

            ->add('mode', ChoiceType::class, [
                'label' => '*Mode de paiement',
                'choices' => [
                    'Carte Bancaire' => ModePaiement::CB,
                    'Virement'       => ModePaiement::VIREMENT,
                    'Chèque'         => ModePaiement::CHEQUE,
                    'Espèces'        => ModePaiement::ESPECES,
                    'OPCO'           => ModePaiement::OPCO,
                ],
                'choice_label' => fn(ModePaiement $m) => $m->label(),
                'choice_value' => fn(?ModePaiement $m) => $m?->value,
                'placeholder'  => 'Sélectionner un mode',
                'attr' => ['class' => 'form-select'],
            ])

            ->add('datePaiement', TextType::class, [
                'label' => '*Date de paiement',
                'attr'  => ['class' => 'form-control flatpickr-datetime', 'placeholder' => 'jj/mm/aaaa'],
            ])

            ->add('justificatif', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Justificatif (PDF / Image)',
                'help' => 'PDF, JPG, PNG, WEBP ou GIF - 10 Mo max.',
                'attr' => ['class' => 'form-control', 'accept' => 'application/pdf,image/*'],
                'constraints' => [
                    new FileConstraint(
                        maxSize: '10M',
                        mimeTypes: [
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/gif',
                        ],
                        mimeTypesMessage: 'Format invalide (PDF ou image requis).',
                        maxSizeMessage: 'Fichier trop volumineux (10 Mo max).',
                    ),
                ],
            ])

            ->add('payeurUtilisateur', EntityType::class, [
                'class' => Utilisateur::class,
                'required' => false,
                'label' => 'Payeur (particulier)',
                'placeholder' => 'Sélectionner un utilisateur',
                'query_builder' => function (EntityRepository $er) use ($opt) {
                    $qb = $er->createQueryBuilder('u');

                    // ✅ Filtre multi-entité via la table pivot
                    if ($opt['entite']) {
                        $qb->innerJoin('u.utilisateurEntites', 'ue')
                            ->andWhere('ue.entite = :e')
                            ->setParameter('e', $opt['entite']);
                    }

                    return $qb->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])


            ->add('payeurEntreprise', EntityType::class, [
                'class' => Entreprise::class,
                'required' => false,
                'label' => 'Payeur (entreprise)',
                'placeholder' => 'Sélectionner une entreprise',
                'query_builder' => function (EntityRepository $er) use ($opt) {
                    $qb = $er->createQueryBuilder('en');
                    if ($opt['entite']) {
                        $qb->andWhere('en.entite = :e')->setParameter('e', $opt['entite']);
                    }
                    return $qb->orderBy('en.raisonSociale', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])

            ->add('ventilationHtHorsDeboursCents', MoneyType::class, [
                'label' => 'HT (hors débours)',
                'divisor' => 100,
                'currency' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control text-end js-ventil',
                    'readonly' => true,
                    'tabindex' => '-1',
                ],
            ])

            ->add('ventilationTvaHorsDeboursCents', MoneyType::class, [
                'label' => 'TVA (hors débours)',
                'divisor' => 100,
                'currency' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control text-end js-ventil',
                    'readonly' => true,
                    'tabindex' => '-1',
                ],
            ])

            ->add('ventilationDeboursCents', MoneyType::class, [
                'label' => 'Débours',
                'divisor' => 100,
                'currency' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control text-end js-ventil',
                    'readonly' => true,
                    'tabindex' => '-1',
                ],
            ])

            ->add('ventilationSource', HiddenType::class, [
                'required' => false,
            ]);

        $b->get('datePaiement')->addModelTransformer($this->dateFr);
    }

    private function getTtcTotalCents(Facture $f): int
    {
        // ⚠️ chez toi: getMontantTtcCents() = TTC hors débours (d'après ton contrôleur)
        $ttcHd = (int) ($f->getMontantTtcCents() ?? 0);

        $deb = method_exists($f, 'getMontantDeboursTtcCents')
            ? (int) ($f->getMontantDeboursTtcCents() ?? 0)
            : 0;

        return $ttcHd + $deb;
    }

    private function getPaidForFactureCents(Facture $f): int
    {
        // ✅ grâce au leftJoin('f.paiements','fp'), déjà hydraté
        $sum = 0;
        if (method_exists($f, 'getPaiements')) {
            foreach ($f->getPaiements() as $p) {
                $sum += (int) ($p->getMontantCents() ?? 0);
            }
        }
        return $sum;
    }

    private function getResteAPayerTotalCents(Facture $f): int
    {
        $ttc  = $this->getTtcTotalCents($f);
        $paid = $this->getPaidForFactureCents($f);
        return max(0, $ttc - $paid);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Paiement::class,
            'entite' => null,
        ]);
        $r->setAllowedTypes('entite', ['null', Entite::class]);
    }

    private function getFactureEmail(Facture $f): string
    {
        // Entreprise prioritaire
        if (method_exists($f, 'getEntrepriseDestinataire') && $f->getEntrepriseDestinataire()) {
            $en = $f->getEntrepriseDestinataire();
            return $en->getEmailFacturation()
                ?: $en->getEmail()
                ?: '-';
        }

        // Sinon particulier
        return $f->getDestinataire()?->getEmail() ?: '-';
    }
}
