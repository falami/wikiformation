<?php

namespace App\Tests\Unit;

use App\Form\Administrateur\CategorieType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;

final class CategorieSlugTest extends TestCase
{
    public function testBlankSlugIsGeneratedAndCustomSlugIsPreserved(): void
    {
        $listener = null;
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnSelf();
        $builder->method('addEventListener')->willReturnCallback(function ($event, $callback) use (&$listener, $builder) {
            $listener = $callback;
            return $builder;
        });
        (new CategorieType())->buildForm($builder, []);
        foreach ([
            [['nom' => 'Santé et sécurité au travail', 'slug' => ''], 'sante-et-securite-au-travail'],
            [['nom' => 'Excel avancé'], 'excel-avance'],
            [['nom' => 'Nouveau nom', 'slug' => 'mon-identifiant'], 'mon-identifiant'],
        ] as [$data, $expected]) {
            $event = new FormEvent($this->createMock(FormInterface::class), $data);
            $listener($event);
            self::assertSame($expected, $event->getData()['slug']);
        }
    }
}
