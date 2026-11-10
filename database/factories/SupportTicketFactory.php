<?php

namespace Database\Factories;

use App\Enums\TicketReason;
use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
	public function definition(): array
	{
		return [
			'reason' => fake()->randomElement(TicketReason::cases()),
			'summary' => fake()->sentence(12),
			'status' => TicketStatus::Open,
		];
	}
}
