<?php
namespace App\Form;

use App\Service\Security\ContactCaptcha;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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

class ContactType extends AbstractType
{
    public function __construct(private readonly ContactCaptcha $captcha) {}

    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $challenge = $this->captcha->create();
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

            ->add('captchaId', HiddenType::class, [
                'mapped' => false,
                'data' => $challenge['id'],
            ])
            ->add('captchaAnswer', TextType::class, [
                'mapped' => false,
                'label' => $challenge['question'],
                'help' => 'Saisissez le résultat en chiffres. Cette vérification est valable 30 minutes.',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'maxlength' => 3],
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

        ;
        $b->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $id = $form->get('captchaId');
            $answer = $form->get('captchaAnswer');
            if (!$id->isSynchronized() || !$answer->isSynchronized()
                || !$this->captcha->verify((string) $id->getData(), (string) $answer->getData())) {
                $answer->addError(new FormError('La vérification anti-spam est incorrecte ou a expiré. Veuillez répondre au nouveau calcul.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
