<?php
declare(strict_types=1);
namespace App\Form\Administrateur;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

final class InvoiceFollowUpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = ['Désactivé' => 0, 'Tous les mois' => 1, 'Tous les 3 mois' => 3, 'Tous les 6 mois' => 6, 'Tous les ans' => 12];
        $builder->add('renewalMonths', ChoiceType::class, ['label' => 'Rappel de renouvellement (tableau de bord)', 'choices' => $choices, 'attr' => ['class' => 'form-select'], 'help' => 'À partir de la date d’émission. Préparez la nouvelle facture, puis marquez le rappel comme traité.'])
            ->add('reminderMonths', ChoiceType::class, ['label' => 'Relance automatique des impayés (e-mail au client)', 'choices' => $choices, 'attr' => ['class' => 'form-select'], 'help' => 'Première relance après la période choisie depuis l’émission, et uniquement après l’échéance. Les suivantes sont espacées de cette même période, tant qu’un solde reste dû.']);
    }
}
