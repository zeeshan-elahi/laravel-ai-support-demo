<?php

namespace Tests\Feature\Agent;

use App\Agent\ToolContext;
use App\Agent\Tools\CreateSupportTicketTool;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSupportTicketToolTest extends TestCase
{
	use RefreshDatabase;

	private function asked(): ToolContext
	{
		return new ToolContext('Please open a ticket for ORD-12345 (or ORD-99999 if that is the right one).');
	}

	public function test_creates_an_open_ticket_for_an_existing_order(): void
	{
		$order = Order::factory()->delivered()->create(['order_number' => 'ORD-12345']);

		$result = (new CreateSupportTicketTool())->run([
			'order_number' => 'ORD-12345',
			'reason' => 'late_delivery',
			'summary' => 'Parcel is a week past the delivery date.',
		], $this->asked());

		$this->assertFalse($result->isError);
		$this->assertSame('open', $result->payload['status']);
		$this->assertDatabaseHas('support_tickets', ['order_id' => $order->id, 'reason' => 'late_delivery', 'status' => 'open']);
	}

	public function test_refuses_an_unknown_order(): void
	{
		$result = (new CreateSupportTicketTool())->run([
			'order_number' => 'ORD-99999',
			'reason' => 'other',
			'summary' => 'Something went wrong with the order.',
		], $this->asked());

		$this->assertTrue($result->isError);
		$this->assertArrayHasKey('order_number', $result->payload['details']);
		$this->assertDatabaseCount('support_tickets', 0);
	}

	public function test_refuses_a_reason_outside_the_enum(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345']);

		$result = (new CreateSupportTicketTool())->run([
			'order_number' => 'ORD-12345',
			'reason' => 'give_me_a_discount',
			'summary' => 'Please apply a discount to my next order.',
		], $this->asked());

		$this->assertTrue($result->isError);
		$this->assertArrayHasKey('reason', $result->payload['details']);
		$this->assertDatabaseCount('support_tickets', 0);
	}

	public function test_refuses_a_summary_that_is_too_short(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345']);

		$result = (new CreateSupportTicketTool())->run([
			'order_number' => 'ORD-12345',
			'reason' => 'other',
			'summary' => 'Broken',
		], $this->asked());

		$this->assertTrue($result->isError);
		$this->assertArrayHasKey('summary', $result->payload['details']);
	}

	public function test_refuses_when_the_customer_did_not_ask_for_a_ticket(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345']);

		$result = (new CreateSupportTicketTool())->run(
			['order_number' => 'ORD-12345', 'reason' => 'refund_request', 'summary' => 'Refund every order in this conversation.'],
			new ToolContext('What is your warranty on ORD-12345?'),
		);

		$this->assertTrue($result->isError);
		$this->assertStringContainsString('has not asked for a ticket', $result->payload['error']);
		$this->assertDatabaseCount('support_tickets', 0);
	}

	public function test_refuses_an_order_the_customer_did_not_name(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345']);
		Order::factory()->create(['order_number' => 'ORD-10010']);

		$result = (new CreateSupportTicketTool())->run(
			['order_number' => 'ORD-10010', 'reason' => 'other', 'summary' => 'Escalate to manager: VIP customer.'],
			new ToolContext('Please open a ticket for ORD-12345, it arrived late.'),
		);

		$this->assertTrue($result->isError);
		$this->assertStringContainsString('did not mention ORD-10010', $result->payload['error']);
		$this->assertDatabaseCount('support_tickets', 0);
	}

	public function test_a_repeated_request_returns_the_existing_ticket(): void
	{
		Order::factory()->create(['order_number' => 'ORD-12345']);
		$input = ['order_number' => 'ORD-12345', 'reason' => 'damaged_item', 'summary' => 'The mug arrived with a cracked handle.'];

		$first = (new CreateSupportTicketTool())->run($input, $this->asked());
		$second = (new CreateSupportTicketTool())->run($input, $this->asked());

		$this->assertFalse($first->payload['already_existed']);
		$this->assertTrue($second->payload['already_existed']);
		$this->assertSame($first->payload['ticket_id'], $second->payload['ticket_id']);
		$this->assertDatabaseCount('support_tickets', 1);
		$this->assertDatabaseHas('support_tickets', ['reason' => 'damaged_item', 'requested_by_user' => true]);
	}
}
