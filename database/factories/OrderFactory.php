<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
	public function definition(): array
	{
		return [
			'order_number' => 'ORD-'.fake()->unique()->numberBetween(10000, 99999),
			'customer_name' => fake()->name(),
			'customer_email' => fake()->unique()->safeEmail(),
			'status' => OrderStatus::Paid,
			'currency' => 'USD',
			'total_cents' => 0,
			'tracking_number' => null,
			'placed_at' => Carbon::parse('2026-11-01 10:00:00')->subDays(fake()->numberBetween(1, 45)),
			'shipped_at' => null,
			'delivered_at' => null,
		];
	}

	public function pending(): static
	{
		return $this->state(fn () => ['status' => OrderStatus::Pending]);
	}

	public function shipped(): static
	{
		return $this->state(fn (array $attributes) => [
			'status' => OrderStatus::Shipped,
			'shipped_at' => Carbon::parse($attributes['placed_at'])->addDays(2),
			'tracking_number' => '1Z'.fake()->numerify('################'),
		]);
	}

	public function delivered(): static
	{
		return $this->shipped()->state(fn (array $attributes) => [
			'status' => OrderStatus::Delivered,
			'delivered_at' => Carbon::parse($attributes['shipped_at'])->addDays(4),
		]);
	}

	public function cancelled(): static
	{
		return $this->state(fn () => ['status' => OrderStatus::Cancelled]);
	}

	public function refunded(): static
	{
		return $this->delivered()->state(fn () => ['status' => OrderStatus::Refunded]);
	}
}
