<?php
declare(strict_types=1);
namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{DateType, TextType, TextareaType, ChoiceType, MoneyType};
use Symfony\Component\Validator\Constraints as Assert;

/** Explicit invoice statements; empty values never imply a tax exemption. */
final class InvoiceTermsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['dueDate' => 'Date d’échéance', 'serviceDate' => 'Date de prestation'] as $name => $label) {
            $builder->add($name, DateType::class, ['label' => $label, 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'attr' => ['class' => 'form-control flatpickr-date']]);
        }
        foreach (['paymentMeans' => 'Mode de règlement', 'earlyPaymentDiscount' => 'Conditions d’escompte', 'latePaymentPenalty' => 'Conditions et taux de pénalités de retard', 'purchaseOrderReference' => 'Référence du bon de commande (si fourni)'] as $name => $label) {
            $builder->add($name, TextType::class, ['label' => $label, 'required' => false, 'constraints' => [new Assert\Length(max: 500)], 'attr' => ['class' => 'form-control']]);
        }
        $builder->add('recoveryCompensationCents', MoneyType::class, ['label' => 'Indemnité forfaitaire de recouvrement B2B (€)', 'required' => false, 'divisor' => 100, 'input' => 'integer', 'currency' => 'EUR', 'constraints' => [new Assert\Range(min: 0, max: 4000)]]);
        $builder->add('vatCategory', ChoiceType::class, ['label' => 'Régime de TVA des prestations', 'required' => false, 'placeholder' => 'À confirmer selon votre situation fiscale', 'choices' => ['TVA normale' => 'S', 'Exonération' => 'E', 'Taux zéro' => 'Z', 'Autoliquidation' => 'AE', 'Hors champ TVA' => 'O'], 'attr' => ['class' => 'form-select delegation-select']]);
        $builder->add('vatExemptionReason', TextareaType::class, ['label' => 'Base légale et motif des lignes sans TVA', 'required' => false, 'help' => 'Renseignez la mention applicable à votre régime ; la plateforme ne présume pas votre exonération.', 'constraints' => [new Assert\Length(max: 2000)], 'attr' => ['class' => 'form-control', 'rows' => 2]]);
        $builder->add('legalIdentity', TextareaType::class, ['label' => 'Identité juridique et immatriculation de l’émetteur', 'required' => false, 'help' => 'Mentions applicables : forme juridique, capital social, registre et ville d’immatriculation, ou mention EI.', 'constraints' => [new Assert\Length(max: 2000)], 'attr' => ['class' => 'form-control', 'rows' => 2]]);
        $builder->add('vatOnDebits', ChoiceType::class, ['label' => 'Exigibilité de la TVA', 'required' => false, 'placeholder' => 'À confirmer selon votre situation fiscale', 'choices' => ['Sur les encaissements' => false, 'D’après les débits' => true], 'attr' => ['class' => 'form-select delegation-select']]);
    }
}
