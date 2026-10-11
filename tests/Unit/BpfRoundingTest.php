<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Service\Bpf\BpfRounding;
use PHPUnit\Framework\TestCase;

final class BpfRoundingTest extends TestCase
{
    public function testDifferentDistributionsKeepSameRoundedTotal(): void
    {
        self::assertSame(['employees' => 2, 'individuals' => 1], BpfRounding::distribution(['employees' => 1.5, 'individuals' => 1.5]));
        self::assertSame(['other' => 3], BpfRounding::distribution(['other' => 3.0]));
        self::assertSame(5, array_sum(BpfRounding::distribution(['a' => 1.25, 'b' => 1.75, 'c' => 1.5])));
    }

    public function testRncpLevelsMatchAllocatedParentInsteadOfRoundingAgain(): void
    {
        self::assertSame(['a' => 1, 'b' => 0], BpfRounding::distribution(['a' => 0.5, 'b' => 0.5], 1));
        self::assertSame(['a' => 0, 'b' => 0], BpfRounding::distribution(['a' => 0.3, 'b' => 0.3], 0));
        self::assertSame(['a' => 0], BpfRounding::distribution(['a' => 0.0]));
    }
}
