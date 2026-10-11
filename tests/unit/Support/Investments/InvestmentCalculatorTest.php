<?php

/*
 * InvestmentCalculatorTest.php
 *
 * Custom addition (not part of upstream Firefly III): tests for the "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace Tests\unit\Support\Investments;

use FireflyIII\Support\Investments\InvestmentCalculator;
use FireflyIII\Support\Investments\InvestmentFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @group unit-test
 * @group investments
 *
 * @internal
 *
 * @coversNothing
 */
final class InvestmentCalculatorTest extends TestCase
{
    public function testSummarize(): void
    {
        $rows   = [
            1 => ['name' => 'A', 'value' => '750', 'previous' => '500'],
            2 => ['name' => 'B', 'value' => '250', 'previous' => '500'],
        ];
        $result = InvestmentCalculator::summarize($rows);

        // other keys are kept.
        $this->assertSame('A', $result['rows'][1]['name']);

        // percentage of total
        $this->assertSame(0, bccomp('75', $result['rows'][1]['share'], 12));
        $this->assertSame(0, bccomp('25', $result['rows'][2]['share'], 12));

        // change vs. previous
        $this->assertSame(0, bccomp('250', $result['rows'][1]['change'], 12));
        $this->assertSame(0, bccomp('50', $result['rows'][1]['change_pct'], 12));
        $this->assertSame(0, bccomp('-250', $result['rows'][2]['change'], 12));
        $this->assertSame(0, bccomp('-50', $result['rows'][2]['change_pct'], 12));

        // totals
        $this->assertSame(0, bccomp('1000', $result['total']['value'], 12));
        $this->assertSame(0, bccomp('1000', $result['total']['previous'], 12));
        $this->assertSame(0, bccomp('0', $result['total']['change'], 12));
        $this->assertSame(0, bccomp('0', $result['total']['change_pct'], 12));
    }

    public function testSummarizeWithZeroes(): void
    {
        $result = InvestmentCalculator::summarize([1 => ['value' => '0', 'previous' => '0']]);

        $this->assertNull($result['rows'][1]['share']);
        $this->assertNull($result['rows'][1]['change_pct']);
        $this->assertNull($result['total']['change_pct']);
    }

    public function testSummarizeEmpty(): void
    {
        $result = InvestmentCalculator::summarize([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, bccomp('0', $result['total']['value'], 12));
    }

    public function testNewAccountHasNoChangePercentage(): void
    {
        // an account that did not exist 30 days ago: the change is the full value, but there is no percentage.
        $result = InvestmentCalculator::summarize([1 => ['value' => '100', 'previous' => '0']]);

        $this->assertSame(0, bccomp('100', $result['rows'][1]['change'], 12));
        $this->assertNull($result['rows'][1]['change_pct']);
    }

    public function testPercentageOfNegativeBase(): void
    {
        // from -100 to -50 is an improvement of 50%.
        $this->assertSame(0, bccomp('50', (string) InvestmentCalculator::percentage('50', '-100', true), 12));
        $this->assertSame(0, bccomp('-50', (string) InvestmentCalculator::percentage('50', '-100'), 12));
    }

    public function testGroupBy(): void
    {
        $rows   = [
            ['broker' => 'X', 'value' => '10'],
            ['broker' => 'Y', 'value' => '50'],
            ['broker' => 'X', 'value' => '30'],
            ['broker' => null, 'value' => '5'],
            ['broker' => '  ', 'value' => '1'],
        ];
        $result = InvestmentCalculator::groupBy($rows, 'broker', '(none)');

        // largest first
        $this->assertSame(['Y', 'X', '(none)'], array_keys($result));
        $this->assertSame(0, bccomp('50', $result['Y'], 12));
        $this->assertSame(0, bccomp('40', $result['X'], 12));
        $this->assertSame(0, bccomp('6', $result['(none)'], 12));
    }

    public function testFormatter(): void
    {
        $this->assertSame('1,234.5', InvestmentFormatter::number('1234.5', 6, 'en_US'));
        $this->assertSame('1.234,5', InvestmentFormatter::number('1234.5', 6, 'de_DE'));
        $this->assertSame('12', InvestmentFormatter::number('12', 6, 'en_US'));
        $this->assertSame('', InvestmentFormatter::number(null, 6, 'en_US'));

        $this->assertSame('12.3%', InvestmentFormatter::percentage('12.34', false, 'en_US'));
        $this->assertSame('+12.3%', InvestmentFormatter::percentage('12.34', true, 'en_US'));
        $this->assertSame('-12.3%', InvestmentFormatter::percentage('-12.34', true, 'en_US'));
        $this->assertSame('0.0%', InvestmentFormatter::percentage('0.01', true, 'en_US'));
        $this->assertSame('–', InvestmentFormatter::percentage(null, true, 'en_US'));
        $this->assertStringContainsString('12,3', InvestmentFormatter::percentage('12.34', false, 'de_DE'));
    }
}
