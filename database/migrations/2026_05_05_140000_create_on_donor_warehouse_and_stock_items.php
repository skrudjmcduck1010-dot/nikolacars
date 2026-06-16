<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $warehouse = DB::table('warehouses')
            ->where('type', 'donor')
            ->orWhere('name', 'На доноре')
            ->first();

        if ($warehouse) {
            DB::table('warehouses')
                ->where('id', $warehouse->id)
                ->update([
                    'name' => 'На доноре',
                    'type' => 'donor',
                    'floor_count' => 1,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

            $warehouseId = $warehouse->id;
        } else {
            $warehouseId = DB::table('warehouses')->insertGetId([
                'name' => 'На доноре',
                'type' => 'donor',
                'floor_count' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('products')
            ->where('storage_status', 'on_donor')
            ->whereNotNull('donor_car_id')
            ->orderBy('id')
            ->get(['id', 'donor_car_id', 'condition_grade', 'testing_status'])
            ->each(function (object $product) use ($warehouseId, $now): void {
                $hasAvailableStock = DB::table('stock_items')
                    ->where('product_id', $product->id)
                    ->where('available_quantity', '>', 0)
                    ->exists();

                if ($hasAvailableStock) {
                    return;
                }

                $donorCar = DB::table('donor_cars')
                    ->where('id', $product->donor_car_id)
                    ->first(['id', 'vin']);

                if (! $donorCar) {
                    return;
                }

                $fullCode = 'ON-DONOR-'.$donorCar->id;
                $location = DB::table('locations')
                    ->where('warehouse_id', $warehouseId)
                    ->where('full_code', $fullCode)
                    ->first();

                if (! $location) {
                    $locationId = DB::table('locations')->insertGetId([
                        'warehouse_id' => $warehouseId,
                        'floor' => 'floor_1',
                        'cell' => mb_substr($donorCar->vin ?: 'DONOR-'.$donorCar->id, 0, 50),
                        'full_code' => $fullCode,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $locationId = $location->id;
                }

                DB::table('stock_items')->updateOrInsert(
                    [
                        'product_id' => $product->id,
                        'location_id' => $locationId,
                        'condition_grade' => $product->condition_grade ?: 'A',
                        'testing_status' => $product->testing_status ?: 'not_tested',
                    ],
                    [
                        'warehouse_id' => $warehouseId,
                        'quantity' => 1,
                        'reserved_quantity' => 0,
                        'available_quantity' => 1,
                        'received_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        $warehouse = DB::table('warehouses')->where('type', 'donor')->first();

        if (! $warehouse) {
            return;
        }

        DB::table('stock_items')
            ->where('warehouse_id', $warehouse->id)
            ->where('reserved_quantity', 0)
            ->where('quantity', 1)
            ->whereIn('product_id', function ($query): void {
                $query->select('id')
                    ->from('products')
                    ->where('storage_status', 'on_donor');
            })
            ->delete();
    }
};
