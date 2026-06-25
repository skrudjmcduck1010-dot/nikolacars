<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('cash_transactions')
                ->where('source', 'sto_work_order_payment')
                ->delete();

            DB::table('sto_work_order_parts')->delete();
            DB::table('sto_work_order_works')->delete();
            DB::table('sto_work_orders')->delete();

            $product = DB::table('products')
                ->where('sku', 'D2-0001')
                ->first(['id', 'source_part_catalog_item_id']);

            $productId = $product?->id ? (int) $product->id : null;
            $catalogItemId = $product?->source_part_catalog_item_id
                ? (int) $product->source_part_catalog_item_id
                : null;

            if ($catalogItemId === null) {
                $catalogItem = DB::table('part_catalog_items')
                    ->where('source', 'nikolacars')
                    ->where(function ($query): void {
                        $query
                            ->where('source_url', 'like', 'nikolacars://donor-product/%')
                            ->where('raw_attributes', 'like', '%"code":"D2-0001"%');
                    })
                    ->first(['id', 'raw_attributes']);

                $catalogItemId = $catalogItem?->id ? (int) $catalogItem->id : null;
                $rawAttributes = $catalogItem ? json_decode((string) $catalogItem->raw_attributes, true) : [];
                if ($productId === null && is_array($rawAttributes) && ! empty($rawAttributes['product_id'])) {
                    $productId = (int) $rawAttributes['product_id'];
                }
            }

            if ($productId !== null) {
                DB::table('reservations')->where('product_id', $productId)->delete();
                DB::table('purchase_items')->where('product_id', $productId)->delete();
                DB::table('movements')->where('product_id', $productId)->delete();
                DB::table('stock_items')->where('product_id', $productId)->delete();
                DB::table('customer_order_items')->where('product_id', $productId)->update(['product_id' => null]);
                DB::table('part_sales')->where('product_id', $productId)->update(['product_id' => null]);
                DB::table('products')->where('id', $productId)->delete();
            }

            if ($catalogItemId !== null) {
                if (Schema::hasColumn('product_price_histories', 'part_catalog_item_id')) {
                    DB::table('product_price_histories')->where('part_catalog_item_id', $catalogItemId)->delete();
                }

                DB::table('deleted_parts')->where('part_catalog_item_id', $catalogItemId)->delete();
                DB::table('part_catalog_items')->where('id', $catalogItemId)->delete();
            }
        });
    }

    public function down(): void
    {
        // Destructive cleanup requested for live legacy STO orders and D2-0001.
    }
};
