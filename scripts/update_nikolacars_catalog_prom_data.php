<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$jsonPath = $argv[1] ?? null;

if (! is_string($jsonPath) || $jsonPath === '' || ! is_file($jsonPath)) {
    fwrite(STDERR, "Usage: php scripts/update_nikolacars_catalog_prom_data.php <nikolacars_prom_raw.json>\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($jsonPath), true);
$products = is_array($payload) ? Arr::get($payload, 'products', []) : [];

if (! is_array($products)) {
    fwrite(STDERR, "Invalid payload: products array not found.\n");
    exit(1);
}

$stats = [
    'products_read' => 0,
    'items_updated' => 0,
    'items_missing' => 0,
    'skipped_without_code' => 0,
];
$missing = [];

foreach ($products as $product) {
    if (! is_array($product)) {
        continue;
    }

    $stats['products_read']++;
    $code = trim((string) ($product['code'] ?? ''));

    if ($code === '') {
        $stats['skipped_without_code']++;

        continue;
    }

    /** @var PartCatalogItem|null $item */
    $item = PartCatalogItem::query()
        ->where('source', 'nikolacars')
        ->where('source_url', "nikolacars://product/{$code}")
        ->first();

    if (! $item) {
        $stats['items_missing']++;
        $missing[] = [
            'code' => $code,
            'sku' => (string) ($product['prom_sku'] ?? ''),
            'name' => (string) ($product['prom_name'] ?? ''),
            'url' => (string) ($product['prom_url'] ?? ''),
        ];

        continue;
    }

    $attributes = $item->raw_attributes instanceof ArrayObject
        ? $item->raw_attributes->getArrayCopy()
        : (array) $item->raw_attributes;

    $promAttributes = json_decode((string) ($product['prom_attributes_json'] ?? ''), true);
    if (! is_array($promAttributes)) {
        $promAttributes = [];
    }

    $promImages = collect(explode(';', (string) ($product['prom_images'] ?? '')))
        ->map(fn (string $url): string => trim($url))
        ->filter(fn (string $url): bool => $url !== '' && str_contains($url, 'images.prom.ua'))
        ->unique()
        ->sortBy(fn (string $url): int => imageQualityRank($url))
        ->groupBy(fn (string $url): string => promImageKey($url))
        ->map(fn ($group): string => $group->first())
        ->values()
        ->all();

    $localImages = collect((array) data_get($attributes, 'image_urls', []))
        ->map(fn (mixed $url): string => trim((string) $url))
        ->filter()
        ->values()
        ->all();

    $attributes['prom'] = array_filter([
        'sku' => (string) ($product['prom_sku'] ?? ''),
        'url' => (string) ($product['prom_url'] ?? ''),
        'name' => (string) ($product['prom_name'] ?? ''),
        'description' => (string) ($product['prom_description'] ?? ''),
        'price' => (string) ($product['prom_price'] ?? ''),
        'currency' => (string) ($product['prom_currency'] ?? ''),
        'availability' => (string) ($product['prom_availability'] ?? ''),
        'brand' => (string) ($product['prom_brand'] ?? ''),
        'condition' => (string) ($product['prom_condition'] ?? ''),
        'color' => (string) ($product['prom_color'] ?? ''),
        'attributes' => $promAttributes,
        'image_urls' => $promImages,
    ], fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== null);

    $attributes['product_url'] = (string) ($product['prom_url'] ?? '');
    $attributes['prom_sku'] = (string) ($product['prom_sku'] ?? '');
    $attributes['prom_price_text'] = trim(collect([
        (string) ($product['prom_price'] ?? ''),
        (string) ($product['prom_currency'] ?? ''),
    ])->filter()->join(' '));
    $attributes['prom_description'] = (string) ($product['prom_description'] ?? '');
    $attributes['characteristics'] = array_filter(array_merge(
        (array) data_get($attributes, 'characteristics', []),
        $promAttributes
    ), fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== null);
    $attributes['info'] = array_filter(array_merge(
        (array) data_get($attributes, 'info', []),
        [
            'Prom SKU' => (string) ($product['prom_sku'] ?? ''),
            'Prom URL' => (string) ($product['prom_url'] ?? ''),
            'Prom price' => $attributes['prom_price_text'],
            'Prom description' => (string) ($product['prom_description'] ?? ''),
        ]
    ), fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== null);

    if ($promImages !== []) {
        $attributes['prom_image_urls'] = $promImages;
        $attributes['image_urls'] = $promImages;
    }

    $item->fill([
        'name_ua' => (string) ($product['prom_name'] ?? '') ?: $item->name_ua,
        'notes_ua' => (string) ($product['prom_description'] ?? '') ?: $item->notes_ua,
        'condition' => (string) ($product['prom_condition'] ?? '') ?: $item->condition,
        'raw_attributes' => $attributes,
        'source_updated_at' => now(),
    ]);

    $item->save();
    $stats['items_updated']++;
}

echo json_encode([
    'stats' => $stats,
    'missing' => $missing,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;

function imageQualityRank(string $url): int
{
    if (! preg_match('/_w(\d+)_h(\d+)_/i', $url, $matches)) {
        return 0;
    }

    $width = (int) $matches[1];

    return match (true) {
        $width >= 1000 => 1,
        $width >= 640 => 2,
        $width >= 500 => 3,
        default => 4,
    };
}

function promImageKey(string $url): string
{
    if (preg_match('~/(\d+)(?:_w\d+_h\d+)?_[^/]+$~i', $url, $matches) === 1) {
        return $matches[1];
    }

    return $url;
}
