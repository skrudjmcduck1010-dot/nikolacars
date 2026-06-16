<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = $argv[1] ?? null;
$limit = max(0, (int) ($argv[2] ?? 0));
$fields = ['image_url', 'image_urls', 'part_image_urls', 'system_group_image_urls', 'primary_image_url'];
$seen = 0;
$updated = 0;
$downloaded = 0;
$existing = 0;
$failed = 0;

$isRemote = fn (string $url): bool => preg_match('~^https?://.+\.(?:jpe?g|png|webp|svg)(?:[?#].*)?$~iu', trim($url)) === 1;

$imagePath = function (PartCatalogItem $item, string $url): ?string {
    $urlPath = parse_url($url, PHP_URL_PATH);
    if (! is_string($urlPath) || $urlPath === '') {
        return null;
    }

    $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION) ?: 'jpg');
    if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
        $extension = 'jpg';
    }

    $name = Str::slug(Str::limit(pathinfo($urlPath, PATHINFO_FILENAME) ?: sha1($url), 90, ''), '-');
    if ($name === '') {
        $name = sha1($url);
    }

    if ($item->source === 'tesla_official' && str_contains((string) parse_url($url, PHP_URL_HOST), 'epc.tesla.com')) {
        return 'tesla-official/resources-images/'.$name.'-'.substr(sha1($url), 0, 12).'.'.$extension;
    }

    $partNumber = Str::slug((string) ($item->part_number ?: 'item-'.$item->id), '-');
    if ($partNumber === '') {
        $partNumber = 'item-'.$item->id;
    }

    return 'competitor-catalog/'.$item->source.'/'.$partNumber.'/'.$name.'-'.substr(sha1($url), 0, 12).'.'.$extension;
};

$download = function (PartCatalogItem $item, string $url) use ($imagePath, &$downloaded, &$existing, &$failed): ?string {
    $path = $imagePath($item, $url);
    if ($path === null) {
        $failed++;

        return null;
    }

    if (Storage::disk('public')->exists($path)) {
        $existing++;

        return $path;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (! is_string($body) || $body === '') {
        $failed++;

        return null;
    }

    Storage::disk('public')->put($path, $body);
    $downloaded++;

    return $path;
};

$query = PartCatalogItem::query()
    ->where(function ($query): void {
        $query->where('raw_attributes', 'like', '%http://%')
            ->orWhere('raw_attributes', 'like', '%https://%');
    })
    ->select(['id', 'source', 'part_number', 'raw_attributes'])
    ->orderBy('id');

if ($source !== null && $source !== '') {
    $query->where('source', $source);
}

$query->chunkById(200, function ($items) use ($fields, $isRemote, $download, $limit, &$seen, &$updated): bool {
    foreach ($items as $item) {
        if ($limit > 0 && $seen >= $limit) {
            return false;
        }

        $raw = $item->raw_attributes instanceof ArrayObject
            ? $item->raw_attributes->getArrayCopy()
            : (array) $item->raw_attributes;
        $changed = false;
        $hasRemoteDisplayImage = false;

        foreach ($fields as $field) {
            foreach ((array) data_get($raw, $field, []) as $url) {
                if (is_string($url) && $isRemote($url)) {
                    $hasRemoteDisplayImage = true;
                    break 2;
                }
            }
        }

        if (! $hasRemoteDisplayImage) {
            continue;
        }

        $seen++;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }

            if (is_array($raw[$field])) {
                $values = [];
                foreach ($raw[$field] as $url) {
                    $values[] = is_string($url) && $isRemote($url)
                        ? ($download($item, $url) ?? $url)
                        : $url;
                }

                $values = array_values(array_unique(array_filter($values, fn ($url): bool => is_string($url) && trim($url) !== '')));
                if ($values !== $raw[$field]) {
                    $raw[$field] = $values;
                    $changed = true;
                }

                continue;
            }

            if (is_string($raw[$field]) && $isRemote($raw[$field])) {
                $localPath = $download($item, $raw[$field]);
                if ($localPath !== null) {
                    $raw[$field] = $localPath;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $item->forceFill(['raw_attributes' => $raw])->save();
            $updated++;
        }

        if ($seen % 25 === 0) {
            echo "seen={$seen} updated={$updated}".PHP_EOL;
        }
    }

    return true;
});

echo json_encode([
    'seen' => $seen,
    'updated' => $updated,
    'downloaded' => $downloaded,
    'existing' => $existing,
    'failed' => $failed,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
