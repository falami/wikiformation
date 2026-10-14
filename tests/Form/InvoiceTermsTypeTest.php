<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Enum\ModePaiement;
use App\Form\Administrateur\InvoiceTermsType;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

final class InvoiceTermsTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    public function testKnownPaymentAndVatAreStoredAsCompatibleScalars(): void
    {
        $form = $this->factory->create(InvoiceTermsType::class);
        $form->submit(['paymentMeans' => ModePaiement::PRELEVEMENT->invoiceLabel(), 'vatCategory' => 'S', 'vatOnDebits' => '0']);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('Prélèvement bancaire', $form->getData()['paymentMeans']);
        self::assertSame('S', $form->getData()['vatCategory']);
        self::assertFalse($form->getData()['vatOnDebits']);
    }

    public function testArbitrarySubmittedChoicesAreRejected(): void
    {
        $form = $this->factory->create(InvoiceTermsType::class);
        $form->submit(['paymentMeans' => 'inventé', 'vatCategory' => 'INVALID']);
        self::assertFalse($form->isValid());
        self::assertFalse($form->get('paymentMeans')->isSynchronized());
        self::assertFalse($form->get('vatCategory')->isSynchronized());
    }

    public function testExistingWordingSurvivesEditing(): void
    {
        foreach (['Virement', 'virement', 'Règlement contractuel historique'] as $stored) {
            $form = $this->factory->create(InvoiceTermsType::class, ['paymentMeans' => $stored]);
            $form->createView();
            $form->submit(['paymentMeans' => $stored]);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame($stored, $form->getData()['paymentMeans']);
        }
    }
}
