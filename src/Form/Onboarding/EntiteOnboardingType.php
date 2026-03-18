<?php

namespace App\Form\Onboarding;

use App\Entity\Entite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{
    TextType,
    EmailType,
    HiddenType,
    FileType
};
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EntiteOnboardingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom de l'organisme",
                'required' => true,
                'attr' => [
                    'class' => 'form-control form-control-lg',
                    'placeholder' => 'Ex. WikiFormation Academy',
                    'autocomplete' => 'organization',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: "Le nom de l’organisme est obligatoire."),
                    new Assert\Length(
                        max: 100,
                        maxMessage: "Le nom de l’organisme ne peut pas dépasser {{ limit }} caractères."
                    ),
                ],
            ])

            ->add('email', EmailType::class, [
                'label' => "Email",
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'contact@mon-organisme.fr',
                    'autocomplete' => 'email',
                    'inputmode' => 'email',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: "L’adresse email est obligatoire."),
                    new Assert\Email(message: "Veuillez saisir une adresse email valide."),
                    new Assert\Length(
                        max: 255,
                        maxMessage: "L’adresse email ne peut pas dépasser {{ limit }} caractères."
                    ),
                ],
            ])

            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. 0606060606',
                    'autocomplete' => 'tel',
                    'inputmode' => 'tel',
                ],
                'help' => 'Vous pouvez saisir 0606060606, il sera automatiquement converti en +33606060606.',
                'constraints' => [
                    new Assert\NotBlank(message: "Le numéro de téléphone est obligatoire."),
                    new Assert\Length(
                        max: 20,
                        maxMessage: "Le numéro de téléphone est trop long."
                    ),
                    new Assert\Regex([
                        'pattern' => '/^\+[1-9]\d{7,14}$/',
                        'message' => 'Le numéro de téléphone doit être au format international, par exemple +33612345678.',
                    ]),
                ],
            ])

            ->add('siret', TextType::class, [
                'label' => "SIRET",
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '14 chiffres',
                    'autocomplete' => 'off',
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^$|^\d{14}$/',
                        'message' => 'Le SIRET doit contenir exactement 14 chiffres.',
                    ]),
                ],
            ])

            ->add('ville', TextType::class, [
                'label' => "Ville",
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. Marseille',
                    'autocomplete' => 'address-level2',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: "La ville est obligatoire."),
                    new Assert\Length(
                        max: 255,
                        maxMessage: "Le nom de la ville ne peut pas dépasser {{ limit }} caractères."
                    ),
                ],
            ])

            ->add('logoFile', FileType::class, [
                'label' => false,
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => '.png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml',
                    'class' => 'd-none',
                ],
                'constraints' => [
                    new Assert\File(
                        maxSize: '2M',
                        maxSizeMessage: 'Le logo ne doit pas dépasser 2 Mo.',
                        mimeTypes: [
                            'image/png',
                            'image/jpeg',
                            'image/webp',
                            'image/svg+xml',
                        ],
                        mimeTypesMessage: 'Format invalide. Formats acceptés : PNG, JPG, WebP ou SVG.',
                    ),
                ],
            ])

            ->add('couleurPrincipal', TextType::class, [
                'label' => 'Couleur principale',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '#RRGGBB ou laisser vide',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
                        'message' => 'Couleur invalide. Format attendu : #RRGGBB',
                    ]),
                ],
            ])

            ->add('couleurSecondaire', TextType::class, [
                'label' => 'Couleur secondaire',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '#RRGGBB (optionnel)',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
                        'message' => 'Couleur invalide. Format attendu : #RRGGBB',
                    ]),
                ],
            ])

            ->add('couleurTertiaire', TextType::class, [
                'label' => 'Couleur tertiaire',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '#RRGGBB (optionnel)',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
                        'message' => 'Couleur invalide. Format attendu : #RRGGBB',
                    ]),
                ],
            ])

            ->add('couleurQuaternaire', TextType::class, [
                'label' => 'Couleur quaternaire',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '#RRGGBB (optionnel)',
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^$|^#[0-9A-Fa-f]{6}$/',
                        'message' => 'Couleur invalide. Format attendu : #RRGGBB',
                    ]),
                ],
            ])

            ->add('planCode', HiddenType::class, [
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un plan pour continuer.'),
                ],
            ])

            ->add('interval', HiddenType::class, [
                'mapped' => false,
                'data' => 'year',
                'constraints' => [
                    new Assert\Choice(
                        choices: ['month', 'year'],
                        message: 'Périodicité invalide.'
                    ),
                ],
            ])
        ;

        /**
         * Normalisation avant mapping/validation :
         * - téléphone FR => format international
         * - email trim/lower
         * - ville trim
         * - siret : on enlève les espaces
         */
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            $data['nom'] = isset($data['nom']) ? trim((string) $data['nom']) : null;
            $data['email'] = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
            $data['ville'] = isset($data['ville']) ? trim((string) $data['ville']) : null;

            if (isset($data['siret'])) {
                $data['siret'] = preg_replace('/\s+/', '', (string) $data['siret']);
            }

            if (isset($data['telephone'])) {
                $data['telephone'] = self::normalizePhone((string) $data['telephone']);
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entite::class,
        ]);
    }

    private static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return '';
        }

        // enlève espaces, points, tirets, parenthèses
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';

        if ($value === '') {
            return '';
        }

        // 0033XXXXXXXXX -> +33XXXXXXXXX
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        // 33XXXXXXXXX -> +33XXXXXXXXX
        if (preg_match('/^33\d{9}$/', $value)) {
            return '+' . $value;
        }

        // 0X XX XX XX XX -> +33X XX XX XX XX
        if (preg_match('/^0\d{9}$/', $value)) {
            return '+33' . substr($value, 1);
        }

        // déjà international correct-ish
        if (str_starts_with($value, '+')) {
            return $value;
        }

        return $value;
    }
}