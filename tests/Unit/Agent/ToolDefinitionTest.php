<?php

namespace Tests\Unit\Agent;

use App\Agent\Tools\CreateSupportTicketTool;
use App\Agent\Tools\GetOrderTool;
use App\Enums\TicketReason;
use PHPUnit\Framework\TestCase;

class ToolDefinitionTest extends TestCase
{
	public function test_get_order_definition_is_strict_with_one_required_field(): void
	{
		$definition = (new GetOrderTool())->definition();

		$this->assertSame('get_order', $definition['name']);
		$this->assertTrue($definition['strict']);
		$this->assertFalse($definition['inputSchema']['additionalProperties']);
		$this->assertSame(['order_number'], $definition['inputSchema']['required']);
	}

	public function test_create_ticket_definition_limits_reason_to_the_enum(): void
	{
		$definition = (new CreateSupportTicketTool())->definition();

		$this->assertTrue($definition['strict']);
		$this->assertSame(['order_number', 'reason', 'summary'], $definition['inputSchema']['required']);
		$this->assertSame(TicketReason::values(), $definition['inputSchema']['properties']['reason']['enum']);
	}
}
