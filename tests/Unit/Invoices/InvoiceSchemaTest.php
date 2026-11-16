<?php

namespace Tests\Unit\Invoices;

use App\Invoices\InvoiceSchema;
use PHPUnit\Framework\TestCase;

class InvoiceSchemaTest extends TestCase
{
	public function test_wraps_the_schema_as_a_json_schema_format(): void
	{
		$format = InvoiceSchema::format();

		$this->assertSame('json_schema', $format['type']);
		$this->assertSame(InvoiceSchema::schema(), $format['schema']);
	}

	public function test_every_object_is_closed_and_requires_all_its_properties(): void
	{
		$objects = 0;

		$this->walk(InvoiceSchema::schema(), function (array $node) use (&$objects) {
			if (($node['type'] ?? null) !== 'object') {
				return;
			}
			$objects++;
			$this->assertFalse($node['additionalProperties']);
			$this->assertSame(array_keys($node['properties']), $node['required']);
		});

		// Root, supplier, customer, line item.
		$this->assertSame(4, $objects);
	}

	public function test_uses_no_keywords_structured_outputs_rejects(): void
	{
		$unsupported = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern'];

		$this->walk(InvoiceSchema::schema(), function (array $node) use ($unsupported) {
			foreach ($unsupported as $keyword) {
				$this->assertArrayNotHasKey($keyword, $node);
			}
		});
	}

	public function test_document_type_is_an_enum_of_the_two_cases(): void
	{
		$this->assertSame(['invoice', 'not_invoice'], InvoiceSchema::schema()['properties']['document_type']['enum']);
	}

	public function test_optional_fields_are_nullable_through_any_of(): void
	{
		$properties = InvoiceSchema::schema()['properties'];

		foreach (['invoice_number', 'issue_date', 'due_date', 'currency', 'subtotal', 'discount', 'vat_rate', 'vat_amount', 'total'] as $field) {
			$this->assertContains(['type' => 'null'], $properties[$field]['anyOf'], $field);
		}
		$this->assertSame('date', $properties['due_date']['anyOf'][0]['format']);
	}

	/**
	 * Visit every array node of the schema.
	 */
	private function walk(array $node, callable $visit): void
	{
		$visit($node);

		foreach ($node as $value) {
			if (is_array($value)) {
				$this->walk($value, $visit);
			}
		}
	}
}
