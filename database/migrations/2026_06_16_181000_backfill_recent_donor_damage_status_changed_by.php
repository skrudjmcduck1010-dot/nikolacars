<?php

use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'donor_damage_status_changed_by')) {
            return;
        }

        $statuses = [
            ...NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES,
            NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
            NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS,
        ];
        $changedSince = Carbon::parse('2026-06-16 00:00:00');

        DB::table('products')
            ->whereNotNull('donor_car_id')
            ->whereNull('donor_damage_status_changed_by')
            ->whereNotNull('updated_by')
            ->whereIn('notes', $statuses)
            ->where('updated_at', '>=', $changedSince)
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $product): void {
                DB::table('products')
                    ->where('id', $product->id)
                    ->whereNull('donor_damage_status_changed_by')
                    ->update([
                        'donor_damage_status_changed_by' => $product->updated_by,
                    ]);

                DB::table('part_catalog_items')
                    ->where('source', NikolaCarsProductInventorySyncService::SOURCE)
                    ->whereIn('source_url', [
                        'nikolacars://donor-product/'.$product->id,
                        'nikolacars://inventory-product/'.$product->id,
                    ])
                    ->orderBy('id')
                    ->lazyById()
                    ->each(function (object $item) use ($product): void {
                        $rawAttributes = PartCatalogRawAttributes::fromValue($item->raw_attributes ?? null);
                        $rawAttributes['donor_damage_status_changed_by'] = (int) $product->updated_by;

                        DB::table('part_catalog_items')
                            ->where('id', $item->id)
                            ->update([
                                'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                    });
            });
    }

    public function down(): void
    {
        //
    }
};
