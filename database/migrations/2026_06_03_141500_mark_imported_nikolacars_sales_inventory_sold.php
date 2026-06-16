<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('part_sales')
            ->where('source', 'nikolacars')
            ->whereNotNull('part_catalog_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($sales): void {
                foreach ($sales as $sale) {
                    $item = DB::table('part_catalog_items')
                        ->where('id', $sale->part_catalog_item_id)
                        ->where('source', 'nikolacars')
                        ->first();

                    if (! $item) {
                        continue;
                    }

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

                    $productIds = collect([(int) data_get($rawAttributes, 'product_id')])
                        ->filter()
                        ->values()
                        ->all();

                    DB::table('products')
                        ->where(function ($query) use ($item, $productIds): void {
                            $query->where('source_part_catalog_item_id', $item->id);

                            if ($productIds !== []) {
                                $query->orWhereIn('id', $productIds);
                            }
                        })
                        ->update([
                            'storage_status' => Product::STORAGE_STATUS_SOLD,
                            'is_active' => false,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Historical sale imports are authoritative; do not restore inventory on rollback.
    }
};
