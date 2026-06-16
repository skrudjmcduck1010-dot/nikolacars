<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$jsonPath = $argv[1] ?? null;

if (! is_string($jsonPath) || $jsonPath === '' || ! is_file($jsonPath)) {
    fwrite(STDERR, "Usage: php scripts/update_nikolacars_prom_images.php <nikolacars_prom_raw.json>\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($jsonPath), true);
$products = is_array($payload) ? ($payload['products'] ?? []) : [];

if (! is_array($products)) {
    fwrite(STDERR, "Invalid payload: products array not found.\n");
    exit(1);
}

$stats = [
    'products_read' => 0,
    'items_updated' => 0,
    'items_missing' => 0,
    'skipped_without_code' => 0,
    'skipped_without_prom_images' => 0,
];
$missing = [];
$withoutImages = [];

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

    $promImages = promImageUrls((string) ($product['prom_images'] ?? ''));
    if ($promImages === []) {
        $stats['skipped_without_prom_images']++;
        $withoutImages[] = [
            'code' => $code,
            'sku' => (string) ($product['prom_sku'] ?? ''),
            'url' => (string) ($product['prom_url'] ?? ''),
        ];

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

    $currentImages = array_values(array_filter(array_map(
        fn (mixed $url): string => trim((string) $url),
        (array) data_get($attributes, 'image_urls', [])
    )));

    if (! array_key_exists('local_image_urls_backup', $attributes)) {
        $attributes['local_image_urls_backup'] = $currentImages;
    }

    $attributes['image_urls'] = $promImages;
    $attributes['prom_image_urls'] = $promImages;
    $attributes['prom_image_source_url'] = (string) ($product['prom_url'] ?? '');

    $prom = (array) data_get($attributes, 'prom', []);
    $prom['image_urls'] = $promImages;
    $attributes['prom'] = array_filter($prom, fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== null);

    $item->raw_attributes = $attributes;
    $item->source_updated_at = now();
    $item->save();

    $stats['items_updated']++;
}

echo json_encode([
    'stats' => $stats,
    'missing' => $missing,
    'without_prom_images' => $withoutImages,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;

function promImageUrls(string $images): array
{
    $urls = collect(explode(';', $images))
        ->map(fn (string $url): string => trim($url))
        ->filter(fn (string $url): bool => $url !== '' && str_contains($url, 'images.prom.ua'))
        ->unique()
        ->sortBy(fn (string $url): int => imageQualityRank($url))
        ->values();

    return $urls
        ->groupBy(fn (string $url): string => promImageKey($url))
        ->map(fn ($group): string => $group->first())
        ->values()
        ->all();
}

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
