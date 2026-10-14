<?php
declare(strict_types=1);
namespace App\Form\Administrateur;

use App\Enum\{ModePaiement, InvoiceVatCategory, InvoiceVatExigibility};
use Symfony\Component\Form\{FormEvent, FormEvents};
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{DateType, TextType, TextareaType, ChoiceType, MoneyType};
use Symfony\Component\Validator\Constraints as Assert;

/** Explicit invoice statements; empty values never imply a tax exemption. */
final class InvoiceTermsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData() ?? [];
            $choices = ModePaiement::invoiceChoices();
            $stored = $data['paymentMeans'] ?? null;
            if (is_string($stored) && $stored !== '' && !in_array($stored, $choices, true)) {
                // Retain old wording without permitting new arbitrary payment methods.
                $mode = ModePaiement::fromInvoiceValue($stored);
                $choices[$mode ? $mode->invoiceLabel().' (valeur enregistrée)' : 'Ancienne valeur : '.$stored] = $stored;
            }
            $event->getForm()->add('paymentMeans', ChoiceType::class, [
                'label' => 'Mode de règlement', 'required' => false,
                'placeholder' => 'Sélectionner un mode de règlement',
                'choices' => $choices, 'invalid_message' => 'Sélectionnez un mode de règlement proposé.',
                'attr' => ['class' => 'form-select delegation-select'],
            ]);
        });
        foreach (['dueDate' => 'Date d’échéance', 'serviceDate' => 'Date de prestation'] as $name => $label) {
            $builder->add($name, DateType::class, ['label' => $label, 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'attr' => ['class' => 'form-control flatpickr-date']]);
        }
        foreach (['earlyPaymentDiscount' => 'Conditions d’escompte', 'latePaymentPenalty' => 'Conditions et taux de pénalités de retard', 'purchaseOrderReference' => 'Référence du bon de commande (si fourni)'] as $name => $label) {
            $builder->add($name, $name === 'latePaymentPenalty' ? TextareaType::class : TextType::class, ['label' => $label, 'required' => false, 'constraints' => [new Assert\Length(max: 500)], 'attr' => ['class' => 'form-control']]);
        }
        $builder->add('recoveryCompensationCents', MoneyType::class, ['label' => 'Indemnité forfaitaire de recouvrement B2B (€)', 'required' => false, 'divisor' => 100, 'input' => 'integer', 'currency' => 'EUR', 'constraints' => [new Assert\Range(min: 0, max: 4000)], 'attr' => ['class' => 'form-control']]);
        $builder->add('vatCategory', ChoiceType::class, ['label' => 'Régime de TVA des prestations', 'required' => false, 'placeholder' => 'À confirmer selon votre situation fiscale', 'choices' => InvoiceVatCategory::choices(), 'attr' => ['class' => 'form-select delegation-select']]);
        $builder->add('vatExemptionReason', TextareaType::class, ['label' => 'Base légale et motif des lignes sans TVA', 'required' => false, 'help' => 'Renseignez la mention applicable à votre régime ; la plateforme ne présume pas votre exonération.', 'constraints' => [new Assert\Length(max: 2000)], 'attr' => ['class' => 'form-control', 'rows' => 2]]);
        $builder->add('legalIdentity', TextareaType::class, ['label' => 'Identité juridique et immatriculation de l’émetteur', 'required' => false, 'help' => 'Mentions applicables : forme juridique, capital social, registre et ville d’immatriculation, ou mention EI.', 'constraints' => [new Assert\Length(max: 2000)], 'attr' => ['class' => 'form-control', 'rows' => 2]]);
        $builder->add('vatOnDebits', ChoiceType::class, ['label' => 'Exigibilité de la TVA', 'required' => false, 'placeholder' => 'À confirmer selon votre situation fiscale', 'choices' => InvoiceVatExigibility::choices(), 'attr' => ['class' => 'form-select delegation-select']]);
    }
}
