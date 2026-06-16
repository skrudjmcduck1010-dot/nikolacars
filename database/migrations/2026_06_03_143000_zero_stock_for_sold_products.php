<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->join('stock_items', 'stock_items.product_id', '=', 'products.id')
            ->where('products.storage_status', Product::STORAGE_STATUS_SOLD)
            ->where('stock_items.quantity', '>', 0)
            ->orderBy('stock_items.id')
            ->select([
                'products.id as product_id',
                'stock_items.id as stock_item_id',
                'stock_items.location_id',
                'stock_items.quantity',
            ])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('movements')->insert([
                        'product_id' => $row->product_id,
                        'stock_item_id' => $row->stock_item_id,
                        'from_location_id' => $row->location_id,
                        'to_location_id' => null,
                        'user_id' => null,
                        'counterparty_id' => null,
                        'type' => 'adjustment',
                        'quantity' => $row->quantity,
                        'reason' => 'sold_product_stock_cleanup',
                        'document_number' => null,
                        'comment' => 'Корректировка с '.$row->quantity.' до 0. Sold product stock corrected to zero.',
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => now()->utc(),
                    ]);

                    DB::table('stock_items')
                        ->where('id', $row->stock_item_id)
                        ->update([
                            'quantity' => 0,
                            'reserved_quantity' => 0,
                            'available_quantity' => 0,
                            'updated_at' => now(),
                        ]);
                }
            }, 'stock_items.id', 'stock_item_id');
    }

    public function down(): void
    {
        // Sold product stock cleanup is a corrective inventory operation and is not restored on rollback.
    }
};
