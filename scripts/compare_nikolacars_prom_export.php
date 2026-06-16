<?php

use App\Services\NikolaCarsPromYmlFeed;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$jsonPath = $argv[1] ?? 'outputs/nikolacars_prom/nikolacars_prom_raw_20260507_154547.json';
$outPath = $argv[2] ?? 'outputs/nikolacars_prom/nikolacars_prom_export_compare.csv';

if (! is_file($jsonPath)) {
    fwrite(STDERR, "Prom raw JSON not found: {$jsonPath}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($jsonPath), true);
$promProducts = collect($payload['products'] ?? [])
    ->filter(fn ($row): bool => is_array($row) && trim((string) ($row['code'] ?? '')) !== '')
    ->keyBy(fn (array $row): string => trim((string) $row['code']));

$groups = app(NikolaCarsPromYmlFeed::class)->exportableGroups();
$exportRowsByCode = collect();

foreach ($groups as $group) {
    foreach ($group['codes'] as $code) {
        $exportRowsByCode->put((string) $code, [
            'export_offer_id' => $group['id'],
            'export_name' => $group['name'],
            'export_part_number' => $group['part_number'],
            'export_codes' => $group['codes']->implode(', '),
            'export_quantity' => $group['quantity'],
            'export_price_usd' => $group['price_usd'],
            'export_images_count' => $group['image_urls']->count(),
        ]);
    }
}

$allCodes = $promProducts->keys()
    ->merge($exportRowsByCode->keys())
    ->unique()
    ->sort(SORT_NATURAL)
    ->values();

$rows = $allCodes->map(function (string $code) use ($promProducts, $exportRowsByCode): array {
    $prom = $promProducts->get($code, []);
    $export = $exportRowsByCode->get($code, []);

    return [
        'code' => $code,
        'in_prom_site' => $prom !== [] ? 'yes' : 'no',
        'in_prom_export' => $export !== [] ? 'yes' : 'no',
        'prom_sku' => $prom['prom_sku'] ?? '',
        'prom_name' => $prom['prom_name'] ?? '',
        'prom_url' => $prom['prom_url'] ?? '',
        'prom_price_uah' => $prom['prom_price'] ?? '',
        'export_offer_id' => $export['export_offer_id'] ?? '',
        'export_name' => $export['export_name'] ?? '',
        'export_part_number' => $export['export_part_number'] ?? '',
        'export_codes' => $export['export_codes'] ?? '',
        'export_quantity' => $export['export_quantity'] ?? '',
        'export_price_usd' => $export['export_price_usd'] ?? '',
        'export_images_count' => $export['export_images_count'] ?? '',
    ];
});

if (! is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

$handle = fopen($outPath, 'wb');
fputcsv($handle, array_keys($rows->first() ?? []));
foreach ($rows as $row) {
    fputcsv($handle, $row);
}
fclose($handle);

$promOnly = $rows->where('in_prom_site', 'yes')->where('in_prom_export', 'no')->values();
$exportOnly = $rows->where('in_prom_site', 'no')->where('in_prom_export', 'yes')->values();
$both = $rows->where('in_prom_site', 'yes')->where('in_prom_export', 'yes')->values();

echo json_encode([
    'prom_site_products' => $promProducts->count(),
    'prom_export_offers' => $groups->count(),
    'prom_export_codes' => $exportRowsByCode->count(),
    'both_codes' => $both->count(),
    'prom_site_not_in_export' => $promOnly->count(),
    'export_not_on_prom_site' => $exportOnly->count(),
    'csv' => realpath($outPath),
    'prom_site_not_in_export_sample' => $promOnly->take(30)->values(),
    'export_not_on_prom_site_sample' => $exportOnly->take(30)->values(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
