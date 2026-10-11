<?php

/*
 * InvestmentNoteParserTest.php
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

use FireflyIII\Support\Investments\InvestmentNoteParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @group unit-test
 * @group investments
 *
 * @internal
 *
 * @coversNothing
 */
final class InvestmentNoteParserTest extends TestCase
{
    public function testFullNotes(): void
    {
        $notes  = "Some text about this account.\ninvestment: true\nbroker: Trade Republic\nisin: IE00B4L5Y983\nshares: 12.5\n";
        $result = new InvestmentNoteParser()->parse($notes);

        $this->assertSame(
            ['broker' => 'Trade Republic', 'isin' => 'IE00B4L5Y983', 'isin_valid' => true, 'shares' => '12.5', 'shares_raw' => '12.5'],
            $result
        );
    }

    public function testOnlyInvestmentLine(): void
    {
        $result = new InvestmentNoteParser()->parse('investment: true');

        $this->assertSame(['broker' => null, 'isin' => null, 'isin_valid' => false, 'shares' => null, 'shares_raw' => null], $result);
    }

    #[DataProvider('provideNotInvestments')]
    public function testNotAnInvestment(?string $notes): void
    {
        $this->assertNull(new InvestmentNoteParser()->parse($notes));
    }

    public static function provideNotInvestments(): iterable
    {
        yield 'null'               => [null];

        yield 'empty'              => [''];

        yield 'no flag'            => ["broker: X\nshares: 3"];

        yield 'false'              => ['investment: false'];

        yield 'no'                 => ['investment: no'];

        yield 'other text'         => ['This is my investment: it is true'];

        yield 'key inside a word'  => ['noinvestment: true'];
    }

    #[DataProvider('provideLenientNotes')]
    public function testLenientParsing(string $notes, string $broker): void
    {
        $result = new InvestmentNoteParser()->parse($notes);

        $this->assertNotNull($result);
        $this->assertSame($broker, $result['broker']);
    }

    public static function provideLenientNotes(): iterable
    {
        yield 'upper case'          => ["INVESTMENT: TRUE\nBroker: ABC", 'ABC'];

        yield 'spaces around colon' => ["  investment :   true  \n broker :  ABC  ", 'ABC'];

        yield 'equals sign'         => ["investment=yes\nbroker=ABC", 'ABC'];

        yield 'markdown list'       => ["- investment: true\n- broker: ABC", 'ABC'];

        yield 'markdown bold'       => ["**investment**: true\n* **broker:** ABC", 'ABC'];

        yield 'windows newlines'    => ["investment: true\r\nbroker: ABC\r\n", 'ABC'];

        yield 'html line breaks'    => ['investment: true<br>broker: ABC', 'ABC'];

        yield 'quoted value'        => ["investment: \"true\"\nbroker: \"ABC\"", 'ABC'];

        yield 'value 1'             => ["investment: 1\nbroker: ABC", 'ABC'];

        yield 'first one wins'      => ["investment: true\nbroker: ABC\nbroker: DEF", 'ABC'];

        yield 'broker with spaces'  => ["investment: true\nbroker: Interactive Brokers Ireland", 'Interactive Brokers Ireland'];
    }

    public function testIsinIsCleanedUp(): void
    {
        $result = new InvestmentNoteParser()->parse("investment: true\nisin: ie00 b4l5 y983");

        $this->assertSame('IE00B4L5Y983', $result['isin']);
        $this->assertTrue($result['isin_valid']);
    }

    public function testInvalidIsinIsKept(): void
    {
        $result = new InvestmentNoteParser()->parse("investment: true\nisin: IE00B4L5Y984");

        $this->assertSame('IE00B4L5Y984', $result['isin']);
        $this->assertFalse($result['isin_valid']);
    }

    public function testInvalidSharesAreKeptAsText(): void
    {
        $result = new InvestmentNoteParser()->parse("investment: true\nshares: about ten");

        $this->assertNull($result['shares']);
        $this->assertSame('about ten', $result['shares_raw']);
    }

    #[DataProvider('provideNumbers')]
    public function testNormalizeNumber(string $input, ?string $expected): void
    {
        $this->assertSame($expected, InvestmentNoteParser::normalizeNumber($input));
    }

    public static function provideNumbers(): iterable
    {
        yield 'integer'                  => ['12', '12'];

        yield 'decimal point'            => ['12.5', '12.5'];

        yield 'decimal comma'            => ['12,5', '12.5'];

        yield 'trailing zeros'           => ['12.500', '12.5'];

        yield 'english thousands'        => ['1,234.56', '1234.56'];

        yield 'german thousands'         => ['1.234,56', '1234.56'];

        yield 'several thousands commas' => ['1,234,567', '1234567'];

        yield 'several thousands points' => ['1.234.567', '1234567'];

        yield 'space thousands'          => ['1 234,5', '1234.5'];

        yield 'swiss thousands'          => ["1'234.5", '1234.5'];

        yield 'leading dot'              => ['.5', '0.5'];

        yield 'zero'                     => ['0', '0'];

        yield 'negative'                 => ['-3.25', '-3.25'];

        yield 'plus sign'                => ['+3', '3'];

        yield 'text'                     => ['ten', null];

        yield 'with unit'                => ['10 pcs', null];

        yield 'empty'                    => ['', null];
    }

    #[DataProvider('provideIsins')]
    public function testIsValidIsin(string $isin, bool $expected): void
    {
        $this->assertSame($expected, InvestmentNoteParser::isValidIsin($isin));
    }

    public static function provideIsins(): iterable
    {
        yield 'iShares MSCI World'  => ['IE00B4L5Y983', true];

        yield 'Apple'               => ['US0378331005', true];

        yield 'SAP'                 => ['DE0007164600', true];

        yield 'wrong check digit'   => ['US0378331006', false];

        yield 'too short'           => ['US037833100', false];

        yield 'lower case'          => ['us0378331005', false];

        yield 'no country'          => ['120378331005', false];
    }
}
