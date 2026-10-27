<?php

namespace App\Form\Administrateur;

use App\Entity\Entreprise;
use App\Entity\UtilisateurEntite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    CheckboxType,
    ChoiceType,
    EmailType,
    TextType,
    FileType,
    HiddenType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Count;
use App\Form\DataTransformer\FrenchToDateTransformer;
use Symfony\Component\Form\FormInterface;

final class UtilisateurType extends AbstractType
{
    public function __construct(
        private FrenchToDateTransformer $dateFr,
    ) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $locked = $o['locked'] ?? false;
        $identityLocked = $o['identity_locked'];
        $emailLocked = $identityLocked;
        $entite = $o['entite'] ?? null;

        /** @var Utilisateur $utilisateur */
        $utilisateur = $b->getData();

        $choices = [
            'Stagiaire'      => UtilisateurEntite::TENANT_STAGIAIRE,
            'Formateur'      => UtilisateurEntite::TENANT_FORMATEUR,
            'Entreprise'     => UtilisateurEntite::TENANT_ENTREPRISE,
            'OPCO'           => UtilisateurEntite::TENANT_OPCO,
            'Organisme (OF)' => UtilisateurEntite::TENANT_OF,
            'Commercial'     => UtilisateurEntite::TENANT_COMMERCIAL,
        ];

        foreach (['Administrateur' => UtilisateurEntite::TENANT_ADMIN, 'Dirigeant' => UtilisateurEntite::TENANT_DIRIGEANT] as $label => $role) {
            if ($o['can_set_high_roles'] || in_array($role, $o['ueRoles'], true)) {
                $choices[$label] = $role;
            }
        }

        $b
            ->add('civilite', ChoiceType::class, [
                'disabled' => $identityLocked,
                'required' => false,
                'choices' => ['-' => null, 'Monsieur' => 'Monsieur', 'Madame' => 'Madame'],
                'attr' => ['class' => 'form-select']
            ])
            ->add('prenom', TextType::class, [
                'disabled' => $identityLocked,
                'attr' => ['class' => 'form-control']
            ])
            ->add('nom', TextType::class, [
                'disabled' => $identityLocked,
                'attr' => ['class' => 'form-control']
            ])
            ->add('email', EmailType::class, [
                'disabled' => $emailLocked,
                'help' => $emailLocked ? 'Ce compte appartient à un autre organisme : son adresse est protégée.' : 'Cette adresse sera utilisée pour la connexion et les prochains emails. Les inscriptions sont conservées.',
                'attr' => ['class' => 'form-control']
            ])
            ->add('photo', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de profil',
                'constraints' => [
                    new Image(
                        maxSize: '8M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Image invalide'
                    )
                ],
                'attr' => [
                    'accept' => 'image/*',
                    'class' => 'd-none',
                ],
            ])
            ->add('removePhoto', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => '0',
            ])
            ->add('dateNaissance', TextType::class, [
                'required' => false,
                'disabled' => $identityLocked,
                'attr' => ['class' => 'form-control js-flatpickr-date']
            ])
            ->add('entreprisesAssociees', EntityType::class, [
                'class' => Entreprise::class, 'multiple' => true, 'required' => false, 'mapped' => false,
                'label' => 'Entreprises associées', 'choice_label' => 'raisonSociale',
                'data' => array_values(array_unique(array_filter([
                    ...($utilisateur?->getEntreprisesAssociees()->toArray() ?? []),
                    $utilisateur?->getEntreprise(),
                ], fn($e) => $e && $e->getEntite() === $entite), SORT_REGULAR)),
                'attr' => ['class' => 'form-select js-tomselect-entreprise'],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('e')->where('e.entite = :entite')->setParameter('entite', $entite)->orderBy('e.raisonSociale', 'ASC'),
                'help' => 'Le stagiaire pourra être sélectionné pour les conventions de chacune de ces entreprises.',
            ])
            ->add('telephone', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('adresse', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('complement', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('codePostal', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('ville', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('isVerified', CheckboxType::class, [
                'required' => false,
                'disabled' => $locked || $identityLocked,
            ])
            ->add('ueRoles', ChoiceType::class, [
                'mapped' => false,
                'required' => true,
                'multiple' => true,
                'expanded' => false,
                'label' => 'Rôles',
                'data' => $o['ueRoles'] ?? [UtilisateurEntite::TENANT_STAGIAIRE],
                'choices' => $choices,
                'constraints' => [new Count(min: 1, minMessage: 'Sélectionnez au moins un rôle dans cet organisme.')],
                'attr' => ['class' => 'form-select js-ts-ueroles'],
            ])
            ->add('formateurData', FormateurInlineType::class, [
                'data' => $o['formateur_data'],
                'mapped' => false,
                'required' => false,
                'entite' => $entite,
            ])
            ->add('entrepriseData', EntrepriseInlineType::class, [
                'mapped' => false,
                'required' => false,
                'locked' => $locked,
                'entite' => $entite,
                // Edit a detached copy: selecting another company must not mutate the original.
                'data' => $utilisateur->getEntreprise() ? clone $utilisateur->getEntreprise() : new Entreprise(),
                'empty_data' => fn(FormInterface $form) => new Entreprise(),
            ]);

        $b->get('dateNaissance')->addModelTransformer($this->dateFr);
        $b->addEventListener(\Symfony\Component\Form\FormEvents::POST_SUBMIT, static function (\Symfony\Component\Form\FormEvent $event) use ($entite): void {
            $form = $event->getForm();
            if (!$form->get('entreprisesAssociees')->isValid()) return;
            $u = $event->getData();
            foreach ($u->getEntreprisesAssociees()->toArray() as $company) {
                if ($company->getEntite() === $entite) $u->removeEntreprisesAssociee($company);
            }
            $companies = $form->get('entreprisesAssociees')->getData();
            foreach ($companies as $company) $u->addEntreprisesAssociee($company);
            // Keep the legacy default only when there is a single unambiguous company.
            if (!$u->getEntreprise() || $u->getEntreprise()->getEntite() === $entite) {
                $u->setEntreprise(count($companies) === 1 ? $companies[0] : null);
            }
        });

    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Utilisateur::class,
            'formateur_data' => [],
            'locked' => false,
            'identity_locked' => false,
            'entite' => null,
            'ueRoles' => [UtilisateurEntite::TENANT_STAGIAIRE],
            'can_set_high_roles' => false,
        ]);
    }
}
