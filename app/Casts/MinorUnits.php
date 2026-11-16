<?php

namespace App\Casts;

use App\Invoices\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Integer minor-unit column <-> Money. Only Money (or null) can be assigned: a bare
 * number is ambiguous (is 1853 fils or dirhams?), so it is refused.
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
class MinorUnits implements CastsAttributes
{
	public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
	{
		return $value === null ? null : new Money((int) $value);
	}

	public function set(Model $model, string $key, mixed $value, array $attributes): ?int
	{
		if ($value === null) {
			return null;
		}

		if (! $value instanceof Money) {
			throw new \InvalidArgumentException("{$key} must be set as a Money instance, got " . get_debug_type($value) . '.');
		}

		return $value->minor;
	}
}
