<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductPhotoNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CleanProductPhotoSchemes extends Command
{
    protected $signature = 'products:clean-photo-schemes
        {--write : Persist cleaned product photo lists}
        {--product-id=* : Limit cleanup to one or more product IDs}';

    protected $description = 'Remove Tesla catalog scheme images and rendered URL duplicates from product photo fields.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $productIds = collect((array) $this->option('product-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $stats = [
            'products_seen' => 0,
            'products_with_photo_issues' => 0,
            'would_update' => 0,
            'updated' => 0,
            'scheme_images_removed' => 0,
            'rendered_duplicates_removed' => 0,
        ];
        $examples = [];

        Product::query()
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $productIds->all()))
            ->select(['id', 'sku', 'external_sku', 'main_image', 'images_json'])
            ->orderBy('id')
            ->chunkById(300, function ($products) use ($write, &$stats, &$examples): void {
                foreach ($products as $product) {
                    $this->inspectProduct($product, $write, $stats, $examples);
                }
            });

        $this->info(($write ? 'Cleaned' : 'Scanned').' product photo schemes.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['product_id', 'sku', 'external_sku', 'before_count', 'after_count', 'schemes_removed', 'duplicates_removed', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectProduct(Product $product, bool $write, array &$stats, array &$examples): void
    {
        $stats['products_seen']++;

        $before = $this->rawPhotos($product);
        $schemeCount = $before
            ->filter(fn (string $photo): bool => ProductPhotoNormalizer::isCatalogSchemeImage($photo))
            ->count();
        $uniqueByRenderedUrl = $before
            ->reject(fn (string $photo): bool => ProductPhotoNormalizer::isCatalogSchemeImage($photo))
            ->unique(fn (string $photo): string => ProductPhotoNormalizer::imageKey($photo))
            ->values();
        $duplicateCount = max(0, $before->count() - $schemeCount - $uniqueByRenderedUrl->count());

        if ($schemeCount <= 0 && $duplicateCount <= 0) {
            return;
        }

        $cleanPhotos = ProductPhotoNormalizer::productPhotos($product);
        $payload = ProductPhotoNormalizer::persistencePayload($cleanPhotos);
        $currentPayload = [
            'main_image' => $product->main_image,
            'images_json' => $product->images_json !== null ? array_values((array) $product->images_json) : null,
        ];

        if ($payload === $currentPayload) {
            return;
        }

        $stats['products_with_photo_issues']++;
        $stats['scheme_images_removed'] += $schemeCount;
        $stats['rendered_duplicates_removed'] += $duplicateCount;

        if ($write) {
            $product->forceFill($payload)->save();
            $stats['updated']++;
            $action = 'updated';
        } else {
            $stats['would_update']++;
            $action = 'would_update';
        }

        if (count($examples) < 10) {
            $examples[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'external_sku' => $product->external_sku,
                'before_count' => $before->count(),
                'after_count' => $cleanPhotos->count(),
                'schemes_removed' => $schemeCount,
                'duplicates_removed' => $duplicateCount,
                'action' => $action,
            ];
        }
    }

    protected function rawPhotos(Product $product): Collection
    {
        return collect([$product->main_image])
            ->merge((array) ($product->images_json ?? []))
            ->map(fn (mixed $photo): string => trim((string) $photo))
            ->filter()
            ->values();
    }
}
