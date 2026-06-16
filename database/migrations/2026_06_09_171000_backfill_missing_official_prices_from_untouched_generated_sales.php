<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products as products')
            ->leftJoin('part_catalog_items as source_items', 'source_items.id', '=', 'products.source_part_catalog_item_id')
            ->where('products.is_auto_generated', true)
            ->whereNotNull('products.generated_at')
            ->whereNull('products.updated_by')
            ->where('products.selling_price', '>', 0)
            ->orderBy('products.id')
            ->select([
                'products.id',
                'products.selling_price',
                'products.currency',
                'source_items.id as source_item_id',
                'source_items.source as source_item_source',
                'source_items.raw_attributes as source_item_raw_attributes',
            ])
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $sourceRawAttributes = $this->decodeRawAttributes($product->source_item_raw_attributes);
                    if (
                        $product->source_item_source !== 'tesla_official'
                        && data_get($sourceRawAttributes, 'source_catalog_source') !== 'tesla_official'
                    ) {
                        continue;
                    }

                    $officialItemId = $product->source_item_source === 'tesla_official'
                        ? (int) $product->source_item_id
                        : (int) data_get($sourceRawAttributes, 'source_catalog_item_id');

                    if ($officialItemId <= 0) {
                        continue;
                    }

                    $officialItem = DB::table('part_catalog_items')
                        ->where('id', $officialItemId)
                        ->where('source', 'tesla_official')
                        ->first(['id', 'price_amount', 'currency', 'raw_attributes']);

                    if (! $officialItem) {
                        continue;
                    }

                    $officialRawAttributes = $this->decodeRawAttributes($officialItem->raw_attributes);
                    $contextPrice = $this->priceFromOfficialContexts($officialRawAttributes);
                    if ($officialItem->price_amount !== null) {
                        $price = round((float) $officialItem->price_amount, 2);
                        $currency = strtoupper((string) ($officialItem->currency ?: 'USD')) ?: 'USD';
                    } elseif ($contextPrice !== null) {
                        continue;
                    } else {
                        $price = round((float) $product->selling_price, 2);
                        $currency = strtoupper((string) ($product->currency ?: 'USD')) ?: 'USD';

                        DB::table('part_catalog_items')
                            ->where('id', $officialItem->id)
                            ->update([
                                'price_amount' => $price,
                                'currency' => $currency,
                                'updated_at' => now(),
                            ]);
                    }

                    if ($product->source_item_source === 'nikolacars') {
                        $sourceRawAttributes['source_catalog_price_amount'] = $price;
                        $sourceRawAttributes['source_catalog_currency'] = $currency;

                        DB::table('part_catalog_items')
                            ->where('id', $product->source_item_id)
                            ->update([
                                'price_amount' => 0,
                                'currency' => 'USD',
                                'raw_attributes' => json_encode($sourceRawAttributes, JSON_UNESCAPED_UNICODE),
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'selling_price' => 0,
                            'currency' => 'USD',
                            'updated_at' => now(),
                        ]);
                }
            }, 'products.id', 'id');
    }

    public function down(): void
    {
        // One-way repair: the previous copied sale price is intentionally separated.
    }

    protected function priceFromOfficialContexts(array $rawAttributes): ?float
    {
        $contexts = collect((array) data_get($rawAttributes, 'tesla_scheme_annotation_contexts', []))
            ->filter(fn (mixed $context): bool => is_array($context) && is_numeric($context['price'] ?? null));

        if ($contexts->isEmpty()) {
            return null;
        }

        $context = $contexts
            ->first(fn (array $context): bool => strtoupper((string) ($context['currency'] ?? 'USD')) === 'USD')
            ?: $contexts->first();

        return round((float) $context['price'], 2);
    }

    protected function decodeRawAttributes(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
