<?php

namespace App\Invoices;

/**
 * An amount in integer minor units (fils, cents). Every currency in this demo has
 * two decimals; a zero-decimal currency (JPY) would need a scale per currency.
 */
final readonly class Money
{
	public function __construct(public int $minor)
	{
	}

	/**
	 * From a decimal amount as the model returns it (1853.15 or "1853.15").
	 */
	public static function fromDecimal(int|float|string $amount): self
	{
		if (is_string($amount) && ! is_numeric($amount)) {
			throw new \InvalidArgumentException("Not a number: {$amount}");
		}

		return new self((int) round((float) $amount * 100));
	}

	public function toDecimal(): float
	{
		return round($this->minor / 100, 2);
	}

	public function plus(self $other): self
	{
		return new self($this->minor + $other->minor);
	}

	public function minus(self $other): self
	{
		return new self($this->minor - $other->minor);
	}

	/**
	 * True when the two amounts differ by at most $tolerance minor units.
	 */
	public function equalsWithin(self $other, int $tolerance): bool
	{
		return abs($this->minor - $other->minor) <= $tolerance;
	}

	public function format(string $currency): string
	{
		return $currency . ' ' . number_format($this->minor / 100, 2, '.', ',');
	}
}
