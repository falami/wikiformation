<?php

namespace App\Tests\Service\Filter;

use App\Service\Filter\ChoiceFilter;
use PHPUnit\Framework\TestCase;

final class ChoiceFilterMatchTest extends TestCase
{
    public function testUnrestrictedIncludesAssignedAndUnassignedDays(): void
    {
        self::assertTrue(ChoiceFilter::matches('all', 12));
        self::assertTrue(ChoiceFilter::matches('all', null));
        self::assertTrue(ChoiceFilter::matches('', 12));
    }

    public function testSingleAndMultipleTrainerSelections(): void
    {
        self::assertTrue(ChoiceFilter::matches('12', 12));
        self::assertFalse(ChoiceFilter::matches('12', 13));
        self::assertTrue(ChoiceFilter::matches('["12","13"]', 13));
        self::assertFalse(ChoiceFilter::matches('["12","13"]', 14));
        self::assertFalse(ChoiceFilter::matches('["12","13"]', null));
    }

    public function testEmptyAndMalformedSelectionsNeverIncludeDays(): void
    {
        self::assertFalse(ChoiceFilter::matches('[]', 12));
        self::assertFalse(ChoiceFilter::matches('[invalid', 12));
    }
}
