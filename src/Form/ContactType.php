<?php
namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Champs
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

// Contraintes
use Symfony\Component\Validator\Constraints as Assert;

// reCAPTCHA v3
use Karser\Recaptcha3Bundle\Form\Recaptcha3Type;
use Karser\Recaptcha3Bundle\Validator\Constraints\Recaptcha3;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'constraints' => [
                    new Assert\NotBlank(message: 'Votre nom est requis.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new Assert\NotBlank(message: 'Votre email est requis.'),
                    new Assert\Email(message: 'Email invalide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'constraints' => [
                    new Assert\NotBlank(message: 'Votre message est requis.'),
                    new Assert\Length(min: 10, max: 5000),
                ],
                'attr' => ['rows' => 5],
            ])

            // Honeypot (doit rester vide)
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                    'aria-hidden' => 'true',
                ],
                'row_attr' => [
                    'style' => 'position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;',
                    'aria-hidden' => 'true',
                ],
            ])

            // Tempo minimale (timestamp à l’affichage)
            ->add('startedAt', HiddenType::class, [
                'mapped' => false,
                'data' => (string) (new \DateTimeImmutable())->getTimestamp(),
            ])

            // reCAPTCHA v3 (invisible)
            ->add('captcha', Recaptcha3Type::class, [
                'mapped' => false,
                'constraints' => [new Recaptcha3()],
                'action_name' => 'contact', // libre, pour tes analytics reCAPTCHA
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
