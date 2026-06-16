<?php

namespace App\Services;

use App\Models\CustomerOrderItem;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;

class CustomerOrderReservationProjectionService
{
    public function refresh(PartCatalogItem|Product $item): bool
    {
        if ($item instanceof Product) {
            return $this->refreshProduct($item);
        }

        return $this->refreshCatalogItem($item);
    }

    public function refreshProduct(Product $product): bool
    {
        $product->loadMissing('sourcePartCatalogItem');
        $stockReservationChanged = app(CustomerOrderProductStockReservationSyncService::class)->syncProduct($product);

        if ($product->sourcePartCatalogItem?->source === 'nikolacars') {
            return $this->refreshCatalogItem($product->sourcePartCatalogItem, $product) || $stockReservationChanged;
        }

        return $stockReservationChanged;
    }

    public function refreshCatalogItem(PartCatalogItem $catalogItem, ?Product $product = null): bool
    {
        if ($catalogItem->source !== 'nikolacars') {
            return false;
        }

        $orderItems = CustomerOrderItem::query()
            ->with('order')
            ->where(function (Builder $query) use ($catalogItem, $product): void {
                $query->where('part_catalog_item_id', $catalogItem->id);

                if ($product instanceof Product) {
                    $query->orWhere('product_id', $product->id);
                }
            })
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->get();

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

        $oldReservedQuantity = round((float) data_get($rawAttributes, 'reserved_quantity', 0), 3);
        $oldReservedOrders = collect((array) data_get($rawAttributes, 'reserved_orders', []))
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $rawAttributes['reserved_quantity'] = round((float) $orderItems->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3);
        $rawAttributes['reserved_orders'] = $orderItems
            ->map(fn (CustomerOrderItem $item): ?string => $item->order?->number)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $changed = $oldReservedQuantity !== $rawAttributes['reserved_quantity']
            || $oldReservedOrders !== $rawAttributes['reserved_orders'];

        if ($changed) {
            $catalogItem->forceFill(['raw_attributes' => $rawAttributes])->save();
        }

        return $changed;
    }
}
