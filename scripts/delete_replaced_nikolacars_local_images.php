<?php

use App\Models\PartCatalogItem;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$publicImagesDir = realpath(public_path('nikolacars/prod'));

if (! is_string($publicImagesDir) || $publicImagesDir === '') {
    fwrite(STDERR, "Public NikolaCars image directory not found.\n");
    exit(1);
}

$stats = [
    'items_scanned' => 0,
    'items_cleaned' => 0,
    'files_deleted' => 0,
    'files_missing' => 0,
    'files_skipped' => 0,
];
$deleted = [];

PartCatalogItem::query()
    ->where('source', 'nikolacars')
    ->whereNotNull('raw_attributes->prom_image_urls')
    ->whereNotNull('raw_attributes->local_image_urls_backup')
    ->orderBy('id')
    ->chunkById(100, function ($items) use (&$stats, &$deleted, $publicImagesDir): void {
        foreach ($items as $item) {
            $stats['items_scanned']++;
            $attributes = $item->raw_attributes instanceof ArrayObject
                ? $item->raw_attributes->getArrayCopy()
                : (array) $item->raw_attributes;

            $backupUrls = (array) data_get($attributes, 'local_image_urls_backup', []);

            foreach ($backupUrls as $url) {
                $path = parse_url((string) $url, PHP_URL_PATH);
                if (! is_string($path) || ! str_starts_with($path, '/nikolacars/prod/')) {
                    $stats['files_skipped']++;

                    continue;
                }

                $basename = basename($path);
                $target = $publicImagesDir.DIRECTORY_SEPARATOR.$basename;
                $resolvedParent = realpath(dirname($target));

                if ($resolvedParent !== $publicImagesDir) {
                    $stats['files_skipped']++;

                    continue;
                }

                if (! is_file($target)) {
                    $stats['files_missing']++;

                    continue;
                }

                unlink($target);
                $stats['files_deleted']++;
                $deleted[] = [
                    'code' => (string) data_get($attributes, 'code', ''),
                    'file' => $target,
                ];
            }

            unset($attributes['local_image_urls_backup']);
            $item->raw_attributes = $attributes;
            $item->save();
            $stats['items_cleaned']++;
        }
    });

echo json_encode([
    'stats' => $stats,
    'deleted_sample' => array_slice($deleted, 0, 30),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
