<?php
// src/Form/Administrateur/SessionInscriptionType.php
namespace App\Form\Administrateur;

use App\Entity\Entite;
use App\Entity\Entreprise;
use App\Entity\UtilisateurEntite;
use App\Entity\Inscription;
use App\Entity\Utilisateur;
use App\Enum\StatusInscription;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SessionInscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $options): void
    {
        /** @var Entite|null $entite */
        $entite = $options['entite'] ?? null;

        $b->add('createConvention', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
            'mapped' => false, 'required' => false, 'label' => 'Créer et rattacher une nouvelle convention',
            'help' => 'Une convention brouillon par entreprise (ou par particulier) sera créée à l’enregistrement. Son prix pourra ensuite être ajusté.',
        ]);
        $b->add('conventionsToAssociate', EntityType::class, [
            'class' => \App\Entity\ConventionContrat::class,
            'mapped' => false, 'multiple' => true, 'required' => false,
            'choices' => $options['conventions'],
            'label' => 'Associer à une convention',
            'choice_label' => static fn(\App\Entity\ConventionContrat $c) => ($c->getNumero() ?: 'Convention #'.$c->getId()).' — '.$c->getDestinataireLabel(),
            'attr' => ['class' => 'form-select', 'data-placeholder' => 'Sélectionner une ou plusieurs conventions…'],
            'help' => 'Conventions non signées de cette session. Le rattachement sera effectué à l’enregistrement ; les liens existants sont conservés.',
        ]);

        $b
            ->add('stagiaire', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => static function (Utilisateur $u) {
                    return trim(sprintf(
                        '%s %s (%s)',
                        $u->getPrenom() ?? '',
                        $u->getNom() ?? '',
                        $u->getEmail() ?? ''
                    ));
                },
                'choice_attr' => static function (Utilisateur $u) use ($entite): array {
                    $company = $u->getEntreprise();
                    if ($company?->getEntite() !== $entite) $company = null;
                    $company ??= $u->getEntreprisesAssociees()->filter(fn($e) => $e->getEntite() === $entite)->first() ?: null;
                    return ['data-company' => $company?->getId() ?? '', 'data-company-label' => $company?->getRaisonSociale() ?? ''];
                },
                'placeholder' => 'Sélectionner un stagiaire…',
                'attr' => ['class' => 'form-select tom-select-inscription'],
                'required' => true,

                // ✅ Filtre multi-tenant : uniquement les utilisateurs de l'entité courante
                'query_builder' => static function (EntityRepository $er) use ($entite) {
                    $qb = $er->createQueryBuilder('u');

                    // Sécurité : si pas d'entité passée, ne rien afficher
                    if (!$entite) {
                        return $qb->andWhere('1 = 0');
                    }

                    return $qb
                        ->leftJoin('u.utilisateurEntites', 'ue', 'WITH', 'ue.entite = :e')
                        ->andWhere('(ue.status = :active OR (ue.id IS NULL AND u.entite = :e))')
                        ->setParameter('active', UtilisateurEntite::STATUS_ACTIVE)
                        ->distinct()
                        ->setParameter('e', $entite)
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
            ])
            ->add('entreprise', EntityType::class, [
                'class' => Entreprise::class,
                'label' => 'Entreprise de prise en charge',
                'required' => false,
                'placeholder' => 'Aucune / particulier',
                'choice_label' => 'raisonSociale',
                'attr' => ['class' => 'form-select'],
                'query_builder' => static fn(EntityRepository $er) => $er->createQueryBuilder('e')
                    ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
                    ->orderBy('e.raisonSociale', 'ASC'),
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Statut',
                'required' => true,
                'choices' => StatusInscription::cases(),
                'choice_label' => fn(StatusInscription $s) => $s->label(),
                'choice_value' => fn(?StatusInscription $s) => $s?->value,
                'attr' => ['class' => 'form-select'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Inscription::class,
            'conventions' => [],
            'entite' => null, // ✅ option custom
        ]);

        $resolver->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
