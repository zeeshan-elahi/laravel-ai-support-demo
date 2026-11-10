<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\TicketReason;
use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 50 fictional orders, ORD-10001 to ORD-10050. Every value is computed from the
 * order's position, not drawn at random, so the data is identical on every run and the
 * agent eval can rely on fixed facts (Faker's seed shares PHP's global random state,
 * which other code in the process also uses).
 */
class OrderSeeder extends Seeder
{
	/**
	 * Status for each order by its position in a block of ten.
	 */
	private const STATUS_PATTERN = [
		OrderStatus::Delivered, OrderStatus::Delivered, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Paid,
		OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Pending, OrderStatus::Cancelled, OrderStatus::Refunded,
	];

	private const CUSTOMERS = [
		'Amira Haddad', 'Ben Carter', 'Chloe Martin', 'Daniel Okafor', 'Elena Rossi',
		'Farid Khan', 'Grace Liu', 'Hugo Silva', 'Isla Murray', 'Jonas Weber',
	];

	public function run(): void
	{
		$skus = array_keys(OrderItemFactory::CATALOGUE);

		for ($i = 1; $i <= 50; $i++) {
			$status = self::STATUS_PATTERN[($i - 1) % 10];
			$placed = Carbon::parse('2026-10-01 09:00:00')->addDays(($i * 3) % 30)->addHours($i % 8);
			$shipped = in_array($status, [OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Refunded], true)
				? $placed->copy()->addDays(2)
				: null;
			$delivered = in_array($status, [OrderStatus::Delivered, OrderStatus::Refunded], true)
				? $shipped->copy()->addDays(4)
				: null;

			$order = Order::factory()->create([
				'order_number' => sprintf('ORD-%05d', 10000 + $i),
				'customer_name' => self::CUSTOMERS[($i - 1) % 10],
				'customer_email' => sprintf('customer%02d@example.com', $i),
				'status' => $status,
				'tracking_number' => $shipped ? sprintf('1ZLS%012d', 480000000000 + $i * 7919) : null,
				'placed_at' => $placed,
				'shipped_at' => $shipped,
				'delivered_at' => $delivered,
			]);

			for ($k = 0; $k < ($i % 3) + 1; $k++) {
				$sku = $skus[($i * 5 + $k * 7) % count($skus)];
				[$name, $price] = OrderItemFactory::CATALOGUE[$sku];

				$order->items()->save(OrderItem::factory()->make([
					'sku' => $sku,
					'product_name' => $name,
					'quantity' => (($i + $k) % 3) + 1,
					'unit_price_cents' => $price,
				]));
			}

			$order->recalculateTotal();
		}

		// Three tickets already open, so "is there a ticket for this order?" has real answers.
		$tickets = [
			'ORD-10001' => [TicketReason::DamagedItem, 'Item arrived damaged in transit.'],
			'ORD-10004' => [TicketReason::WrongItem, 'Received a different item from the one ordered.'],
			'ORD-10007' => [TicketReason::RefundRequest, 'Customer asked to return the order for a refund.'],
		];

		foreach ($tickets as $orderNumber => [$reason, $summary]) {
			Order::where('order_number', $orderNumber)->firstOrFail()->supportTickets()->create([
				'reason' => $reason,
				'summary' => $summary,
				'status' => TicketStatus::Open,
			]);
		}
	}
}
