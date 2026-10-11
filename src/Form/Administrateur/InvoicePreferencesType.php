<?php
declare(strict_types=1);
namespace App\Form\Administrateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{IntegerType, CheckboxType};
use Symfony\Component\Validator\Constraints as Assert;

final class InvoicePreferencesType extends AbstractType
{
    public function getParent(): string { return InvoiceTermsType::class; }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['dueDate', 'serviceDate', 'purchaseOrderReference'] as $field) $builder->remove($field);
        $builder->add('dueDays', IntegerType::class, ['label' => 'Délai d’échéance après émission (jours)', 'required' => false, 'help' => 'Ce délai est appliqué aux nouvelles factures lorsque vous ne renseignez pas une échéance particulière.', 'constraints' => [new Assert\Range(min: 0, max: 60)]]);
        $builder->add('confirmed', CheckboxType::class, ['label' => 'J’ai vérifié ces mentions et je souhaite les appliquer aux nouvelles factures de mon organisme.', 'required' => false]);
    }
}
