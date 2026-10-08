<?php

declare(strict_types=1);
namespace App\Form\Administrateur;

use Symfony\Component\Form\{AbstractType, FormBuilderInterface, FormEvent, FormEvents};
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, IntegerType};
use Symfony\Component\Validator\Constraints\{NotNull, Range};
use Symfony\Component\Form\FormError;

final class TrainerReminderSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ([
            'contractNotice' => 'Prévenir le formateur quand son contrat est prêt',
            'contractReminder' => 'Relancer les contrats non signés',
            'attendanceReminder' => 'Relancer les émargements manquants',
            'satisfactionReminder' => 'Relancer les appréciations et comptes rendus stagiaires manquants',
        ] as $key => $label) $builder->add($key, CheckboxType::class, ['label' => $label, 'required' => false, 'row_attr' => ['class' => 'form-switch mb-3'], 'attr' => ['role' => 'switch']]);
        foreach ([
            'contractDelay' => 'Première relance après l’e-mail de mise à disposition (jours)',
            'attendanceDelay' => 'Première relance après la fin de session (jours)',
            'satisfactionDelay' => 'Première relance après la fin de session (jours)',
            'repeatDays' => 'Intervalle entre deux relances (jours)',
            'maxReminders' => 'Nombre maximum de relances par sujet',
        ] as $key => $label) {
            $max = $key === 'maxReminders' ? 20 : 365;
            $builder->add($key, IntegerType::class, ['label' => $label, 'attr' => ['min' => 1, 'max' => $max], 'constraints' => [new NotNull(), new Range(min: 1, max: $max)]]);
        }
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (($data['contractReminder'] ?? false) && !($data['contractNotice'] ?? false)) {
                $event->getForm()->get('contractNotice')->addError(new FormError('Activez l’e-mail de mise à disposition pour démarrer le délai des relances de signature.'));
            }
        });
    }
}
