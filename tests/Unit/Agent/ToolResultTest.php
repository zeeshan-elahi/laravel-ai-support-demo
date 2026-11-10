<?php

namespace Tests\Unit\Agent;

use App\Agent\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
	public function test_ok_result_serialises_its_payload(): void
	{
		$result = ToolResult::ok(['order_number' => 'ORD-10001', 'status' => 'delivered']);

		$this->assertFalse($result->isError);
		$this->assertSame('{"order_number":"ORD-10001","status":"delivered"}', $result->toContent());
	}

	public function test_error_result_carries_message_and_details(): void
	{
		$result = ToolResult::error('Invalid input.', ['order_number' => ['The order number format is invalid.']]);

		$this->assertTrue($result->isError);
		$this->assertSame('Invalid input.', $result->payload['error']);
		$this->assertSame(['The order number format is invalid.'], $result->payload['details']['order_number']);
	}

	public function test_error_result_without_details_has_no_details_key(): void
	{
		$this->assertArrayNotHasKey('details', ToolResult::error('Not found.')->payload);
	}
}
