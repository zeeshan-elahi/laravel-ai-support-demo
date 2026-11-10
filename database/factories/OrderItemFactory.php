<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
	/**
	 * Fictional catalogue: SKU => [product name, unit price in cents].
	 */
	public const CATALOGUE = [
		'MUG-11-WHT' => ['Ceramic Mug 11 oz, White', 1299],
		'TEE-CTN-BLK' => ['Cotton T-Shirt, Black', 1999],
		'TEE-CTN-NVY' => ['Cotton T-Shirt, Navy', 1999],
		'HOOD-FLC-GRY' => ['Fleece Hoodie, Grey', 4499],
		'CAP-TWL-RED' => ['Twill Cap, Red', 1599],
		'BTL-STL-750' => ['Steel Water Bottle 750 ml', 2499],
		'BAG-TOTE-NAT' => ['Canvas Tote Bag, Natural', 1499],
		'NBK-A5-DOT' => ['A5 Dotted Notebook', 999],
		'PEN-GEL-BLU' => ['Gel Pen, Blue (pack of 5)', 699],
		'UMB-CMP-BLK' => ['Compact Umbrella, Black', 2299],
		'JKT-SFT-NVY' => ['Softshell Jacket, Navy', 8999],
		'PWR-10K-WHT' => ['Power Bank 10,000 mAh', 3499],
	];

	public function definition(): array
	{
		$sku = fake()->randomElement(array_keys(self::CATALOGUE));
		[$name, $price] = self::CATALOGUE[$sku];

		return [
			'sku' => $sku,
			'product_name' => $name,
			'quantity' => fake()->numberBetween(1, 4),
			'unit_price_cents' => $price,
		];
	}
}
