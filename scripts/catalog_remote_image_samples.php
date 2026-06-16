<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = $argv[1] ?? 'stock-tesla';
$limit = max(1, (int) ($argv[2] ?? 20));
$printed = 0;

PartCatalogItem::query()
    ->where('source', $source)
    ->where(function ($query): void {
        $query->where('raw_attributes', 'like', '%http://%')
            ->orWhere('raw_attributes', 'like', '%https://%');
    })
    ->select(['id', 'part_number', 'name', 'name_en', 'raw_attributes'])
    ->chunkById(500, function ($items) use (&$printed, $limit): bool {
        foreach ($items as $item) {
            $raw = $item->raw_attributes instanceof ArrayObject
                ? $item->raw_attributes->getArrayCopy()
                : (array) $item->raw_attributes;

            $urls = [];
            foreach (['image_url', 'image_urls', 'part_image_urls', 'system_group_image_urls', 'primary_image_url'] as $key) {
                foreach ((array) data_get($raw, $key, []) as $url) {
                    if (is_string($url) && preg_match('~^https?://~i', trim($url)) === 1) {
                        $urls[$key][] = $url;
                    }
                }
            }

            if ($urls === []) {
                continue;
            }

            echo 'ID='.$item->id.' PN='.$item->part_number.' NAME='.($item->name ?: $item->name_en).PHP_EOL;
            echo json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL;

            $printed++;
            if ($printed >= $limit) {
                return false;
            }
        }

        return true;
    });
