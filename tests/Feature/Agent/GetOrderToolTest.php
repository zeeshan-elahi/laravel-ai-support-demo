<?php

namespace Tests\Feature\Agent;

use App\Agent\ToolContext;
use App\Agent\Tools\GetOrderTool;
use App\Enums\TicketReason;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetOrderToolTest extends TestCase
{
	use RefreshDatabase;

	public function test_returns_the_order_with_items_and_open_tickets(): void
	{
		$order = Order::factory()->delivered()->create(['order_number' => 'ORD-12345']);
		$order->items()->save(OrderItem::factory()->make(['product_name' => 'Ceramic Mug 11 oz, White', 'quantity' => 2, 'unit_price_cents' => 1299]));
		$order->recalculateTotal();
		$order->supportTickets()->create(['reason' => TicketReason::DamagedItem, 'summary' => 'Handle arrived cracked.']);

		$result = (new GetOrderTool())->run(['order_number' => 'ORD-12345'], new ToolContext('Where is my order?'));

		$this->assertFalse($result->isError);
		$this->assertSame('delivered', $result->payload['status']);
		$this->assertSame('USD 25.98', $result->payload['total']);
		$this->assertSame([['product' => 'Ceramic Mug 11 oz, White', 'quantity' => 2]], $result->payload['items']);
		$this->assertSame('damaged_item', $result->payload['open_tickets'][0]['reason']);
	}

	public function test_does_not_expose_customer_details(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345', 'customer_email' => 'buyer@example.com']);

		$content = (new GetOrderTool())->run(['order_number' => 'ORD-12345'], new ToolContext('Where is my order?'))->toContent();

		$this->assertStringNotContainsString('buyer@example.com', $content);
		$this->assertStringNotContainsString('customer', $content);
	}

	public function test_rejects_a_malformed_order_number(): void
	{
		$result = (new GetOrderTool())->run(['order_number' => "ORD-1'; DROP TABLE orders;--"], new ToolContext('Where is my order?'));

		$this->assertTrue($result->isError);
		$this->assertArrayHasKey('order_number', $result->payload['details']);
	}

	public function test_reports_an_unknown_order(): void
	{
		$result = (new GetOrderTool())->run(['order_number' => 'ORD-99999'], new ToolContext('Where is my order?'));

		$this->assertTrue($result->isError);
		$this->assertSame('No order found with number ORD-99999.', $result->payload['error']);
	}
}
