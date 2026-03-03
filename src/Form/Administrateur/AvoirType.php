<?php

namespace App\Form\Administrateur;

use App\Entity\Avoir;
use App\Entity\Entite;
use App\Entity\Facture;
use App\Form\DataTransformer\FrenchToDateTransformer;
use App\Repository\FactureRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{MoneyType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AvoirType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
    ) {}

    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        /** @var Entite|null $entite */
        $entite = $opt['entite'] ?? null;

        $b
            ->add('factureOrigine', EntityType::class, [
                'class' => Facture::class,
                'choice_label' => fn(Facture $f) => sprintf(
                    '%s - %s',
                    $f->getNumero(),
                    $f->getDestinataire()?->getEmail() ?? '-'
                ),
                'choice_attr' => fn(Facture $f) => [
                    'data-ttc-cents' => (string) $this->getTtcCents($f),
                ],
                'label' => '*Facture d’origine',
                'placeholder' => 'Sélectionner une facture',
                'attr' => ['class' => 'form-select'],

                'query_builder' => function (FactureRepository $repo) use ($entite) {
                    $qb = $repo->createQueryBuilder('f')
                        ->leftJoin('f.destinataire', 'u')->addSelect('u')
                        ->orderBy('f.id', 'DESC');

                    if (!$entite) {
                        return $qb->andWhere('1 = 0'); // sécurité multi-tenant
                    }

                    return $qb
                        ->andWhere('f.entite = :entite')
                        ->setParameter('entite', $entite);
                },
            ])
            ->add('dateEmission', TextType::class, [
                'label' => '*Date d’émission',
                'attr'  => ['class' => 'form-control flatpickr-date', 'placeholder' => 'jj/mm/aaaa'],
            ])
            ->add('montantTtcCents', MoneyType::class, [
                'label' => '*Montant TTC',
                'divisor' => 100,
                'currency' => false,
                'attr' => ['class' => 'form-control text-end', 'placeholder' => '0,00'],
            ])
        ;

        $b->get('dateEmission')->addModelTransformer($this->dateFr);
    }

    private function getTtcCents(Facture $f): int
    {
        foreach (['getMontantTtcCents', 'getTotalTtcCents', 'getTtcCents', 'getTotalTtc'] as $m) {
            if (method_exists($f, $m)) {
                return (int) $f->{$m}();
            }
        }
        return 0;
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Avoir::class,
            'entite' => null,
        ]);

        $r->setAllowedTypes('entite', ['null', Entite::class, 'int']);
    }
}
