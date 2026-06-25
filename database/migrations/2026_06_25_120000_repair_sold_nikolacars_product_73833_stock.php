<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sale = DB::table('part_sales')
            ->where('source', 'nikolacars')
            ->where('product_id', 73833)
            ->where('part_catalog_item_id', 154772)
            ->where('code', '247')
            ->first();

        if (! $sale) {
            return;
        }

        DB::transaction(function () use ($sale): void {
            $item = DB::table('part_catalog_items')
                ->where('id', $sale->part_catalog_item_id)
                ->where('source', 'nikolacars')
                ->first();

            if ($item) {
                $rawAttributes = json_decode((string) $item->raw_attributes, true);
                $rawAttributes = is_array($rawAttributes) ? $rawAttributes : [];

                if (! array_key_exists('stock_quantity_before_sale', $rawAttributes)) {
                    $rawAttributes['stock_quantity_before_sale'] = data_get($rawAttributes, 'stock_quantity');
                }

                $rawAttributes['stock_quantity'] = 0;
                $rawAttributes['storage_status'] = Product::STORAGE_STATUS_SOLD;
                $rawAttributes['sold_at'] = $sale->sold_at !== null ? substr((string) $sale->sold_at, 0, 10) : null;
                $rawAttributes['sold_document_number'] = $sale->document_number;

                DB::table('part_catalog_items')
                    ->where('id', $item->id)
                    ->update([
                        'availability' => "0 \u{0448}\u{0442}",
                        'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('products')
                ->where('id', 73833)
                ->update([
                    'storage_status' => Product::STORAGE_STATUS_SOLD,
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            DB::table('stock_items')
                ->where('product_id', 73833)
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->get()
                ->each(function ($stockItem) use ($sale): void {
                    DB::table('movements')->insert([
                        'product_id' => 73833,
                        'stock_item_id' => $stockItem->id,
                        'from_location_id' => $stockItem->location_id,
                        'to_location_id' => null,
                        'user_id' => null,
                        'counterparty_id' => null,
                        'type' => 'adjustment',
                        'quantity' => $stockItem->quantity,
                        'reason' => 'sold_product_stock_cleanup',
                        'document_number' => $sale->document_number,
                        'comment' => 'Corrected sold NikolaCars product stock to zero.',
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => now()->utc(),
                    ]);

                    DB::table('stock_items')
                        ->where('id', $stockItem->id)
                        ->update([
                            'quantity' => 0,
                            'reserved_quantity' => 0,
                            'available_quantity' => 0,
                            'updated_at' => now(),
                        ]);
                });
        });
    }

    public function down(): void
    {
        // Sold inventory cleanup is a corrective operation and is not restored on rollback.
    }
};
