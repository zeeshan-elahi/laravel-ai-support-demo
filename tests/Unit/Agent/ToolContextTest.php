<?php

namespace Tests\Unit\Agent;

use App\Agent\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToolContextTest extends TestCase
{
	public function test_finds_the_order_numbers_the_customer_wrote(): void
	{
		$context = new ToolContext('Orders ord-10002 and ORD-10007 both arrived, but not ORD-10002 again.');

		$this->assertSame(['ORD-10002', 'ORD-10007'], $context->orderNumbers());
		$this->assertTrue($context->mentionsOrder('ORD-10007'));
		$this->assertFalse($context->mentionsOrder('ORD-10010'));
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function questions(): array
	{
		return [
			'open a ticket' => ['The items in ORD-10002 arrived damaged. Please open a ticket for me.', true],
			'wants a refund' => ['I want a refund for ORD-10007.', true],
			'replacement please' => ['Wrong colour in ORD-10004, replacement please.', true],
			'status question' => ['Where is my order ORD-10003?', false],
			'policy question' => ['Can I return something after 30 days?', false],
			'warranty question' => ['What is the warranty on power banks?', false],
		];
	}

	#[DataProvider('questions')]
	public function test_recognises_a_request_for_a_ticket(string $question, bool $expected): void
	{
		$this->assertSame($expected, (new ToolContext($question))->asksForTicket());
	}
}
