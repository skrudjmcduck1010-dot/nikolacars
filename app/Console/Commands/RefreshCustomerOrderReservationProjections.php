<?php

namespace App\Console\Commands;

use App\Models\CustomerOrderItem;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\CustomerOrderReservationProjectionService;
use Illuminate\Console\Command;

class RefreshCustomerOrderReservationProjections extends Command
{
    protected $signature = 'customer-orders:refresh-reservation-projections';

    protected $description = 'Refresh NikolaCars catalog and product stock reservations from active customer orders.';

    public function handle(CustomerOrderReservationProjectionService $reservationProjectionService): int
    {
        $catalogItemIds = CustomerOrderItem::query()
            ->whereNotNull('part_catalog_item_id')
            ->pluck('part_catalog_item_id')
            ->merge(
                PartCatalogItem::query()
                    ->where('source', 'nikolacars')
                    ->get()
                    ->filter(fn (PartCatalogItem $item): bool => (float) data_get($item->raw_attributes, 'reserved_quantity', 0) > 0)
                    ->pluck('id')
            )
            ->unique()
            ->values();
        $productIds = CustomerOrderItem::query()
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->merge(
                Product::query()
                    ->whereIn('source_part_catalog_item_id', $catalogItemIds)
                    ->pluck('id')
            )
            ->unique()
            ->values();

        $changed = 0;

        Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->each(function (Product $product) use ($reservationProjectionService, &$changed): void {
                if ($reservationProjectionService->refresh($product)) {
                    $changed++;
                }
            });

        PartCatalogItem::query()
            ->whereIn('id', $catalogItemIds)
            ->orderBy('id')
            ->each(function (PartCatalogItem $catalogItem) use ($reservationProjectionService, &$changed): void {
                if ($reservationProjectionService->refresh($catalogItem)) {
                    $changed++;
                }
            });

        $this->info(sprintf('Reservation projections refreshed. Changed records: %d.', $changed));

        return self::SUCCESS;
    }
}
