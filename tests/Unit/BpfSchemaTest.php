<?php

declare(strict_types=1);
namespace App\Tests\Unit;
use App\Service\Bpf\BpfSchema;
use PHPUnit\Framework\TestCase;
final class BpfSchemaTest extends TestCase
{
    public function testUnknownValuesAreNotZeroAndProductsAreNotCountedTwice(): void
    {
        self::assertNull(BpfSchema::totals([])['c_total']);
        $d = [];
        foreach (BpfSchema::fields() as $key => [, $type]) if (in_array($type, ['money','integer','percent'], true)) $d[$key] = 0;
        $d['c_a'] = 100; $d['c_h'] = 50; $d['c_1'] = 25; $d['c_10'] = 75;
        self::assertSame(150, BpfSchema::totals($d)['c_2']);
        self::assertSame(250, BpfSchema::totals($d)['c_total']);
        $d['f3_a_count'] = 3; $d['f3_a68_count'] = 3;
        self::assertSame(3, BpfSchema::totals($d)['f3_count']);
    }
    public function testConsistencyChecksCatchMismatchedBreakdowns(): void
    {
        $d = [];
        foreach (BpfSchema::fields() as $key => [, $type]) if (in_array($type, ['money','integer','percent'], true)) $d[$key] = 0;
        $d['f1_a_count'] = 2; $d['f2_count'] = 3; $d['d_salary'] = 20;
        $d['f4_1_count'] = 1; $d['f4_2_count'] = 1;
        $d['f4_1_code'] = $d['f4_2_code'] = '324';
        $issues = implode(' ', BpfSchema::checks($d, new \DateTimeImmutable('2025-12-31')));
        self::assertStringContainsString('F3 ne correspondent pas à F1', $issues);
        self::assertStringContainsString('F2 dépasse F1', $issues);
        self::assertStringContainsString('charges dépassent', $issues);
        self::assertStringContainsString('regroupez les lignes', $issues);
        self::assertStringContainsString('libellé requis', $issues);
    }

    public function testOfficialNsfCodesHaveMeaningfulLabels(): void
    {
        self::assertSame(explode(' ', BpfSchema::NSF_CODES), array_values(\App\Service\Bpf\BpfNsf::choices()));
        self::assertSame('Secrétariat, bureautique', \App\Service\Bpf\BpfNsf::LABELS[324]);
    }
}
