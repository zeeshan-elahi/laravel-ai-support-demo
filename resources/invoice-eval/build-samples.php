<?php

/*
| Builds the invoice-extraction sample set: 10 fictional PDFs in samples/ and one
| ground-truth JSON per file in expected/. Every company, TRN, person and amount
| here is invented. PDF and JSON come from the same data below, so they cannot
| drift apart.
|
| Run on the host, not in the container (it needs LibreOffice and poppler):
|   php resources/invoice-eval/build-samples.php
|
| Requires: soffice (LibreOffice), pdftoppm, pdftotext and pdfinfo (poppler-utils).
|
| Arithmetic used everywhere (integer fils, no floats):
|   line_total = round(quantity × unit_price)
|   subtotal   = Σ line_total
|   vat_amount = round((subtotal − discount) × vat_rate / 100)
|   total      = subtotal − discount + vat_amount
| One invoice (inv-06) prints a wrong total on purpose. Its ground truth holds
| the printed value, because extraction must report what the page says, and the
| expected status is needs_review with the failed check named.
*/

const OUT_SAMPLES = __DIR__ . '/samples';
const OUT_EXPECTED = __DIR__ . '/expected';

$work = sys_get_temp_dir() . '/invoice-samples-' . getmypid();

foreach (['soffice', 'pdftoppm', 'pdftotext', 'pdfinfo'] as $bin) {
	if (trim((string) shell_exec('command -v ' . escapeshellarg($bin))) === '') {
		fwrite(STDERR, "Missing required program: {$bin}\n");
		exit(1);
	}
}

@mkdir(OUT_SAMPLES, 0775, true);
@mkdir(OUT_EXPECTED, 0775, true);
@mkdir($work . '/html', 0775, true);
@mkdir($work . '/profile', 0775, true);

// ---------------------------------------------------------------- parties

$parties = [
	'qamar' => ['name' => 'Qamar Grove Trading LLC', 'trn' => '100234567800003', 'address' => 'Office 14, Al Qusais Industrial Area 2, Dubai, UAE', 'phone' => '+971 4 000 1418'],
	'dune' => ['name' => 'Dune Lantern IT Services FZE', 'trn' => '100345678900003', 'address' => 'Unit B-07, Business Park 3, Sharjah, UAE', 'phone' => '+971 6 000 2207'],
	'coral' => ['name' => 'Coral Spindle Printing LLC', 'trn' => '100456789000003', 'address' => 'Shop 3, Mussafah M-9, Abu Dhabi, UAE', 'phone' => '+971 2 000 3309'],
	'saltmarsh' => ['name' => 'Saltmarsh Analytics FZ-LLC', 'trn' => '100567890100003', 'address' => 'Desk 22, Innovation Hub, Dubai, UAE', 'phone' => '+971 4 000 4493'],
	'palm' => ['name' => 'Palm Ledger Catering LLC', 'trn' => '100678901200003', 'address' => 'Warehouse 5, Al Quoz 4, Dubai, UAE', 'phone' => '+971 4 000 5520'],
	'harbor' => ['name' => 'Harbor Finch Consulting FZ-LLC', 'trn' => '100987654300003', 'address' => 'Floor 9, Tower C, Business Bay, Dubai, UAE'],
	'silver' => ['name' => 'Silver Wadi Clinics LLC', 'trn' => '100555666700003', 'address' => 'Building 2, Al Barsha 1, Dubai, UAE'],
	'juniper' => ['name' => 'Juniper Kite Events', 'trn' => null, 'address' => 'Villa 41, Al Mizhar 1, Dubai, UAE'],
	'ostrava' => ['name' => 'Ostrava Freight s.r.o.', 'trn' => null, 'address' => 'Nádražní 118, 702 00 Ostrava, Czech Republic'],
	'granite' => ['name' => 'Granite Owl Real Estate LLC', 'trn' => '100789012300003', 'address' => 'Office 1203, Marina Plaza, Dubai Marina, Dubai, UAE'],
];

// ---------------------------------------------------------------- documents
// Money in fils (AED) or cents (USD). Quantities may be decimal (hours).

$inv04Lines = [];
$tasks = [
	['Laptop setup and imaging', 12000],
	['Printer driver deployment', 4500],
	['Email account configuration', 3500],
	['Backup agent installation', 6000],
];
for ($i = 1; $i <= 36; $i++) {
	[$task, $price] = $tasks[($i - 1) % 4];
	$inv04Lines[] = [sprintf('%s, asset SW-%03d', $task, 100 + $i), 1, $price];
}

$docs = [
	[
		'id' => 'inv-01',
		'slug' => 'qamar-grove-office-supplies',
		'layout' => 'A',
		'type' => 'invoice',
		'title' => 'TAX INVOICE',
		'notes' => 'Layout A, clean, four lines. Dates printed dd/mm/yyyy.',
		'supplier' => 'qamar',
		'customer' => 'harbor',
		'number' => 'QG-2026-0418',
		'issue' => '2026-10-04',
		'due' => '2026-11-03',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['A4 copy paper 80gsm (box of 5 reams)', 12, 9500],
			['Ballpoint pens, blue (pack of 50)', 4, 4250],
			['Desk organiser, mesh', 6, 3875],
			['Whiteboard markers, assorted (pack of 12)', 5, 2700],
		],
	],
	[
		'id' => 'inv-02',
		'slug' => 'qamar-grove-discount',
		'layout' => 'A',
		'type' => 'invoice',
		'title' => 'TAX INVOICE',
		'notes' => 'Layout A, six lines and a 10% discount printed as an amount before VAT.',
		'supplier' => 'qamar',
		'customer' => 'silver',
		'number' => 'QG-2026-0431',
		'issue' => '2026-10-19',
		'due' => '2026-11-18',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount_percent' => 10,
		'lines' => [
			['Toner cartridge, black', 3, 31000],
			['Laminating pouches A4 (box of 100)', 2, 6400],
			['Archive boxes, corrugated', 20, 1150],
			['Sticky notes 3x3 (12 pads)', 8, 1825],
			['Stapler, heavy duty', 2, 8900],
			['Label printer tape 12mm', 10, 3490],
		],
	],
	[
		'id' => 'inv-03',
		'slug' => 'dune-lantern-it-services',
		'layout' => 'B',
		'type' => 'invoice',
		'title' => 'INVOICE',
		'notes' => 'Layout B, hourly line with a decimal quantity (7.5). Dates printed "12 Nov 2026".',
		'supplier' => 'dune',
		'customer' => 'harbor',
		'number' => 'DL-1107',
		'issue' => '2026-11-12',
		'due' => '2026-12-12',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['On-site network support (hours)', 7.5, 18000],
			['Firewall configuration review', 1, 145000],
			['Microsoft 365 licence migration (per user)', 14, 6500],
		],
	],
	[
		'id' => 'inv-04',
		'slug' => 'dune-lantern-multi-page',
		'layout' => 'B',
		'type' => 'invoice',
		'title' => 'INVOICE',
		'notes' => 'Layout B, 36 lines over pages 1 and 2 (no repeated column header), totals alone on page 3. Every line must be captured.',
		'supplier' => 'dune',
		'customer' => 'silver',
		'number' => 'DL-1112',
		'issue' => '2026-11-14',
		'due' => '2026-12-14',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => $inv04Lines,
	],
	[
		'id' => 'inv-05',
		'slug' => 'coral-spindle-unregistered-customer',
		'layout' => 'C',
		'type' => 'invoice',
		'title' => 'Tax Invoice',
		'notes' => 'Layout C: columns in a different order (unit price before quantity), amounts as "Dhs", customer has no TRN (expected null).',
		'supplier' => 'coral',
		'customer' => 'juniper',
		'number' => 'CSP/26/0907',
		'issue' => '2026-11-03',
		'due' => '2026-11-17',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['Roll-up banner 85 x 200 cm', 4, 24500],
			['Business cards 350gsm (box of 500)', 6, 12000],
			['A5 flyers, double-sided (1,000)', 3, 38000],
			['Event lanyards, printed', 150, 640],
		],
	],
	[
		'id' => 'inv-06',
		'slug' => 'coral-spindle-wrong-total',
		'layout' => 'C',
		'type' => 'invoice',
		'title' => 'Tax Invoice',
		'notes' => 'Layout C with a deliberate error: the printed total is 100.00 too high. Ground truth = printed values; the code checks must flag the total.',
		'supplier' => 'coral',
		'customer' => 'harbor',
		'number' => 'CSP/26/0921',
		'issue' => '2026-11-10',
		'due' => '2026-11-24',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'total_error' => 10000,
		'lines' => [
			['Annual report printing, 48 pages', 200, 1850],
			['Presentation folders, printed', 300, 725],
			['Envelopes C4, printed', 500, 110],
		],
	],
	[
		'id' => 'inv-07',
		'slug' => 'saltmarsh-usd-zero-rated',
		'layout' => 'D',
		'type' => 'invoice',
		'title' => 'Tax Invoice',
		'notes' => 'Layout D, USD, zero-rated export of services (VAT 0%), foreign customer without a TRN. Dates printed "November 7, 2026"; amounts without thousands separators.',
		'supplier' => 'saltmarsh',
		'customer' => 'ostrava',
		'number' => 'SA-0093',
		'issue' => '2026-11-07',
		'due' => '2026-12-07',
		'currency' => 'USD',
		'vat_rate' => 0,
		'vat_label' => 'VAT 0% (zero-rated export of services)',
		'discount' => 0,
		'lines' => [
			['Data pipeline maintenance, October 2026', 1, 240000],
			['Dashboard changes (hours)', 12, 5500],
		],
	],
	[
		'id' => 'inv-08',
		'slug' => 'palm-ledger-scanned',
		'layout' => 'D',
		'type' => 'invoice',
		'title' => 'Tax Invoice',
		'notes' => 'Layout D rasterised to an image-only PDF (150 dpi, greyscale): no text layer, the model must read the image. Clean image: no skew, noise or stamps.',
		'scan' => true,
		'supplier' => 'palm',
		'customer' => 'silver',
		'number' => 'PL-5520',
		'issue' => '2026-11-05',
		'due' => '2026-11-20',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['Staff lunch buffet (per person)', 45, 3800],
			['Coffee break service', 2, 35000],
			['Delivery and setup', 1, 15000],
		],
	],
	[
		'id' => 'quote-09',
		'slug' => 'qamar-grove-quotation',
		'layout' => 'A',
		'type' => 'not_invoice',
		'title' => 'QUOTATION',
		'notes' => 'Layout A styled like inv-01 but it is a quotation (Quote No., Valid until). Must be rejected, not extracted as an invoice.',
		'supplier' => 'qamar',
		'customer' => 'juniper',
		'number' => 'QG-Q-2026-077',
		'issue' => '2026-11-11',
		'valid_until' => '2026-11-25',
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['Folding chairs, white (rental, per day)', 120, 1200],
			['Round banquet tables (rental, per day)', 15, 6500],
			['Table linen, white', 15, 2200],
		],
	],
	[
		'id' => 'inv-10',
		'slug' => 'dune-lantern-no-due-date',
		'layout' => 'B',
		'type' => 'invoice',
		'title' => 'INVOICE',
		'notes' => 'Layout B, one large line (48,500.00), no due date or payment terms printed (expected null, not guessed).',
		'supplier' => 'dune',
		'customer' => 'granite',
		'number' => 'DL-1120',
		'issue' => '2026-11-16',
		'due' => null,
		'currency' => 'AED',
		'vat_rate' => 5,
		'discount' => 0,
		'lines' => [
			['Server room upgrade, project fee (milestone 2 of 3)', 1, 4850000],
		],
	],
];

// ---------------------------------------------------------------- arithmetic

function compute(array $d): array
{
	$lines = [];
	$subtotal = 0;
	foreach ($d['lines'] as [$desc, $qty, $unit]) {
		$lineTotal = (int) round($qty * $unit);
		$subtotal += $lineTotal;
		$lines[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $unit, 'line_total' => $lineTotal];
	}
	$discount = isset($d['discount_percent'])
		? (int) round($subtotal * $d['discount_percent'] / 100)
		: $d['discount'];
	$vat = (int) round(($subtotal - $discount) * $d['vat_rate'] / 100);
	$total = $subtotal - $discount + $vat;
	$printedTotal = $total + ($d['total_error'] ?? 0);

	return compact('lines', 'subtotal', 'discount', 'vat', 'total', 'printedTotal');
}

// ---------------------------------------------------------------- formatting

function money(int $minor, string $style, string $currency): string
{
	$plain = number_format($minor / 100, 2, '.', ',');

	return match ($style) {
		'A' => $plain,
		'B' => $currency . ' ' . $plain,
		'C' => ($currency === 'AED' ? 'Dhs ' : $currency . ' ') . $plain,
		'D' => number_format($minor / 100, 2, '.', ''),
	};
}

function qty(int|float $q): string
{
	return is_float($q) ? rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.') : (string) $q;
}

function dateFor(?string $iso, string $style): string
{
	if ($iso === null) {
		return '';
	}
	$t = strtotime($iso);

	return match ($style) {
		'A' => date('d/m/Y', $t),
		'B' => date('j M Y', $t),
		'C' => date('Y-m-d', $t),
		'D' => date('F j, Y', $t),
	};
}

function e(?string $s): string
{
	return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---------------------------------------------------------------- layouts

function render(array $d, array $c, array $parties): string
{
	$s = $parties[$d['supplier']];
	$b = $parties[$d['customer']];
	$L = $d['layout'];
	$cur = $d['currency'];
	$m = fn (int $v) => money($v, $L, $cur);
	$isQuote = $d['type'] !== 'invoice';
	$numLabel = $isQuote ? 'Quote No.' : 'Invoice No.';
	$vatLabel = $d['vat_label'] ?? ('VAT ' . $d['vat_rate'] . '%');
	$custTrn = $b['trn'] !== null ? 'TRN: ' . e($b['trn']) : '';

	$head = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($d['number']) . '</title></head>';

	if ($L === 'A') {
		$rows = '';
		foreach ($c['lines'] as $i => $ln) {
			$rows .= '<tr><td>' . ($i + 1) . '</td><td>' . e($ln['description']) . '</td><td align="right">' . qty($ln['quantity']) . '</td><td align="right">' . $m($ln['unit_price']) . '</td><td align="right">' . $m($ln['line_total']) . '</td></tr>';
		}
		$dateRows = $isQuote
			? '<tr><td><b>Date</b></td><td>' . dateFor($d['issue'], 'A') . '</td></tr><tr><td><b>Valid until</b></td><td>' . dateFor($d['valid_until'], 'A') . '</td></tr>'
			: '<tr><td><b>Invoice date</b></td><td>' . dateFor($d['issue'], 'A') . '</td></tr><tr><td><b>Due date</b></td><td>' . dateFor($d['due'], 'A') . '</td></tr>';
		$totals = '<tr><td colspan="4" align="right">Subtotal (' . $cur . ')</td><td align="right">' . $m($c['subtotal']) . '</td></tr>';
		if ($c['discount'] > 0) {
			$pct = isset($d['discount_percent']) ? ' (' . $d['discount_percent'] . '%)' : '';
			$totals .= '<tr><td colspan="4" align="right">Discount' . $pct . '</td><td align="right">-' . $m($c['discount']) . '</td></tr>';
		}
		$totals .= '<tr><td colspan="4" align="right">' . e($vatLabel) . '</td><td align="right">' . $m($c['vat']) . '</td></tr>';
		$totals .= '<tr><td colspan="4" align="right"><b>Total (' . $cur . ')</b></td><td align="right"><b>' . $m($c['printedTotal']) . '</b></td></tr>';
		$footer = $isQuote
			? '<p>This quotation is not a tax invoice. Prices are valid until the date above. Confirm by return email to book.</p>'
			: '<p>Bank: Fictional National Bank, IBAN AE00 0000 0000 0000 0000 000. Please quote the invoice number with payment.</p>';

		return $head . '<body style="font-family: Liberation Sans, Arial, sans-serif; font-size: 10pt">'
			. '<table width="100%" cellpadding="4"><tr><td valign="top"><font size="5"><b>' . e($s['name']) . '</b></font><br>' . e($s['address']) . '<br>Tel ' . e($s['phone']) . '<br>TRN: ' . e($s['trn']) . '</td>'
			. '<td valign="top" align="right"><font size="6"><b>' . e($d['title']) . '</b></font><br><table cellpadding="2"><tr><td><b>' . $numLabel . '</b></td><td>' . e($d['number']) . '</td></tr>' . $dateRows . '</table></td></tr></table>'
			. '<p><b>Bill to</b><br>' . e($b['name']) . '<br>' . e($b['address']) . ($custTrn ? '<br>' . $custTrn : '') . '</p>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="5"><tr bgcolor="#dddddd"><th>#</th><th align="left">Description</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr>' . $rows . $totals . '</table>'
			. $footer . '</body></html>';
	}

	if ($L === 'B') {
		$rows = '';
		foreach ($c['lines'] as $ln) {
			$rows .= '<tr><td>' . e($ln['description']) . '</td><td align="center">' . qty($ln['quantity']) . '</td><td align="right">' . $m($ln['unit_price']) . '</td><td align="right">' . $m($ln['line_total']) . '</td></tr>';
		}
		$meta = '<tr><td>Invoice #</td><td><b>' . e($d['number']) . '</b></td></tr><tr><td>Date</td><td>' . dateFor($d['issue'], 'B') . '</td></tr>';
		if ($d['due'] !== null) {
			$meta .= '<tr><td>Due</td><td>' . dateFor($d['due'], 'B') . '</td></tr>';
		}

		return $head . '<body style="font-family: DejaVu Sans, sans-serif; font-size: 9pt">'
			. '<p><font size="7" color="#1f4e79"><b>' . e($d['title']) . '</b></font></p>'
			. '<p>Tax invoice under UAE VAT law</p>'
			. '<table cellpadding="3">' . $meta . '</table><br>'
			. '<table width="100%" cellpadding="4"><tr><td valign="top" width="50%"><b>From</b><br>' . e($s['name']) . '<br>' . e($s['address']) . '<br>Supplier TRN ' . e($s['trn']) . '</td>'
			. '<td valign="top"><b>To</b><br>' . e($b['name']) . '<br>' . e($b['address']) . ($b['trn'] !== null ? '<br>Customer TRN ' . e($b['trn']) : '') . '</td></tr></table><br>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="4"><tr bgcolor="#1f4e79"><th align="left"><font color="#ffffff">Item</font></th><th><font color="#ffffff">Hours / Qty</font></th><th><font color="#ffffff">Rate</font></th><th><font color="#ffffff">Total</font></th></tr>' . $rows . '</table><br>'
			. '<table align="right" cellpadding="3"><tr><td>Subtotal</td><td align="right">' . $m($c['subtotal']) . '</td></tr><tr><td>' . e($vatLabel) . '</td><td align="right">' . $m($c['vat']) . '</td></tr><tr><td><b>Amount due</b></td><td align="right"><b>' . $m($c['printedTotal']) . '</b></td></tr></table>'
			. '<br clear="all"><p>Thank you for your business.</p></body></html>';
	}

	if ($L === 'C') {
		$rows = '';
		foreach ($c['lines'] as $ln) {
			$rows .= '<tr><td>' . e($ln['description']) . '</td><td align="right">' . $m($ln['unit_price']) . '</td><td align="center">' . qty($ln['quantity']) . '</td><td align="right">' . $m($ln['line_total']) . '</td></tr>';
		}

		return $head . '<body style="font-family: Liberation Serif, Times New Roman, serif; font-size: 11pt">'
			. '<p align="center"><font size="5"><b>' . e(strtoupper($s['name'])) . '</b></font><br>' . e($s['address']) . ' | ' . e($s['phone']) . '<br>Tax Registration Number ' . e($s['trn']) . '</p>'
			. '<hr><p align="center"><font size="4"><b>' . e($d['title']) . '</b></font></p>'
			. '<p>No. ' . e($d['number']) . ' &nbsp;&nbsp; Issued ' . dateFor($d['issue'], 'C') . ' &nbsp;&nbsp; Payable by ' . dateFor($d['due'], 'C') . '</p>'
			. '<p>Customer: ' . e($b['name']) . ', ' . e($b['address']) . ($b['trn'] !== null ? '<br>Customer TRN ' . e($b['trn']) : '') . '</p>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="4"><tr><th align="left">Description</th><th>Unit price</th><th>Qty</th><th>Amount</th></tr>' . $rows
			. '<tr><td colspan="3" align="right">Net amount</td><td align="right">' . $m($c['subtotal']) . '</td></tr>'
			. '<tr><td colspan="3" align="right">' . e($vatLabel) . '</td><td align="right">' . $m($c['vat']) . '</td></tr>'
			. '<tr><td colspan="3" align="right"><b>Total payable</b></td><td align="right"><b>' . $m($c['printedTotal']) . '</b></td></tr></table>'
			. '<p><i>Goods remain the property of ' . e($s['name']) . ' until paid in full.</i></p></body></html>';
	}

	// Layout D: letter style, borderless table, no thousands separators.
	$rows = '';
	foreach ($c['lines'] as $ln) {
		$rows .= '<tr><td>' . e($ln['description']) . '</td><td align="right">' . qty($ln['quantity']) . ' x ' . $m($ln['unit_price']) . '</td><td align="right">' . $m($ln['line_total']) . '</td></tr>';
	}

	return $head . '<body style="font-family: Liberation Sans, Arial, sans-serif; font-size: 11pt">'
		. '<p align="right">' . e($s['name']) . '<br>' . e($s['address']) . '<br>TRN ' . e($s['trn']) . '</p>'
		. '<p>' . dateFor($d['issue'], 'D') . '</p>'
		. '<p>' . e($b['name']) . '<br>' . e($b['address']) . ($b['trn'] !== null ? '<br>TRN ' . e($b['trn']) : '') . '</p>'
		. '<p><b>' . e($d['title']) . ' ' . e($d['number']) . '</b></p>'
		. '<p>For the services below. Payment is due by ' . dateFor($d['due'], 'D') . '. All amounts in ' . $cur . '.</p>'
		. '<table width="100%" cellpadding="3">' . $rows
		. '<tr><td colspan="2"><br>Subtotal</td><td align="right"><br>' . $m($c['subtotal']) . '</td></tr>'
		. '<tr><td colspan="2">' . e($vatLabel) . '</td><td align="right">' . $m($c['vat']) . '</td></tr>'
		. '<tr><td colspan="2"><b>Total</b></td><td align="right"><b>' . $m($c['printedTotal']) . '</b></td></tr></table>'
		. '<p>Kind regards,<br>Accounts team, ' . e($s['name']) . '</p></body></html>';
}

// ---------------------------------------------------------------- build

function run(string $cmd): void
{
	exec($cmd . ' 2>&1', $out, $code);
	if ($code !== 0) {
		fwrite(STDERR, "Command failed ({$code}): {$cmd}\n" . implode("\n", $out) . "\n");
		exit(1);
	}
}

function amount(int $minor): float
{
	return round($minor / 100, 2);
}

function tabs(string $json): string
{
	return preg_replace_callback('/^(?: {4})+/m', fn ($m) => str_repeat("\t", intdiv(strlen($m[0]), 4)), $json);
}

function party(array $p): array
{
	return ['name' => $p['name'], 'trn' => $p['trn']];
}

$profile = 'file://' . $work . '/profile';

foreach ($docs as $d) {
	$c = compute($d);
	$base = $d['id'] . '-' . $d['slug'];
	$html = $work . '/html/' . $base . '.html';
	file_put_contents($html, render($d, $c, $parties));

	run(sprintf(
		'soffice -env:UserInstallation=%s --headless --convert-to pdf:writer_web_pdf_Export --outdir %s %s',
		escapeshellarg($profile),
		escapeshellarg($work),
		escapeshellarg($html),
	));
	$pdf = $work . '/' . $base . '.pdf';

	if (! empty($d['scan'])) {
		// Rasterise every page, then rebuild a PDF from the images only.
		run(sprintf('pdftoppm -r 150 -gray -png %s %s', escapeshellarg($pdf), escapeshellarg($work . '/' . $base . '-page')));
		$pngs = glob($work . '/' . $base . '-page*.png');
		if (count($pngs) !== 1) {
			fwrite(STDERR, "Expected one page for the scan, got " . count($pngs) . "\n");
			exit(1);
		}
		unlink($pdf);
		run(sprintf(
			'soffice -env:UserInstallation=%s --headless --convert-to pdf --outdir %s %s',
			escapeshellarg($profile),
			escapeshellarg($work . '/scan'),
			escapeshellarg($pngs[0]),
		));
		rename($work . '/scan/' . basename($pngs[0], '.png') . '.pdf', $pdf);
	}

	copy($pdf, OUT_SAMPLES . '/' . $base . '.pdf');

	// pdftotext emits a form feed per page even when there is no text.
	$text = trim((string) shell_exec('pdftotext ' . escapeshellarg($pdf) . ' -'), " \n\r\t\v\0\f");
	$pages = (int) preg_replace('/\D/', '', (string) shell_exec('pdfinfo ' . escapeshellarg($pdf) . " 2>/dev/null | grep '^Pages:'"));

	$expected = $d['type'] === 'invoice'
		? [
			'document_type' => 'invoice',
			'invoice_number' => $d['number'],
			'issue_date' => $d['issue'],
			'due_date' => $d['due'],
			'supplier' => party($parties[$d['supplier']]),
			'customer' => party($parties[$d['customer']]),
			'currency' => $d['currency'],
			'lines' => array_map(fn ($ln) => [
				'description' => $ln['description'],
				'quantity' => $ln['quantity'],
				'unit_price' => amount($ln['unit_price']),
				'line_total' => amount($ln['line_total']),
			], $c['lines']),
			'subtotal' => amount($c['subtotal']),
			'discount' => amount($c['discount']),
			'vat_rate' => $d['vat_rate'],
			'vat_amount' => amount($c['vat']),
			'total' => amount($c['printedTotal']),
		]
		: ['document_type' => 'not_invoice'];

	$truth = [
		'file' => $base . '.pdf',
		'layout' => $d['layout'],
		'pages' => $pages,
		'text_layer' => $text !== '',
		'notes' => $d['notes'],
		'expected_status' => match (true) {
			$d['type'] !== 'invoice' => 'rejected',
			isset($d['total_error']) => 'needs_review',
			default => 'accepted',
		},
		'expected_failed_checks' => isset($d['total_error']) ? ['total'] : [],
		'expected' => $expected,
	];

	file_put_contents(
		OUT_EXPECTED . '/' . $base . '.json',
		tabs(json_encode($truth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n",
	);

	printf("%-46s pages=%d text=%s status=%s\n", $base . '.pdf', $pages, $truth['text_layer'] ? 'yes' : 'NO', $truth['expected_status']);
}

run('rm -rf ' . escapeshellarg($work));
