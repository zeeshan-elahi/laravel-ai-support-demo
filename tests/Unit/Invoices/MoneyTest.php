<?php

namespace Tests\Unit\Invoices;

use App\Invoices\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
	public function test_converts_decimals_to_minor_units_without_float_drift(): void
	{
		$this->assertSame(185315, Money::fromDecimal(1853.15)->minor);
		$this->assertSame(185315, Money::fromDecimal('1853.15')->minor);
		$this->assertSame(1999, Money::fromDecimal(19.99)->minor);
		$this->assertSame(176490, Money::fromDecimal(1961.00 - 196.10)->minor);
		$this->assertSame(4850000, Money::fromDecimal(48500)->minor);
	}

	public function test_rejects_a_non_numeric_string(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		Money::fromDecimal('AED 12.00');
	}

	public function test_arithmetic_and_tolerance(): void
	{
		$a = new Money(8825);

		$this->assertSame(9825, $a->plus(new Money(1000))->minor);
		$this->assertSame(7825, $a->minus(new Money(1000))->minor);
		$this->assertTrue($a->equalsWithin(new Money(8824), 1));
		$this->assertFalse($a->equalsWithin(new Money(8823), 1));
	}

	public function test_formats_and_returns_a_decimal(): void
	{
		$this->assertSame('AED 48,500.00', (new Money(4850000))->format('AED'));
		$this->assertSame(88.25, (new Money(8825))->toDecimal());
	}
}
