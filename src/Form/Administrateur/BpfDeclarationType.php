<?php

declare(strict_types=1);
namespace App\Form\Administrateur;
use App\Service\Bpf\BpfSchema;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType, EmailType, DateType, IntegerType, ChoiceType, HiddenType};
use Symfony\Component\Validator\Constraints as Assert;

final class BpfDeclarationType extends AbstractType
{
    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['classifications' => []]);
        $resolver->setAllowedTypes('classifications', 'array');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (BpfSchema::fields() as $key => [$label, $type]) {
            $opts = ['label' => $label, 'required' => false];
            $class = TextType::class;
            if (in_array($type, ['integer','money','percent'], true)) {
                $class = IntegerType::class;
                $opts['constraints'] = [new Assert\Range(min: 0, max: $type === 'percent' ? 100 : 999999999)];
                $opts['attr'] = ['min' => 0, 'max' => $type === 'percent' ? 100 : 999999999, 'step' => 1, 'inputmode' => 'numeric'];
            } elseif ($type === 'yesno') { $class = ChoiceType::class; $opts += ['choices' => ['Oui' => 'yes', 'Non' => 'no'], 'expanded' => true, 'placeholder' => 'Non renseigné']; }
            elseif ($type === 'nsf') { $opts['constraints'] = [new Assert\Choice(choices: explode(' ', BpfSchema::NSF_CODES))]; $opts['attr'] = ['maxlength' => 3, 'inputmode' => 'numeric', 'placeholder' => 'Ex. 324']; }
            elseif ($type === 'date') { $class = DateType::class; $opts += ['widget' => 'single_text', 'input' => 'string']; }
            elseif ($type === 'email') { $class = EmailType::class; $opts['constraints'] = [new Assert\Email(), new Assert\Length(max: 255)]; }
            elseif ($type === 'notes') { $class = TextareaType::class; $opts['attr'] = ['rows' => 4]; $opts['constraints'] = [new Assert\Length(max: 10000)]; }
            else $opts['constraints'] = [new Assert\Length(max: 500)];
            if ($key === 'a_siret') $opts['constraints'][] = new Assert\Regex('/^\d{14}$/', message: 'Le SIRET doit contenir 14 chiffres.');
            if ($key === 'a_nda') $opts['constraints'][] = new Assert\Regex('/^\d{11}$/', message: 'Le numéro de déclaration doit contenir 11 chiffres.');
            if ($key === 'a_naf') $opts['constraints'][] = new Assert\Regex('/^\d{4}[A-Za-z]$/', message: 'Format attendu : 8559A.');
            $builder->add($key, $class, $opts);
        }
        foreach ($options['classifications'] as $key => $spec) {
            $builder->add($key, ChoiceType::class, ['label' => $spec['label'], 'choices' => $spec['choices'], 'required' => false,
                'placeholder' => 'À préciser', 'help' => $spec['help'], 'attr' => ['class' => 'bpf-classification']]);
        }
        $builder->add('_version', HiddenType::class);
    }
}
