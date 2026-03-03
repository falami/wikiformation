<?php
// src/Form/Administrateur/SessionInscriptionType.php
namespace App\Form\Administrateur;

use App\Entity\Entite;
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
                        ->andWhere('u.entite = :e')
                        ->setParameter('e', $entite)
                        ->orderBy('u.nom', 'ASC')
                        ->addOrderBy('u.prenom', 'ASC');
                },
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
            'entite' => null, // ✅ option custom
        ]);

        $resolver->setAllowedTypes('entite', ['null', Entite::class]);
    }
}
