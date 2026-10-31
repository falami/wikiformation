<?php

namespace App\Tests\Unit;

use App\Form\TrainerExpertiseField;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Test\TypeTestCase;

final class TrainerExpertiseFieldTest extends TypeTestCase
{
    private function expertiseForm(?string $stored = null): \Symfony\Component\Form\FormInterface
    {
        $builder = $this->factory->createBuilder(FormType::class, ['certifications' => $stored]);
        TrainerExpertiseField::attach($builder);
        return $builder->getForm();
    }

    public function testCatalogueSelectionRoundTrips(): void
    {
        $form = $this->expertiseForm("AIPR\n5S");
        self::assertSame(['AIPR', '5S'], $form->get('certifications')->getViewData());
        $form->submit(['certifications' => ['AIPR', 'Bureautique']]);
        self::assertTrue($form->isValid());
        self::assertSame("AIPR\nBureautique", $form->getData()['certifications']);
    }

    public function testFreeTextIsRejected(): void
    {
        $form = $this->expertiseForm();
        $form->submit(['certifications' => ['Invented arbitrary expertise']]);
        self::assertFalse($form->isValid());
    }

    public function testLegacyValueIsPreservedButNotAvailableOnOtherRecords(): void
    {
        $legacy = 'STCW, Médical, etc.';
        $form = $this->expertiseForm($legacy);
        $form->submit(['certifications' => [$legacy, 'AIPR']]);
        self::assertTrue($form->isValid());
        self::assertSame($legacy."\nAIPR", $form->getData()['certifications']);
        $other = $this->expertiseForm();
        $other->submit(['certifications' => [$legacy]]);
        self::assertFalse($other->isValid());
    }

    public function testEntityMappingPreservesExistingSelection(): void
    {
        $trainer = (new \App\Entity\Formateur())->setCertifications('AIPR');
        $builder = $this->factory->createBuilder(FormType::class, $trainer, ['data_class' => \App\Entity\Formateur::class]);
        TrainerExpertiseField::attach($builder);
        $form = $builder->getForm();
        self::assertSame(['AIPR'], $form->get('certifications')->getViewData());
        $form->submit(['certifications' => ['Bureautique']]);
        self::assertTrue($form->isValid());
        self::assertSame('Bureautique', $trainer->getCertifications());
    }

    public function testSelectionCanBeCleared(): void
    {
        $form = $this->expertiseForm('AIPR');
        $form->submit(['certifications' => []]);
        self::assertTrue($form->isValid());
        self::assertNull($form->getData()['certifications']);
    }
}
