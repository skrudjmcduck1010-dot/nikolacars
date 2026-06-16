<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('part_catalog_items')
            ->where('source', 'nikolacars')
            ->where('raw_attributes', 'like', '%"manual_sold_at"%')
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $hash = 'manual-sold-before-june-2026-'.$item->id;

                    if (DB::table('part_sales')->where('source', 'nikolacars')->where('source_row_hash', $hash)->exists()) {
                        continue;
                    }

                    $rawAttributes = json_decode((string) $item->raw_attributes, true);
                    $rawAttributes = is_array($rawAttributes) ? $rawAttributes : [];
                    $product = null;
                    $productId = (int) data_get($rawAttributes, 'product_id');

                    if ($productId > 0) {
                        $product = DB::table('products')->where('id', $productId)->first(['id', 'donor_car_id']);
                    }

                    $donorCar = null;
                    if ($product?->donor_car_id) {
                        $donorCar = DB::table('donor_cars')->where('id', $product->donor_car_id)->first(['id', 'vin']);
                    }

                    if (! $donorCar && filled(data_get($rawAttributes, 'donor_vin'))) {
                        $donorVin = Str::lower(trim((string) data_get($rawAttributes, 'donor_vin')));
                        $donorCar = DB::table('donor_cars')
                            ->whereRaw('lower(vin) = ?', [$donorVin])
                            ->first(['id', 'vin']);
                    }

                    $quantityBeforeSold = data_get($rawAttributes, 'stock_quantity_before_manual_sold');
                    $quantity = $quantityBeforeSold !== null && $quantityBeforeSold !== ''
                        ? max(0.001, round((float) $quantityBeforeSold, 3))
                        : 1.0;
                    $name = trim((string) ($item->name_ua ?: $item->name_ru ?: $item->name ?: $item->part_number ?: $item->id));
                    $unitPrice = strtoupper((string) ($item->currency ?: 'USD')) === 'USD' && $item->price_amount !== null
                        ? round((float) $item->price_amount, 2)
                        : null;
                    $rawDonorVin = trim((string) data_get($rawAttributes, 'donor_vin', ''));
                    $donorVinCandidates = collect([
                        trim((string) ($donorCar?->vin ?? '')),
                        $rawDonorVin,
                    ])->filter()->values();
                    $donorVin = $donorVinCandidates
                        ->first(fn (string $value): bool => Str::length($value) <= 17);
                    $saleRawAttributes = [
                        'manual_cleanup' => true,
                        'manual_sold_at' => data_get($rawAttributes, 'manual_sold_at') ?: '2026-05-31',
                        'restorable_from_zapchasti_sold' => true,
                        'backfilled_by_migration' => true,
                        'product_id' => $product?->id,
                    ];
                    $originalDonorVin = $donorVinCandidates
                        ->first(fn (string $value): bool => $value !== $donorVin && Str::length($value) > 17);

                    if ($originalDonorVin !== null) {
                        $saleRawAttributes['original_donor_vin'] = $originalDonorVin;
                    }

                    $now = now();

                    DB::table('part_sales')->insert([
                        'part_catalog_item_id' => $item->id,
                        'donor_car_id' => $donorCar?->id,
                        'source' => 'nikolacars',
                        'code' => data_get($rawAttributes, 'code'),
                        'part_number' => $item->part_number,
                        'name' => $name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'currency' => 'USD',
                        'sold_at' => data_get($rawAttributes, 'manual_sold_at') ?: '2026-05-31',
                        'document_number' => 'manual-sold-before-june-2026',
                        'counterparty' => 'Cleanup before 01.06.2026',
                        'donor_vin' => $donorVin,
                        'category_path' => data_get($rawAttributes, 'category_display') ?: data_get($rawAttributes, 'category_path'),
                        'raw_attributes' => json_encode($saleRawAttributes, JSON_UNESCAPED_UNICODE),
                        'source_file' => 'manual-zapchasti-cleanup',
                        'source_row_number' => $item->id,
                        'source_row_hash' => $hash,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        DB::table('products')
            ->leftJoin('part_catalog_items', 'part_catalog_items.id', '=', 'products.source_part_catalog_item_id')
            ->where('products.storage_status', 'sold')
            ->where('products.is_active', false)
            ->whereNotNull('products.donor_car_id')
            ->whereNotNull('products.source_part_catalog_item_id')
            ->whereNull('part_catalog_items.id')
            ->orderBy('products.id')
            ->select([
                'products.id',
                'products.sku',
                'products.external_sku',
                'products.name',
                'products.donor_car_id',
                'products.source_part_catalog_item_id',
                'products.selling_price',
                'products.currency',
            ])
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $hash = 'manual-sold-before-june-2026-product-'.$product->id;

                    if (DB::table('part_sales')->where('source', 'nikolacars')->where('source_row_hash', $hash)->exists()) {
                        continue;
                    }

                    $donorCar = DB::table('donor_cars')->where('id', $product->donor_car_id)->first(['id', 'vin']);
                    $unitPrice = strtoupper((string) ($product->currency ?: 'USD')) === 'USD' && $product->selling_price !== null
                        ? round((float) $product->selling_price, 2)
                        : null;
                    $now = now();

                    DB::table('part_sales')->insert([
                        'part_catalog_item_id' => null,
                        'donor_car_id' => $donorCar?->id,
                        'source' => 'nikolacars',
                        'code' => $product->sku,
                        'part_number' => $product->external_sku,
                        'name' => $product->name ?: $product->external_sku ?: $product->sku,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'currency' => 'USD',
                        'sold_at' => '2026-05-31',
                        'document_number' => 'manual-sold-before-june-2026',
                        'counterparty' => 'Cleanup before 01.06.2026',
                        'donor_vin' => $donorCar?->vin,
                        'category_path' => null,
                        'raw_attributes' => json_encode([
                            'manual_cleanup' => true,
                            'manual_sold_at' => '2026-05-31',
                            'restorable_from_zapchasti_sold' => true,
                            'backfilled_by_migration' => true,
                            'product_id' => $product->id,
                            'missing_part_catalog_item_id' => $product->source_part_catalog_item_id,
                        ], JSON_UNESCAPED_UNICODE),
                        'source_file' => 'manual-zapchasti-cleanup',
                        'source_row_number' => $product->id,
                        'source_row_hash' => $hash,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }, 'products.id', 'id');
    }

    public function down(): void
    {
        DB::table('part_sales')
            ->where('source', 'nikolacars')
            ->where('source_file', 'manual-zapchasti-cleanup')
            ->where('document_number', 'manual-sold-before-june-2026')
            ->delete();
    }
};
