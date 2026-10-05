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
        $emailLocked = $locked || $identityLocked;
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

        if (($o['can_set_high_roles'] ?? false) === true) {
            $choices['Administrateur'] = UtilisateurEntite::TENANT_ADMIN;
            $choices['Dirigeant']      = UtilisateurEntite::TENANT_DIRIGEANT;
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
                'help' => $emailLocked ? 'Adresse du compte protégé. La correction du nom ou du prénom ne change pas ses accès ni ses inscriptions.' : null,
                'attr' => ['class' => 'form-control']
            ])
            ->add('photo', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de profil',
                'constraints' => [
                    new Image(
                        maxSize: '8M',
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
                'label' => 'Autres entreprises associées', 'choice_label' => 'raisonSociale',
                'data' => $b->getData()?->getEntreprisesAssociees()->filter(fn($e) => $e->getEntite() === $entite)->toArray() ?? [],
                'attr' => ['class' => 'form-select js-tomselect-entreprise'],
                'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('e')->where('e.entite = :entite')->setParameter('entite', $entite)->orderBy('e.raisonSociale', 'ASC'),
                'help' => 'Le stagiaire pourra être sélectionné pour les conventions de chacune de ces entreprises.',
            ])
            ->add('entreprise', EntityType::class, [
                'required' => false,
                'class' => Entreprise::class,
                'choice_label' => 'raisonSociale',
                'label' => 'Entreprise principale (proposée par défaut)',
                'placeholder' => '- Aucune -',
                'attr' => ['class' => 'form-select js-tomselect-entreprise'],
                'query_builder' => fn(EntityRepository $er) =>
                    $er->createQueryBuilder('e')
                        ->andWhere('e.entite = :entite')
                        ->setParameter('entite', $entite)
                        ->orderBy('e.raisonSociale', 'ASC')
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
                'disabled' => $emailLocked,
            ])
            ->add('newsletter', CheckboxType::class, [
                'required' => false,
                'disabled' => $locked,
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
            foreach ($form->get('entreprisesAssociees')->getData() as $company) $u->addEntreprisesAssociee($company);
        });

    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Utilisateur::class,
            'locked' => false,
            'identity_locked' => false,
            'entite' => null,
            'ueRoles' => [UtilisateurEntite::TENANT_STAGIAIRE],
            'can_set_high_roles' => false,
        ]);
    }
}
