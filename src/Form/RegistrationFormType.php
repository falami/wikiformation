<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class RegistrationFormType extends AbstractType
{
    public function __construct(private UrlGeneratorInterface $router) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Prénom
            ->add('prenom', TextType::class, [
                'label' => 'auth.register.fields.first_name',
                'attr'  => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'auth.register.errors.first_name_required']),
                ],
            ])

            // Nom
            ->add('nom', TextType::class, [
                'label' => 'auth.register.fields.last_name',
                'attr'  => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'auth.register.errors.last_name_required']),
                ],
            ])

            // Email
            ->add('email', EmailType::class, [
                'label' => 'auth.register.fields.email',
                'attr'  => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'auth.register.errors.email_required']),
                    new Email(['message' => 'auth.register.errors.email_invalid']),
                ],
            ])

            // RGPD (label HTML + URL injectée)
            ->add('consentementRgpd', CheckboxType::class, [
                'label' => 'auth.register.fields.rgpd_label_html',
                'label_html' => true,
                'label_translation_parameters' => [
                    '%policy_url%' => $this->router->generate('app_public_politique_confidentialite'),
                ],
                'constraints' => [
                    new IsTrue(['message' => 'auth.register.errors.must_accept_privacy']),
                ],
            ])

            // Newsletter (facultatif)
            ->add('newsletter', CheckboxType::class, [
                'label' => 'auth.register.fields.newsletter',
                'label_html' => true,
                'required' => false,
            ])

            // Mot de passe (répété)
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'auth.register.errors.password_mismatch',
                'required' => true,

                'first_options' => [
                    'label' => 'auth.register.fields.password',
                    'attr'  => ['class' => 'form-control'],
                    'constraints' => [
                        new NotBlank(['message' => 'auth.register.errors.password_required']),
                        new Length([
                            'min' => 8,
                            'minMessage' => 'auth.register.errors.password_min',
                        ]),
                        new Regex([
                            'pattern' => "/^(?=.*[A-Z])(?=.*\d).{8,}$/",
                            'message' => 'auth.register.errors.password_strength',
                        ]),
                    ],
                ],

                'second_options' => [
                    'label' => 'auth.register.fields.password_confirm',
                    'attr'  => ['class' => 'form-control'],
                ],
            ]);
    }
}
