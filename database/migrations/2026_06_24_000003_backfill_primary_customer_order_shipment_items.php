<?php

use App\Models\CustomerOrderShipment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orderIds = DB::table('customer_order_shipments')
            ->select('customer_order_id')
            ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
            ->whereNotNull('tracking_number')
            ->groupBy('customer_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('customer_order_id');

        foreach ($orderIds as $orderId) {
            $shipmentIds = DB::table('customer_order_shipments')
                ->where('customer_order_id', $orderId)
                ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
                ->whereNotNull('tracking_number')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values();

            $primaryShipmentId = $shipmentIds->first();

            if ($primaryShipmentId === null) {
                continue;
            }

            $secondaryShipmentIds = $shipmentIds->slice(1)->values();
            $secondaryItemIds = DB::table('customer_order_shipment_items')
                ->whereIn('customer_order_shipment_id', $secondaryShipmentIds->all())
                ->pluck('customer_order_item_id')
                ->map(fn ($id): int => (int) $id)
                ->unique();

            $primaryItemIds = DB::table('customer_order_items')
                ->where('customer_order_id', $orderId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->diff($secondaryItemIds)
                ->values();

            DB::table('customer_order_shipment_items')
                ->where('customer_order_shipment_id', $primaryShipmentId)
                ->delete();

            $now = now();
            $rows = $primaryItemIds->map(fn (int $itemId): array => [
                'customer_order_shipment_id' => $primaryShipmentId,
                'customer_order_item_id' => $itemId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                DB::table('customer_order_shipment_items')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
