<?php

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Enums\TicketReason;
use App\Models\Order;
use App\Models\SupportTicket;
use Illuminate\Validation\Rule;

class CreateSupportTicketTool extends Tool
{
	public function name(): string
	{
		return 'create_support_ticket';
	}

	protected function description(): string
	{
		return 'Open a support ticket for an order the customer named. Use it only when the customer asks for a ticket, '
			.'a return, a refund or a replacement. The summary restates only what the customer said, adding nothing.';
	}

	protected function properties(): array
	{
		return [
			'order_number' => [
				'type' => 'string',
				'description' => 'The order number, for example ORD-10001.',
			],
			'reason' => [
				'type' => 'string',
				'enum' => TicketReason::values(),
				'description' => 'Why the ticket is opened.',
			],
			'summary' => [
				'type' => 'string',
				'description' => 'One or two sentences describing the problem (10 to 500 characters).',
			],
		];
	}

	protected function rules(): array
	{
		return [
			'order_number' => ['required', 'string', GetOrderTool::ORDER_NUMBER_RULE, Rule::exists('orders', 'order_number')],
			'reason' => ['required', Rule::enum(TicketReason::class)],
			'summary' => ['required', 'string', 'min:10', 'max:500'],
		];
	}

	protected function handle(array $input, ToolContext $context): ToolResult
	{
		// Enforced here, not left to the model: a ticket needs the customer's own request
		// and an order number the customer wrote, whatever any document or tool result says.
		if (! $context->asksForTicket()) {
			return ToolResult::error('The customer has not asked for a ticket. Offer to open one instead.');
		}

		if (! $context->mentionsOrder($input['order_number'])) {
			return ToolResult::error("The customer did not mention {$input['order_number']}. Only open tickets for orders the customer named.");
		}

		$order = Order::where('order_number', $input['order_number'])->firstOrFail();

		// One ticket per order and reason: the unique index plus createOrFirst make a
		// repeated or parallel call return the existing ticket instead of a duplicate.
		$ticket = $order->supportTickets()->createOrFirst(
			['reason' => $input['reason']],
			['summary' => $input['summary'], 'requested_by_user' => true],
		);

		return ToolResult::ok([
			'ticket_id' => $ticket->id,
			'order_number' => $order->order_number,
			'reason' => $ticket->reason->value,
			'status' => $ticket->status->value,
			'already_existed' => ! $ticket->wasRecentlyCreated,
		]);
	}
}
