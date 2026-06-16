<?php

use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where(function ($query): void {
                $query
                    ->where('source_url', 'like', 'nikolacars://donor-product/%')
                    ->orWhere('source_url', 'like', 'nikolacars://inventory-product/%')
                    ->orWhereIn('raw_attributes->source_type', ['donor', 'warehouse', 'purchase']);
            })
            ->select('id')
            ->chunkById(500, function ($items): void {
                Product::query()
                    ->whereIn('source_part_catalog_item_id', $items->pluck('id'))
                    ->where('is_auto_generated', true)
                    ->update([
                        'is_auto_generated' => false,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        //
    }
};
