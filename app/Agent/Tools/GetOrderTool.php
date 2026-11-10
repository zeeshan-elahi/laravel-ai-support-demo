<?php

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportTicket;

class GetOrderTool extends Tool
{
	public const ORDER_NUMBER_RULE = 'regex:/^ORD-\d{5}$/';

	public function name(): string
	{
		return 'get_order';
	}

	protected function description(): string
	{
		return 'Look up one order by its order number (ORD- followed by five digits, for example ORD-10001). '
			.'Returns the status, dates, tracking number, items, total and open support tickets. Read-only.';
	}

	protected function properties(): array
	{
		return [
			'order_number' => [
				'type' => 'string',
				'description' => 'The order number, for example ORD-10001.',
			],
		];
	}

	protected function rules(): array
	{
		return [
			'order_number' => ['required', 'string', self::ORDER_NUMBER_RULE],
		];
	}

	protected function handle(array $input, ToolContext $context): ToolResult
	{
		$order = Order::with(['items', 'supportTickets'])
			->where('order_number', $input['order_number'])
			->first();

		if ($order === null) {
			return ToolResult::error("No order found with number {$input['order_number']}.");
		}

		// Only what an answer about the order needs: no customer name or email.
		return ToolResult::ok([
			'order_number' => $order->order_number,
			'status' => $order->status->value,
			'placed_at' => $order->placed_at->toDateString(),
			'shipped_at' => $order->shipped_at?->toDateString(),
			'delivered_at' => $order->delivered_at?->toDateString(),
			'tracking_number' => $order->tracking_number,
			'total' => sprintf('%s %.2f', $order->currency, $order->total_cents / 100),
			'items' => $order->items->map(fn (OrderItem $item) => [
				'product' => $item->product_name,
				'quantity' => $item->quantity,
			])->all(),
			'open_tickets' => $order->supportTickets
				->filter(fn (SupportTicket $ticket) => $ticket->status === TicketStatus::Open)
				->map(fn (SupportTicket $ticket) => [
					'ticket_id' => $ticket->id,
					'reason' => $ticket->reason->value,
				])->values()->all(),
		]);
	}
}
