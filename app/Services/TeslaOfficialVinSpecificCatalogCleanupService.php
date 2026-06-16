<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Str;

class TeslaOfficialVinSpecificCatalogCleanupService
{
    public function __construct(
        protected TeslaCatalogDonorProductSync $catalogSync,
    ) {}

    public function cleanupAll(bool $dryRun = false, ?int $donorCarId = null): array
    {
        $donorCar = $donorCarId !== null
            ? DonorCar::query()->find($donorCarId)
            : null;
        $stats = $this->emptyStats($dryRun);

        PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', '%vin=%')
            ->with('products')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($donorCar, &$stats): void {
                foreach ($items as $item) {
                    if ($donorCar instanceof DonorCar && ! $this->belongsToDonor($item, $donorCar)) {
                        continue;
                    }

                    $this->cleanupItem($item, $stats);
                }
            });

        return $stats;
    }

    public function cleanupDonor(DonorCar $donorCar, bool $dryRun = false): array
    {
        return $this->cleanupAll($dryRun, (int) $donorCar->id);
    }

    public function cleanupItems(iterable $items, bool $dryRun = false): array
    {
        $stats = $this->emptyStats($dryRun);

        foreach ($items as $item) {
            if (! $item instanceof PartCatalogItem) {
                continue;
            }

            $this->cleanupItem($item, $stats);
        }

        return $stats;
    }

    protected function cleanupItem(PartCatalogItem $item, array &$stats): void
    {
        if (! $this->isVinSpecificItem($item)) {
            return;
        }

        $stats['items_seen']++;
        $item->loadMissing('products');

        foreach ($item->products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $stats['products_seen']++;
            $beforeItemId = (int) $product->source_part_catalog_item_id;

            if (! $stats['dry_run']) {
                $this->catalogSync->syncProduct($product->refresh());
                $product->refresh();
            }

            if ($stats['dry_run'] || (int) $product->source_part_catalog_item_id !== $beforeItemId) {
                $stats['products_relinked']++;
            }
        }

        if ($stats['dry_run']) {
            $stats['items_would_delete']++;

            return;
        }

        $remainingProducts = Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->count();

        if ($remainingProducts > 0) {
            $stats['items_skipped_referenced']++;

            return;
        }

        $item->delete();
        $stats['items_deleted']++;
    }

    protected function isVinSpecificItem(PartCatalogItem $item): bool
    {
        return $item->source === 'tesla_official'
            && Str::contains(Str::lower((string) $item->source_url), 'vin=');
    }

    protected function belongsToDonor(PartCatalogItem $item, DonorCar $donorCar): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $donorCarId = (int) data_get($rawAttributes, 'donor_car_id');

        if ($donorCarId > 0 && $donorCarId === (int) $donorCar->id) {
            return true;
        }

        $donorVin = Str::upper(trim((string) $donorCar->vin));
        if ($donorVin === '') {
            return false;
        }

        if (Str::upper(trim((string) data_get($rawAttributes, 'donor_vin'))) === $donorVin) {
            return true;
        }

        return $this->sourceUrlVin((string) $item->source_url) === $donorVin;
    }

    protected function sourceUrlVin(string $sourceUrl): string
    {
        $query = parse_url($sourceUrl, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $params);

        return Str::upper(trim((string) ($params['vin'] ?? '')));
    }

    protected function emptyStats(bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'items_seen' => 0,
            'items_would_delete' => 0,
            'items_deleted' => 0,
            'items_skipped_referenced' => 0,
            'products_seen' => 0,
            'products_relinked' => 0,
        ];
    }
}
