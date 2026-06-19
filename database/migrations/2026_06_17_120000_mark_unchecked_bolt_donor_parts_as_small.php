<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASON = 'auto: unchecked donor bolt with Tesla.com price under 1 USD';

    private const UNKNOWN_DAMAGE_STATUS = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";

    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $partNumbers = $this->eligiblePartNumbers();

        if ($partNumbers->isEmpty()) {
            return;
        }

        DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'raw_attributes'])
            ->whereIn('part_number', $partNumbers->all())
            ->orderBy('id')
            ->chunkById(500, function (Collection $items): void {
                foreach ($items as $item) {
                    $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);

                    if ($partNumber === null) {
                        continue;
                    }

                    $rawAttributes = $this->rawAttributes($item);

                    if ((bool) data_get($rawAttributes, 'donor_vin_small_part')) {
                        continue;
                    }

                    $rawAttributes['donor_vin_small_part'] = true;
                    $rawAttributes['donor_vin_small_part_part_number'] = $partNumber;
                    $rawAttributes['donor_vin_small_part_reason'] = self::REASON;
                    $rawAttributes['donor_vin_small_part_marked_at'] = now()->toIso8601String();

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // One-time data classification; intentionally not reversible.
    }

    protected function eligiblePartNumbers(): Collection
    {
        $partNumbers = collect();

        DB::table('products')
            ->leftJoin('part_catalog_items as source_items', 'products.source_part_catalog_item_id', '=', 'source_items.id')
            ->whereNotNull('products.donor_car_id')
            ->where(function ($query): void {
                $query
                    ->where('products.is_auto_generated', true)
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('products.generated_at')
                            ->where('products.sku', 'like', 'DON%-');
                    });
            })
            ->whereNotIn('products.storage_status', ['sold', 'written_off'])
            ->select([
                'products.id',
                'products.sku',
                'products.external_sku',
                'products.notes',
                'source_items.id as source_item_id',
                'source_items.source as source_item_source',
                'source_items.part_number as source_item_part_number',
                'source_items.name as source_item_name',
                'source_items.name_en as source_item_name_en',
                'source_items.price_amount as source_item_price_amount',
                'source_items.currency as source_item_currency',
                'source_items.raw_attributes as source_item_raw_attributes',
            ])
            ->orderBy('products.id')
            ->chunkById(500, function (Collection $products) use (&$partNumbers): void {
                $sourceRawAttributesByProductId = $products
                    ->mapWithKeys(fn (object $product): array => [
                        (int) $product->id => $this->rawAttributesFromValue($product->source_item_raw_attributes),
                    ]);

                $sourceCatalogItemIds = $sourceRawAttributesByProductId
                    ->map(fn (array $rawAttributes): int => (int) data_get($rawAttributes, 'source_catalog_item_id'))
                    ->filter()
                    ->unique()
                    ->values();

                $productPartNumbers = $products
                    ->map(fn (object $product): ?string => $this->productPartNumber($product))
                    ->filter()
                    ->unique()
                    ->values();

                $officialItemsById = $this->officialItemsById($sourceCatalogItemIds);
                $officialItemsByPartNumber = $this->officialItemsByPartNumber($productPartNumbers);

                foreach ($products as $product) {
                    if (! $this->isUncheckedDonorProduct($product)) {
                        continue;
                    }

                    $partNumber = $this->productPartNumber($product);

                    if ($partNumber === null) {
                        continue;
                    }

                    $sourceRawAttributes = $sourceRawAttributesByProductId->get((int) $product->id, []);
                    $officialItem = $this->officialItemForProduct($product, $sourceRawAttributes, $officialItemsById, $officialItemsByPartNumber);

                    if (! $this->isBoltName($officialItem)) {
                        continue;
                    }

                    $price = $this->officialUsdPrice($officialItem, $sourceRawAttributes);

                    if ($price === null || $price >= 1.0) {
                        continue;
                    }

                    $partNumbers->push($partNumber);
                }
            }, 'products.id', 'id');

        return $partNumbers
            ->filter()
            ->unique()
            ->values();
    }

    protected function officialItemsById(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('part_catalog_items')
            ->where('source', 'tesla_official')
            ->whereIn('id', $ids->all())
            ->get($this->officialItemColumns())
            ->keyBy('id');
    }

    protected function officialItemsByPartNumber(Collection $partNumbers): Collection
    {
        if ($partNumbers->isEmpty()) {
            return collect();
        }

        return DB::table('part_catalog_items')
            ->where('source', 'tesla_official')
            ->whereIn('part_number', $partNumbers->all())
            ->orderByRaw("case when source_url like 'https://parts.tesla.com/%' then 0 else 1 end")
            ->orderBy('id')
            ->get($this->officialItemColumns())
            ->groupBy('part_number')
            ->map(fn (Collection $items): object => $items->first());
    }

    protected function officialItemColumns(): array
    {
        return [
            'id',
            'source',
            'source_url',
            'part_number',
            'name',
            'name_en',
            'price_amount',
            'currency',
            'raw_attributes',
        ];
    }

    protected function officialItemForProduct(
        object $product,
        array $sourceRawAttributes,
        Collection $officialItemsById,
        Collection $officialItemsByPartNumber
    ): ?object {
        if ($product->source_item_source === 'tesla_official') {
            return (object) [
                'id' => $product->source_item_id,
                'source' => $product->source_item_source,
                'part_number' => $product->source_item_part_number,
                'name' => $product->source_item_name,
                'name_en' => $product->source_item_name_en,
                'price_amount' => $product->source_item_price_amount,
                'currency' => $product->source_item_currency,
                'raw_attributes' => $product->source_item_raw_attributes,
            ];
        }

        $sourceCatalogItemId = (int) data_get($sourceRawAttributes, 'source_catalog_item_id');
        if ($sourceCatalogItemId > 0) {
            $officialItem = $officialItemsById->get($sourceCatalogItemId);

            if ($officialItem !== null) {
                return $officialItem;
            }
        }

        $partNumber = $this->productPartNumber($product);

        return $partNumber !== null ? $officialItemsByPartNumber->get($partNumber) : null;
    }

    protected function officialUsdPrice(?object $officialItem, array $sourceRawAttributes): ?float
    {
        if ($officialItem !== null && $officialItem->price_amount !== null && $this->isUsdCurrency($officialItem->currency)) {
            return round((float) $officialItem->price_amount, 2);
        }

        if ($officialItem !== null) {
            $contexts = (array) data_get($this->rawAttributes($officialItem), 'tesla_scheme_annotation_contexts', []);

            foreach ($contexts as $context) {
                if (! is_array($context) || ! is_numeric($context['price'] ?? null)) {
                    continue;
                }

                if (! $this->isUsdCurrency($context['currency'] ?? 'USD')) {
                    continue;
                }

                return round((float) $context['price'], 2);
            }
        }

        $sourcePrice = data_get($sourceRawAttributes, 'source_catalog_price_amount');
        if ($sourcePrice !== null && is_numeric($sourcePrice) && $this->isUsdCurrency(data_get($sourceRawAttributes, 'source_catalog_currency', 'USD'))) {
            return round((float) $sourcePrice, 2);
        }

        return null;
    }

    protected function isBoltName(?object $officialItem): bool
    {
        $name = trim((string) ($officialItem?->name_en ?: $officialItem?->name ?: ''));

        return $name !== '' && stripos($name, 'bolt') !== false;
    }

    protected function isUncheckedDonorProduct(object $product): bool
    {
        $note = trim((string) ($product->notes ?? ''));

        return $note === ''
            || $note === self::UNKNOWN_DAMAGE_STATUS
            || preg_match('/^\?+$/', $note) === 1;
    }

    protected function productPartNumber(object $product): ?string
    {
        return PartNumberNormalizer::normalize((string) ($product->external_sku ?: $product->source_item_part_number));
    }

    protected function rawAttributes(object $item): array
    {
        return $this->rawAttributesFromValue($item->raw_attributes ?? null);
    }

    protected function rawAttributesFromValue(mixed $rawAttributes): array
    {
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
