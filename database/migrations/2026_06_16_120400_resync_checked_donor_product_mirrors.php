<?php

use App\Models\Product;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $sync = app(NikolaCarsProductInventorySyncService::class);

        Product::query()
            ->where('donor_car_id', 27)
            ->whereIn('notes', NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES)
            ->whereIn('storage_status', [
                Product::STORAGE_STATUS_IN_STOCK,
                Product::STORAGE_STATUS_ON_DONOR,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($sync): void {
                foreach ($products as $product) {
                    $sync->syncProduct($product);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
