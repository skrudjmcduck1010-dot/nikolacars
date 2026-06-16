<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\StockItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerOrderIssuedSaleService
{
    public function syncOrder(CustomerOrder $order): int
    {
        if (! $order->isIssuedToClient()) {
            return 0;
        }

        $createdOrUpdated = 0;

        $order->loadMissing(['items.partCatalogItem', 'items.product.donorCar', 'items.product.sourcePartCatalogItem']);

        foreach ($order->items as $item) {
            if ($this->syncItem($order, $item)) {
                $createdOrUpdated++;
            }
        }

        return $createdOrUpdated;
    }

    public function cancelOrder(CustomerOrder $order): int
    {
        $order->loadMissing(['items.partCatalogItem', 'items.product.donorCar', 'items.product.sourcePartCatalogItem']);

        $hashes = $order->items
            ->map(fn (CustomerOrderItem $item): string => $this->sourceRowHash($item))
            ->values();

        if ($hashes->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($order, $hashes): int {
            $sales = PartSale::query()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->where('source_file', 'customer-order-issued')
                ->whereIn('source_row_hash', $hashes->all())
                ->get();

            $sales
                ->groupBy(fn (PartSale $sale): string => ((int) $sale->product_id > 0 ? 'product:' : 'catalog:').(int) ($sale->product_id ?: $sale->part_catalog_item_id))
                ->each(fn (Collection $salesForItem, string $key) => $this->restoreInventoryFromCancelledSales(
                    $key,
                    $salesForItem,
                    $order,
                ));

            $deleted = $sales->count();

            if ($deleted > 0) {
                PartSale::query()
                    ->whereIn('id', $sales->pluck('id')->all())
                    ->delete();
            }

            return $deleted;
        });
    }

    public function syncItem(CustomerOrder $order, CustomerOrderItem $orderItem): bool
    {
        $product = $orderItem->product;
        $catalogItem = $this->catalogItemForOrderItem($orderItem, $product);

        if (! $product instanceof Product && (! $catalogItem instanceof PartCatalogItem || $catalogItem->source !== NikolaCarsInventoryService::SOURCE)) {
            return false;
        }

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
        $originalPartNumber = trim((string) ($product?->external_sku ?: $catalogItem?->part_number ?: $orderItem->part_number));
        $quantity = max(0.001, round((float) $orderItem->quantity, 3));
        $stockBeforeSale = $product instanceof Product
            ? $this->productStockQuantity($product, $catalogItem)
            : $this->catalogStockQuantity($rawAttributes);
        $soldAt = $order->payment_confirmed_at ?: $order->updated_at ?: now();
        $hash = $this->sourceRowHash($orderItem);
        $existingSale = PartSale::query()
            ->where('source', NikolaCarsInventoryService::SOURCE)
            ->where('source_row_hash', $hash)
            ->first();
        $stockAfterSale = $existingSale instanceof PartSale
            ? $stockBeforeSale
            : max(0.0, round($stockBeforeSale - $quantity, 3));

        $sale = PartSale::query()->updateOrCreate(
            [
                'source' => NikolaCarsInventoryService::SOURCE,
                'source_row_hash' => $hash,
            ],
            [
                'part_catalog_item_id' => $catalogItem?->id,
                'product_id' => $product?->id,
                'donor_car_id' => $product?->donor_car_id ?: ($catalogItem instanceof PartCatalogItem ? $this->donorCarId($catalogItem, $rawAttributes) : null),
                'code' => $orderItem->code ?: $product?->sku ?: data_get($rawAttributes, 'code'),
                'part_number' => $originalPartNumber,
                'name' => $orderItem->name ?: ($product?->name ?: $catalogItem?->name_ua ?: $catalogItem?->name_ru ?: $catalogItem?->name),
                'quantity' => $quantity,
                'unit_price' => $this->unitPriceUsd($orderItem, $catalogItem, $product),
                'currency' => 'USD',
                'sold_at' => $soldAt,
                'document_number' => $order->number,
                'counterparty' => $order->client_name ?: $order->client_phone,
                'donor_vin' => $this->safeDonorVin($orderItem->donor_vin ?: $product?->donorCar?->vin ?: data_get($rawAttributes, 'donor_vin')),
                'category_path' => $orderItem->category ?: data_get($rawAttributes, 'category_display') ?: data_get($rawAttributes, 'category_path') ?: $product?->compatibility,
                'raw_attributes' => [
                    'customer_order_id' => $order->id,
                    'customer_order_item_id' => $orderItem->id,
                    'customer_order_number' => $order->number,
                    'delivery_method' => $order->delivery_method,
                    'paid_amount_uah' => (float) $order->paid_amount_uah,
                    'unit_price_uah' => (float) $orderItem->unit_price,
                    'total_price_uah' => (float) $orderItem->total_price,
                    'original_part_number' => $originalPartNumber !== '' ? $originalPartNumber : null,
                    'stock_quantity_before_sale' => $stockBeforeSale,
                    'stock_quantity_after_sale' => $stockAfterSale,
                ],
                'source_file' => 'customer-order-issued',
                'source_row_number' => $orderItem->id,
            ],
        );

        if ($product instanceof Product) {
            $this->syncProductSale($product, $stockAfterSale, $order);
        }

        if (! $catalogItem instanceof PartCatalogItem) {
            return $sale->wasRecentlyCreated || $sale->wasChanged();
        }

        $rawAttributes['stock_quantity_before_customer_order_sale_'.$orderItem->id] = $stockBeforeSale;
        $rawAttributes['stock_quantity'] = $stockAfterSale;
        $rawAttributes['sold_at'] = $this->dateString($soldAt);
        $rawAttributes['sold_document_number'] = $order->number;
        $rawAttributes['customer_order_sale_id'] = $sale->id;

        if ($stockAfterSale <= 0) {
            $rawAttributes['storage_status'] = Product::STORAGE_STATUS_SOLD;
        }

        $catalogItem->forceFill([
            'availability' => app(NikolaCarsInventoryService::class)->availability($stockAfterSale),
            'raw_attributes' => $rawAttributes,
        ])->save();

        $this->syncProducts($catalogItem, $rawAttributes, $stockAfterSale, $order);
        $this->keepIssuedCatalogItemVisible($catalogItem->refresh(), $stockAfterSale, $order, $sale);
        $this->refreshReservationProjection($catalogItem, $product);

        return $sale->wasRecentlyCreated || $sale->wasChanged();
    }

    protected function catalogItemForOrderItem(CustomerOrderItem $orderItem, ?Product $product): ?PartCatalogItem
    {
        if ($product instanceof Product) {
            $product->loadMissing('sourcePartCatalogItem');
            $sourceItem = $product->sourcePartCatalogItem;

            if ($sourceItem instanceof PartCatalogItem && $sourceItem->source === NikolaCarsInventoryService::SOURCE) {
                return $sourceItem;
            }
        }

        $catalogItem = $orderItem->partCatalogItem;

        if (! $catalogItem instanceof PartCatalogItem || $catalogItem->source !== NikolaCarsInventoryService::SOURCE) {
            return null;
        }

        if (! $product instanceof Product) {
            return $catalogItem;
        }

        return $this->catalogItemBelongsToProduct($catalogItem, $product)
            ? $catalogItem
            : null;
    }

    protected function catalogItemBelongsToProduct(PartCatalogItem $catalogItem, Product $product): bool
    {
        if ((int) $product->source_part_catalog_item_id === (int) $catalogItem->id) {
            return true;
        }

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
        if ((int) data_get($rawAttributes, 'product_id') === (int) $product->id) {
            return true;
        }

        return preg_match(
            '~^nikolacars://(?:donor-product|inventory-product)/'.preg_quote((string) $product->id, '~').'$~',
            (string) $catalogItem->source_url,
        ) === 1;
    }

    protected function refreshReservationProjection(?PartCatalogItem $catalogItem, ?Product $product): void
    {
        $projectionService = app(CustomerOrderReservationProjectionService::class);

        if ($catalogItem instanceof PartCatalogItem && $catalogItem->source === NikolaCarsInventoryService::SOURCE) {
            $projectionService->refresh($catalogItem->refresh());
        }

        if ($product instanceof Product) {
            $projectionService->refresh($product->refresh());
        }
    }

    protected function keepIssuedCatalogItemVisible(
        PartCatalogItem $catalogItem,
        float $stockAfterSale,
        CustomerOrder $order,
        PartSale $sale,
    ): void {
        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

        $rawAttributes['stock_quantity'] = $stockAfterSale;
        $rawAttributes['sold_at'] = $this->dateString($sale->sold_at ?: $order->payment_confirmed_at ?: $order->updated_at);
        $rawAttributes['sold_document_number'] = $order->number;
        $rawAttributes['customer_order_sale_id'] = $sale->id;

        if ($stockAfterSale <= 0) {
            $rawAttributes['storage_status'] = Product::STORAGE_STATUS_SOLD;
        }

        $catalogItem->forceFill([
            'availability' => app(NikolaCarsInventoryService::class)->availability($stockAfterSale),
            'raw_attributes' => $rawAttributes,
        ])->save();
    }

    protected function syncProducts(PartCatalogItem $catalogItem, array $rawAttributes, float $stockAfterSale, CustomerOrder $order): void
    {
        $productIds = collect([(int) data_get($rawAttributes, 'product_id')])
            ->filter()
            ->values();

        Product::query()
            ->where(function (Builder $query) use ($catalogItem, $productIds): void {
                $query->where('source_part_catalog_item_id', $catalogItem->id);

                if ($productIds->isNotEmpty()) {
                    $query->orWhereIn('id', $productIds->all());
                }
            })
            ->get()
            ->each(function (Product $product) use ($stockAfterSale, $order): void {
                if ($stockAfterSale <= 0) {
                    $product->forceFill([
                        'storage_status' => Product::STORAGE_STATUS_SOLD,
                        'is_active' => false,
                    ])->save();

                    app(SoldProductStockAdjustmentService::class)->zeroStock($product->refresh(), [
                        'document_number' => $order->number,
                        'comment' => 'Customer order issued to client.',
                    ]);
                }

                app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            });
    }

    protected function restoreInventoryFromCancelledSales(string $key, Collection $sales, CustomerOrder $order): void
    {
        if (str_starts_with($key, 'product:')) {
            $this->restoreProductFromCancelledSales((int) substr($key, 8), $sales, $order);

            return;
        }

        $this->restoreCatalogItemFromCancelledSales((int) substr($key, 8), $sales, $order);
    }

    protected function restoreCatalogItemFromCancelledSales(
        int $catalogItemId,
        Collection $sales,
        CustomerOrder $order,
        bool $restoreProducts = true,
        ?float $restoredQuantity = null,
    ): void {
        if ($catalogItemId <= 0) {
            return;
        }

        $catalogItem = PartCatalogItem::query()->find($catalogItemId);

        if (! $catalogItem instanceof PartCatalogItem) {
            return;
        }

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
        $currentQuantity = data_get($rawAttributes, 'stock_quantity');
        $currentQuantity = $currentQuantity !== null && $currentQuantity !== ''
            ? round((float) $currentQuantity, 3)
            : 0.0;
        $restoredQuantity ??= round($currentQuantity + $sales->sum(fn (PartSale $sale): float => (float) $sale->quantity), 3);
        $restoredQuantity = $this->catalogStockQuantityCappedToSourceRow($catalogItem, $restoredQuantity);

        foreach ($sales as $sale) {
            unset($rawAttributes['stock_quantity_before_customer_order_sale_'.$sale->source_row_number]);
        }

        if ((string) data_get($rawAttributes, 'sold_document_number') === (string) $order->number) {
            unset(
                $rawAttributes['sold_at'],
                $rawAttributes['sold_document_number'],
                $rawAttributes['customer_order_sale_id']
            );
        }

        $rawAttributes['stock_quantity'] = $restoredQuantity;
        $rawAttributes['storage_status'] = data_get($rawAttributes, 'donor_vin')
            ? Product::STORAGE_STATUS_ON_DONOR
            : Product::STORAGE_STATUS_IN_STOCK;

        $catalogItem->forceFill([
            'availability' => app(NikolaCarsInventoryService::class)->availability($restoredQuantity),
            'raw_attributes' => $rawAttributes,
        ])->save();

        if ($restoreProducts) {
            $this->restoreProductsFromCancelledSales($catalogItem, $rawAttributes, $restoredQuantity, $order);
        }
    }

    protected function restoreProductsFromCancelledSales(
        PartCatalogItem $catalogItem,
        array $rawAttributes,
        float $restoredQuantity,
        CustomerOrder $order,
    ): void {
        $productIds = collect([(int) data_get($rawAttributes, 'product_id')])
            ->filter()
            ->values();

        Product::query()
            ->where(function (Builder $query) use ($catalogItem, $productIds): void {
                $query->where('source_part_catalog_item_id', $catalogItem->id);

                if ($productIds->isNotEmpty()) {
                    $query->orWhereIn('id', $productIds->all());
                }
            })
            ->get()
            ->each(function (Product $product) use ($restoredQuantity, $order): void {
                $product->forceFill([
                    'storage_status' => $product->donor_car_id !== null
                        ? Product::STORAGE_STATUS_ON_DONOR
                        : Product::STORAGE_STATUS_IN_STOCK,
                    'is_active' => true,
                ])->save();

                $this->restoreProductStockItems($product->refresh(), $restoredQuantity, $order);
                app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            });
    }

    protected function restoreProductStockItems(Product $product, float $restoredQuantity, CustomerOrder $order): void
    {
        $product->loadMissing('stockItems');

        if ($product->stockItems->isEmpty() || $restoredQuantity <= 0) {
            return;
        }

        $currentQuantity = (float) $product->stockItems->sum('quantity');
        $targetQuantity = (int) ceil($restoredQuantity);
        $delta = $targetQuantity - (int) $currentQuantity;

        if ($delta <= 0) {
            return;
        }

        /** @var StockItem $stockItem */
        $stockItem = $product->stockItems->sortByDesc('id')->first();

        app(StockService::class)->adjust($stockItem, (int) $stockItem->quantity + $delta, [
            'reason' => 'customer_order_issue_cancelled',
            'document_number' => $order->number,
            'comment' => 'Customer order issue cancelled; product stock restored.',
        ]);
    }

    protected function syncProductSale(Product $product, float $stockAfterSale, CustomerOrder $order): void
    {
        $product->loadMissing('stockItems');

        if ($product->stockItems->isNotEmpty()) {
            /** @var StockItem $stockItem */
            $stockItem = $product->stockItems->sortByDesc('id')->first();
            app(StockService::class)->adjust($stockItem, (int) ceil($stockAfterSale), [
                'reason' => 'customer_order_issued',
                'document_number' => $order->number,
                'comment' => 'Customer order issued to client.',
            ]);
        }

        if ($stockAfterSale <= 0) {
            $product->forceFill([
                'storage_status' => Product::STORAGE_STATUS_SOLD,
                'is_active' => false,
            ])->save();
        }

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
    }

    protected function restoreProductFromCancelledSales(int $productId, Collection $sales, CustomerOrder $order): void
    {
        if ($productId <= 0) {
            return;
        }

        $product = Product::query()->with(['stockItems', 'sourcePartCatalogItem'])->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $restoredQuantity = round($this->physicalProductStockQuantity($product) + $sales->sum(fn (PartSale $sale): float => (float) $sale->quantity), 3);

        $product->forceFill([
            'storage_status' => $product->donor_car_id !== null
                ? Product::STORAGE_STATUS_ON_DONOR
                : Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ])->save();

        $this->restoreProductStockItems($product->refresh(), $restoredQuantity, $order);
        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        if ($product->sourcePartCatalogItem?->source === NikolaCarsInventoryService::SOURCE) {
            $this->restoreCatalogItemFromCancelledSales($product->sourcePartCatalogItem->id, $sales, $order, false, $restoredQuantity);
        }
    }

    protected function productStockQuantity(Product $product, ?PartCatalogItem $catalogItem = null): float
    {
        $product->loadMissing('stockItems');
        $availableQuantity = $product->stockItems->sum(function (StockItem $stockItem): float {
            return max(0.0, round((float) $stockItem->quantity - (float) $stockItem->reserved_quantity, 3));
        });

        if ($availableQuantity > 0) {
            return round($availableQuantity, 3);
        }

        $stockQuantity = (float) $product->stockItems->sum('quantity');

        if ($product->stockItems->isNotEmpty()) {
            return round($stockQuantity, 3);
        }

        return 0.0;
    }

    protected function physicalProductStockQuantity(Product $product): float
    {
        $product->loadMissing('stockItems');

        return round((float) $product->stockItems->sum('quantity'), 3);
    }

    protected function catalogStockQuantity(array $rawAttributes): float
    {
        $stockBeforeSale = data_get($rawAttributes, 'stock_quantity');

        return $stockBeforeSale !== null && $stockBeforeSale !== ''
            ? round((float) $stockBeforeSale, 3)
            : 0.0;
    }

    protected function catalogStockQuantityCappedToSourceRow(PartCatalogItem $catalogItem, float $quantity): float
    {
        $sourceRowStock = $this->numericQuantity(data_get(
            PartCatalogRawAttributes::from($catalogItem),
            'source_row.stock'
        ));

        if ($sourceRowStock === null) {
            return round($quantity, 3);
        }

        return round(min($quantity, $sourceRowStock), 3);
    }

    protected function numericQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return max(0.0, round((float) $normalized, 3));
    }

    protected function unitPriceUsd(CustomerOrderItem $orderItem, ?PartCatalogItem $catalogItem = null, ?Product $product = null): ?float
    {
        if ($orderItem->unit_price_usd_hint !== null) {
            return round((float) $orderItem->unit_price_usd_hint, 2);
        }

        if ($product instanceof Product && $product->selling_price !== null) {
            return strtoupper((string) ($product->currency ?: 'USD')) === 'USD'
                ? round((float) $product->selling_price, 2)
                : null;
        }

        return $catalogItem?->priceAmountUsd();
    }

    protected function donorCarId(PartCatalogItem $catalogItem, array $rawAttributes): ?int
    {
        $productId = (int) data_get($rawAttributes, 'product_id');
        $product = Product::query()
            ->where(function (Builder $query) use ($catalogItem, $productId): void {
                $query->where('source_part_catalog_item_id', $catalogItem->id);

                if ($productId > 0) {
                    $query->orWhere('id', $productId);
                }
            })
            ->whereNotNull('donor_car_id')
            ->first(['donor_car_id']);

        return $product?->donor_car_id;
    }

    protected function safeDonorVin(mixed $vin): ?string
    {
        $value = trim((string) $vin);

        return $value !== '' && mb_strlen($value) <= 17 ? $value : null;
    }

    protected function sourceRowHash(CustomerOrderItem $orderItem): string
    {
        return 'customer-order-'.$orderItem->customer_order_id.'-item-'.$orderItem->id;
    }

    protected function dateString(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->toDateString();
        }

        return $date ? substr((string) $date, 0, 10) : null;
    }
}
