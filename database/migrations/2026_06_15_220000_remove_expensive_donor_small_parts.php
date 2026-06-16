<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items') || ! Schema::hasTable('products')) {
            return;
        }

        $flaggedItems = DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'raw_attributes'])
            ->orderBy('id')
            ->get()
            ->filter(fn (object $item): bool => (bool) data_get($this->rawAttributes($item), 'donor_vin_small_part'));

        $smallPartNumbers = $flaggedItems
            ->map(fn (object $item): ?string => PartNumberNormalizer::normalize(
                (string) ($item->part_number ?: data_get($this->rawAttributes($item), 'donor_vin_small_part_part_number'))
            ))
            ->filter()
            ->unique()
            ->values();

        if ($smallPartNumbers->isEmpty()) {
            return;
        }

        $maxPrices = $this->maxUsdPricesByPartNumber($smallPartNumbers);
        $expensivePartNumbers = collect($maxPrices)
            ->filter(fn (float $price): bool => $price >= 10.0)
            ->keys()
            ->values();

        if ($expensivePartNumbers->isEmpty()) {
            return;
        }

        $itemIds = $this->catalogItemIdsForPartNumbers($expensivePartNumbers, $flaggedItems);

        foreach ($itemIds->chunk(500) as $chunk) {
            DB::table('part_catalog_items')
                ->whereIn('id', $chunk->all())
                ->select(['id', 'raw_attributes'])
                ->orderBy('id')
                ->get()
                ->each(function (object $item): void {
                    $rawAttributes = $this->rawAttributes($item);

                    if (! (bool) data_get($rawAttributes, 'donor_vin_small_part')) {
                        return;
                    }

                    unset(
                        $rawAttributes['donor_vin_small_part'],
                        $rawAttributes['donor_vin_small_part_part_number'],
                        $rawAttributes['donor_vin_small_part_reason'],
                        $rawAttributes['donor_vin_small_part_marked_at']
                    );

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                });
        }
    }

    public function down(): void
    {
        // One-time data cleanup; intentionally not reversible.
    }

    protected function maxUsdPricesByPartNumber(Collection $partNumbers): array
    {
        $maxPrices = [];
        $partNumberSet = $partNumbers->flip();

        DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'price_amount', 'currency', 'raw_attributes'])
            ->whereIn('part_number', $partNumbers->all())
            ->orderBy('id')
            ->chunkById(1000, function (Collection $items) use (&$maxPrices): void {
                foreach ($items as $item) {
                    $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);

                    if ($partNumber === null) {
                        continue;
                    }

                    foreach ($this->catalogItemUsdPrices($item) as $price) {
                        $maxPrices[$partNumber] = max($maxPrices[$partNumber] ?? 0.0, $price);
                    }
                }
            });

        DB::table('products')
            ->leftJoin('part_catalog_items', 'products.source_part_catalog_item_id', '=', 'part_catalog_items.id')
            ->whereNotNull('products.donor_car_id')
            ->where(function ($query) use ($partNumbers): void {
                $query->whereIn('products.external_sku', $partNumbers->all())
                    ->orWhereIn('part_catalog_items.part_number', $partNumbers->all());
            })
            ->select([
                'products.id',
                'products.external_sku',
                'products.selling_price',
                'products.currency',
                'part_catalog_items.part_number as source_part_number',
            ])
            ->orderBy('products.id')
            ->chunkById(1000, function (Collection $products) use (&$maxPrices, $partNumberSet): void {
                foreach ($products as $product) {
                    $partNumber = PartNumberNormalizer::normalize((string) ($product->external_sku ?: $product->source_part_number));

                    if ($partNumber === null || ! $partNumberSet->has($partNumber)) {
                        continue;
                    }

                    if ($product->selling_price !== null && $this->isUsdCurrency($product->currency)) {
                        $maxPrices[$partNumber] = max($maxPrices[$partNumber] ?? 0.0, (float) $product->selling_price);
                    }
                }
            }, 'products.id', 'id');

        return $maxPrices;
    }

    protected function catalogItemIdsForPartNumbers(Collection $partNumbers, Collection $flaggedItems): Collection
    {
        $partNumberSet = $partNumbers->flip();

        $itemIds = DB::table('part_catalog_items')
            ->whereIn('part_number', $partNumbers->all())
            ->pluck('id');

        $productSourceItemIds = DB::table('products')
            ->leftJoin('part_catalog_items', 'products.source_part_catalog_item_id', '=', 'part_catalog_items.id')
            ->whereNotNull('products.donor_car_id')
            ->where(function ($query) use ($partNumbers): void {
                $query->whereIn('products.external_sku', $partNumbers->all())
                    ->orWhereIn('part_catalog_items.part_number', $partNumbers->all());
            })
            ->pluck('products.source_part_catalog_item_id');

        $rawFlaggedItemIds = $flaggedItems
            ->filter(fn (object $item): bool => $partNumberSet->has(
                PartNumberNormalizer::normalize((string) data_get($this->rawAttributes($item), 'donor_vin_small_part_part_number'))
            ))
            ->pluck('id');

        return collect($itemIds)
            ->merge($productSourceItemIds)
            ->merge($rawFlaggedItemIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    protected function catalogItemUsdPrices(object $item): array
    {
        $prices = [];
        $rawAttributes = $this->rawAttributes($item);

        if ($item->price_amount !== null && $this->isUsdCurrency($item->currency)) {
            $prices[] = (float) $item->price_amount;
        }

        $sourcePrice = data_get($rawAttributes, 'source_catalog_price_amount');
        if ($sourcePrice !== null && $this->isUsdCurrency(data_get($rawAttributes, 'source_catalog_currency', 'USD'))) {
            $prices[] = (float) $sourcePrice;
        }

        return $prices;
    }

    protected function rawAttributes(object $item): array
    {
        $rawAttributes = $item->raw_attributes ?? null;

        if (is_array($rawAttributes)) {
            return $rawAttributes;
        }

        if (is_object($rawAttributes) && method_exists($rawAttributes, 'getArrayCopy')) {
            return $rawAttributes->getArrayCopy();
        }

        if (! is_string($rawAttributes) || trim($rawAttributes) === '') {
            return [];
        }

        $decoded = json_decode($rawAttributes, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function isUsdCurrency(mixed $currency): bool
    {
        return in_array(strtoupper((string) ($currency ?: 'USD')), ['', 'USD'], true);
    }
};
