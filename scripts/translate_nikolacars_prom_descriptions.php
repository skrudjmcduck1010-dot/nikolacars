<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cachePath = base_path('outputs/nikolacars_prom/description_ru_cache.json');
$cache = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : [];
$cache = is_array($cache) ? $cache : [];

$limit = null;
$force = in_array('--force', $argv, true);
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, strlen('--limit=')));
    }
}

$query = PartCatalogItem::query()
    ->where('source', 'nikolacars')
    ->whereNotNull('notes_ua')
    ->where('notes_ua', '<>', '');

if (! $force) {
    $query->where(function ($query): void {
        $query->whereNull('notes_ru')->orWhere('notes_ru', '');
    });
}

$items = $query->orderBy('id')->get();
if ($limit !== null) {
    $items = $items->take($limit);
}

$updated = 0;
$failed = 0;

foreach ($items as $item) {
    $descriptionUa = trim((string) $item->notes_ua);
    if ($descriptionUa === '') {
        continue;
    }

    $cacheKey = md5($descriptionUa);
    $descriptionRu = $cache[$cacheKey] ?? null;

    if (! is_string($descriptionRu) || trim($descriptionRu) === '') {
        try {
            $descriptionRu = translateUaToRu($descriptionUa);
            $cache[$cacheKey] = $descriptionRu;
            saveTranslationCache($cachePath, $cache);
            usleep(120000);
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, sprintf(
                "Failed item %s: %s\n",
                data_get($item->raw_attributes, 'code', $item->id),
                $exception->getMessage()
            ));

            continue;
        }
    }

    $rawAttributes = $item->raw_attributes?->toArray() ?? [];
    $rawAttributes['prom_description_ru'] = $descriptionRu;
    if (isset($rawAttributes['prom']) && is_array($rawAttributes['prom'])) {
        $rawAttributes['prom']['description_ru'] = $descriptionRu;
    }

    $item->forceFill([
        'notes_ru' => $descriptionRu,
        'raw_attributes' => $rawAttributes,
    ])->save();

    $updated++;

    if ($updated % 50 === 0) {
        echo "Updated {$updated}\n";
    }
}

echo json_encode([
    'selected' => $items->count(),
    'updated' => $updated,
    'failed' => $failed,
    'cache_entries' => count($cache),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

function translateUaToRu(string $text): string
{
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=uk&tl=ru&dt=t&q='.rawurlencode($text);
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header' => "User-Agent: Mozilla/5.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if (! is_string($response) || $response === '') {
        throw new RuntimeException('empty translation response');
    }

    $payload = json_decode($response, true);
    if (! is_array($payload) || ! is_array($payload[0] ?? null)) {
        throw new RuntimeException('unexpected translation response');
    }

    $translated = collect($payload[0])
        ->map(fn ($part): string => is_array($part) ? (string) ($part[0] ?? '') : '')
        ->implode('');

    $translated = trim($translated);
    if ($translated === '') {
        throw new RuntimeException('empty translated text');
    }

    return $translated;
}

function saveTranslationCache(string $cachePath, array $cache): void
{
    $directory = dirname($cachePath);
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($cachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
