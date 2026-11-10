<?php

namespace Tests\Feature\Agent;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SupportTicket;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSeederTest extends TestCase
{
	use RefreshDatabase;

	public function test_seeds_fifty_numbered_orders_with_items_and_three_tickets(): void
	{
		$this->seed(OrderSeeder::class);

		$this->assertSame(50, Order::count());
		$this->assertSame('ORD-10001', Order::orderBy('id')->first()->order_number);
		$this->assertSame('ORD-10050', Order::orderByDesc('id')->first()->order_number);
		$this->assertSame(0, Order::doesntHave('items')->count());
		$this->assertSame(3, SupportTicket::count());
	}

	public function test_status_pattern_and_totals_are_consistent(): void
	{
		$this->seed(OrderSeeder::class);

		$this->assertSame(OrderStatus::Pending, Order::where('order_number', 'ORD-10008')->first()->status);
		$this->assertSame(OrderStatus::Cancelled, Order::where('order_number', 'ORD-10009')->first()->status);
		$this->assertNotNull(Order::where('order_number', 'ORD-10003')->first()->tracking_number);

		Order::with('items')->get()->each(function (Order $order) {
			$this->assertSame(
				$order->items->sum(fn ($item) => $item->quantity * $item->unit_price_cents),
				$order->total_cents,
			);
		});
	}

	public function test_seeded_values_are_fixed(): void
	{
		$this->seed(OrderSeeder::class);

		$order = Order::with('items')->where('order_number', 'ORD-10003')->first();

		$this->assertSame('1ZLS480000023757', $order->tracking_number);
		$this->assertSame('2026-10-10', $order->placed_at->toDateString());
		$this->assertSame('2026-10-12', $order->shipped_at->toDateString());
		$this->assertSame(1, $order->items->count());
	}

	public function test_seeding_is_repeatable(): void
	{
		$this->seed(OrderSeeder::class);
		$first = Order::orderBy('id')->pluck('total_cents', 'order_number')->all();

		SupportTicket::query()->delete();
		Order::query()->delete();
		$this->seed(OrderSeeder::class);

		$this->assertSame($first, Order::orderBy('id')->pluck('total_cents', 'order_number')->all());
	}
}
