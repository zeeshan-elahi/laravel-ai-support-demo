<?php

namespace App\Enums;

enum TicketReason: string
{
	case DamagedItem = 'damaged_item';
	case WrongItem = 'wrong_item';
	case LateDelivery = 'late_delivery';
	case RefundRequest = 'refund_request';
	case Other = 'other';

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
