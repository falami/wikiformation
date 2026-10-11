<?php

declare(strict_types=1);
namespace App\Tests\Integration;
use App\Form\Administrateur\TrainerTravelSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class TrainerTravelSettingsTest extends KernelTestCase
{
    public function testValidSettingsAndInvalidRateAndFrequency(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        foreach ([['0.65', 'once', true], ['-1', 'daily', false], ['11', 'daily', false], ['', 'daily', false], ['0.4', 'invalid', false]] as [$rate, $frequency, $valid]) {
            $form = $factory->create(TrainerTravelSettingsType::class, null, ['csrf_protection' => false]);
            $form->submit(['rate' => $rate, 'returnTrip' => '1', 'frequency' => $frequency]);
            self::assertSame($valid, $form->isValid());
            if ($valid) self::assertEquals(['rate' => .65, 'returnTrip' => true, 'frequency' => 'once'], $form->getData());
        }
    }
}
