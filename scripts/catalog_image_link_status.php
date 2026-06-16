<?php

use App\Models\PartCatalogItem;
use App\Services\CompetitorCatalogImageLocalizer;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (CompetitorCatalogImageLocalizer::SOURCES as $source) {
    $count = 0;

    PartCatalogItem::query()
        ->where('source', $source)
        ->where(function ($query): void {
            $query->where('raw_attributes', 'like', '%http://%')
                ->orWhere('raw_attributes', 'like', '%https://%');
        })
        ->select(['id', 'raw_attributes'])
        ->chunkById(500, function ($items) use (&$count): void {
            foreach ($items as $item) {
                $raw = $item->raw_attributes instanceof ArrayObject
                    ? $item->raw_attributes->getArrayCopy()
                    : (array) $item->raw_attributes;

                foreach (['image_url', 'image_urls', 'part_image_urls', 'system_group_image_urls', 'primary_image_url'] as $key) {
                    foreach ((array) data_get($raw, $key, []) as $url) {
                        if (is_string($url) && preg_match('~^https?://~i', trim($url)) === 1) {
                            $count++;

                            continue 3;
                        }
                    }
                }
            }
        });

    echo $source.': '.$count.PHP_EOL;
}
