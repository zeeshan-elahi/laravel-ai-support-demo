<?php

namespace App\Agent;

/**
 * What the tools know about the request, taken from the customer's own message only.
 * Text from documents or earlier tool results never reaches here, so an instruction
 * planted in a help article cannot widen what a tool is allowed to do.
 */
final readonly class ToolContext
{
	public function __construct(
		public string $question,
	) {}

	/**
	 * Order numbers the customer wrote.
	 *
	 * @return list<string>
	 */
	public function orderNumbers(): array
	{
		preg_match_all('/\bORD-\d{5}\b/i', $this->question, $matches);

		return array_values(array_unique(array_map('strtoupper', $matches[0])));
	}

	public function mentionsOrder(string $orderNumber): bool
	{
		return in_array(strtoupper($orderNumber), $this->orderNumbers(), true);
	}

	/**
	 * Whether the customer asked for a ticket, a return, a refund or a replacement.
	 * A keyword check: coarse on purpose, it only has to stop tickets nobody asked for.
	 */
	public function asksForTicket(): bool
	{
		$patterns = [
			'/\b(open|create|raise|file|log|submit|start)\b.{0,40}\b(ticket|case|claim|complaint)\b/i',
			'/\b(i want|i\'d like|i would like|please|can you|could you)\b.{0,60}\b(return|refund|replacement|replace|exchange)\b/i',
			'/\b(return|refund|replacement|replace|exchange)\b.{0,40}\bplease\b/i',
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $this->question) === 1) {
				return true;
			}
		}

		return false;
	}
}
