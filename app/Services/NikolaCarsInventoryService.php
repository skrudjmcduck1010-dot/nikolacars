<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NikolaCarsInventoryService
{
    public const SOURCE = 'nikolacars';

    public const MANUAL_SOLD_AT = '2026-05-31';

    public function itemGroups(Collection $items, array $usdRate, callable $displayItemName): Collection
    {
        $groups = $items->groupBy(fn (PartCatalogItem $item): string => $this->groupKey($item));
        $productQuantities = $this->productQuantitiesForItems($items);
        $productPrices = $this->productPricePayloadsForItems($items);
        $productImageUrls = $this->productImageUrlsForItems($items);
        $officialPartImageUrls = $this->officialPartImageUrlsForItems($items);
        $productDonorColors = $this->productDonorColorsForItems($items);
        $reservationDetailsByGroup = $this->reservationDetailsByGroup($groups);

        return $groups
            ->map(function (Collection $group) use ($displayItemName, $officialPartImageUrls, $productDonorColors, $productImageUrls, $productPrices, $productQuantities, $reservationDetailsByGroup, $usdRate): array {
                /** @var PartCatalogItem $first */
                $first = $group->first();
                $reservationDetails = $reservationDetailsByGroup[$this->groupKey($first)] ?? [
                    'quantity' => 0.0,
                    'availability_quantity' => 0.0,
                    'quantity_text' => '',
                    'orders' => collect(),
                ];
                $stockQuantity = $this->inventoryQuantity($group, $productQuantities);
                $reservedQuantity = (float) $reservationDetails['quantity'];
                $availabilityReservedQuantity = (float) $reservationDetails['availability_quantity'];
                $quantity = $this->availableInventoryQuantity($stockQuantity, $availabilityReservedQuantity);
                $reservedOrders = $reservationDetails['orders'];
                $priceValues = $group
                    ->map(fn (PartCatalogItem $item): ?float => $this->itemPriceAmountUsd($item, $usdRate, $productPrices))
                    ->filter(fn (?float $price): bool => $price !== null)
                    ->values();
                $priceValuesUah = $group
                    ->map(fn (PartCatalogItem $item): ?float => $this->itemPriceAmountUah($item, $usdRate, $productPrices))
                    ->filter(fn (?float $price): bool => $price !== null)
                    ->values();
                $minPrice = $priceValues->isNotEmpty() ? round((float) $priceValues->min(), 2) : null;
                $maxPrice = $priceValues->isNotEmpty() ? round((float) $priceValues->max(), 2) : null;
                $minPriceUah = $priceValuesUah->isNotEmpty() ? round((float) $priceValuesUah->min(), 2) : null;
                $maxPriceUah = $priceValuesUah->isNotEmpty() ? round((float) $priceValuesUah->max(), 2) : null;
                $totalValueUsd = $this->inventoryTotalUsd($group, $usdRate, $availabilityReservedQuantity, $productQuantities, $productPrices);

                $partNumber = $this->normalizePartNumber((string) $first->part_number);

                return [
                    'item' => $first,
                    'items' => $group->values(),
                    'part_number' => $partNumber !== '' ? $partNumber : $first->part_number,
                    'part_numbers' => $group
                        ->pluck('part_number')
                        ->map(fn (?string $partNumber): string => $this->normalizePartNumber((string) $partNumber))
                        ->filter()
                        ->unique()
                        ->values(),
                    'count' => $group->count(),
                    'codes' => $this->uniqueAttributeValues($group, 'code'),
                    'image_urls' => $this->imageUrlsForGroup($group, $productImageUrls, $officialPartImageUrls),
                    'names' => $group
                        ->map(fn (PartCatalogItem $item): string => $displayItemName($item))
                        ->filter()
                        ->unique()
                        ->values(),
                    'vins' => $group
                        ->map(fn (PartCatalogItem $item): string => Str::upper(trim((string) data_get($item->raw_attributes, 'donor_vin', ''))))
                        ->filter()
                        ->unique()
                        ->values(),
                    'donor_colors' => $group
                        ->map(fn (PartCatalogItem $item): string => $this->donorColorForItem($item, $productDonorColors))
                        ->filter()
                        ->unique(fn (string $color): string => Str::lower($color))
                        ->values(),
                    'categories' => $group
                        ->map(fn (PartCatalogItem $item): string => $this->displayCategory($item))
                        ->filter()
                        ->unique()
                        ->values(),
                    'models' => $group
                        ->map(fn (PartCatalogItem $item): string => $this->displayModel($item))
                        ->filter()
                        ->unique()
                        ->values(),
                    'main_categories' => $group->pluck('main_category_name')->filter()->unique()->values(),
                    'damages' => $group
                        ->pluck('quality')
                        ->map(fn (?string $quality): string => trim((string) $quality))
                        ->filter()
                        ->unique()
                        ->values(),
                    'damage_status_changed_by_ids' => $group
                        ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'donor_damage_status_changed_by'))
                        ->filter()
                        ->unique()
                        ->values(),
                    'stock_quantity' => $stockQuantity,
                    'stock_quantity_text' => $this->quantityText($stockQuantity),
                    'quantity' => $quantity,
                    'quantity_text' => $this->quantityText($quantity),
                    'reserved_quantity' => $reservedQuantity,
                    'availability_reserved_quantity' => $availabilityReservedQuantity,
                    'reserved_quantity_text' => $reservationDetails['quantity_text'],
                    'is_reserved' => $reservedQuantity > 0,
                    'reserved_orders' => $reservedOrders,
                    'unit_price_value' => $minPrice !== null && $minPrice === $maxPrice ? $minPrice : null,
                    'unit_price_text' => $this->unitPriceText($priceValues),
                    'unit_price_uah_value' => $minPriceUah !== null && $minPriceUah === $maxPriceUah ? $minPriceUah : null,
                    'unit_price_uah_text' => $this->unitPriceUahText($priceValuesUah),
                    'total_value_usd' => $totalValueUsd,
                    'total_value_text' => $totalValueUsd > 0 ? number_format($totalValueUsd, 2, '.', ' ').' USD' : '-',
                ];
            })
            ->values();
    }

    protected function reservationDetailsByGroup(Collection $groups): array
    {
        $itemIdsByGroup = $groups->map(fn (Collection $group): Collection => $group
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values());
        $productIdsByGroup = $groups->map(fn (Collection $group): Collection => $group
            ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'))
            ->filter()
            ->values());
        $allItemIds = $itemIdsByGroup
            ->flatten()
            ->unique()
            ->values();
        $allProductIds = $productIdsByGroup
            ->flatten()
            ->unique()
            ->values();
        $orderItemsByCatalogItemId = collect();
        $orderItemsByProductId = collect();
        $rawOrderNumbersByGroup = $groups->map(fn (Collection $group): Collection => $this->rawReservedOrderNumbers($group));
        $rawOrdersByNumber = collect();

        if ($allItemIds->isNotEmpty()) {
            $orderItemsByCatalogItemId = CustomerOrderItem::query()
                ->with('order')
                ->whereIn('part_catalog_item_id', $allItemIds->all())
                ->whereHas('order', fn ($query) => $query->reservable())
                ->get()
                ->groupBy(fn (CustomerOrderItem $item): int => (int) $item->part_catalog_item_id);
        }

        if ($allProductIds->isNotEmpty()) {
            $orderItemsByProductId = CustomerOrderItem::query()
                ->with('order')
                ->whereIn('product_id', $allProductIds->all())
                ->whereHas('order', fn ($query) => $query->reservable())
                ->get()
                ->groupBy(fn (CustomerOrderItem $item): int => (int) $item->product_id);
        }

        $rawOrderNumbers = $rawOrderNumbersByGroup
            ->flatten()
            ->unique()
            ->values();

        if ($rawOrderNumbers->isNotEmpty()) {
            $rawOrdersByNumber = CustomerOrder::query()
                ->whereIn('number', $rawOrderNumbers->all())
                ->reservable()
                ->orderBy('number')
                ->get()
                ->keyBy('number');
        }

        return $groups
            ->mapWithKeys(function (Collection $group, string $groupKey) use ($itemIdsByGroup, $productIdsByGroup, $orderItemsByCatalogItemId, $orderItemsByProductId, $rawOrderNumbersByGroup, $rawOrdersByNumber): array {
                $orderItems = ($itemIdsByGroup->get($groupKey) ?? collect())
                    ->flatMap(fn (int $itemId): Collection => $orderItemsByCatalogItemId->get($itemId, collect()))
                    ->merge(($productIdsByGroup->get($groupKey) ?? collect())
                        ->flatMap(fn (int $productId): Collection => $orderItemsByProductId->get($productId, collect())))
                    ->unique('id')
                    ->values();

                if ($orderItems->isNotEmpty()) {
                    $quantity = round((float) $orderItems->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3);
                    $availabilityQuantity = round((float) $orderItems
                        ->reject(fn (CustomerOrderItem $item): bool => $item->order?->isIssuedToClient() ?? false)
                        ->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3);

                    return [$groupKey => [
                        'quantity' => $quantity,
                        'availability_quantity' => $availabilityQuantity,
                        'quantity_text' => $quantity > 0 ? $this->quantityText($quantity) : '',
                        'orders' => $this->reservedOrdersFromOrderItems($orderItems),
                    ]];
                }

                $quantity = round($group->sum(function (PartCatalogItem $item): float {
                    $quantity = data_get($item->raw_attributes, 'reserved_quantity');

                    return $quantity !== null && $quantity !== '' ? (float) $quantity : 0.0;
                }), 3);

                return [$groupKey => [
                    'quantity' => $quantity,
                    'availability_quantity' => $quantity,
                    'quantity_text' => $quantity > 0 ? $this->quantityText($quantity) : '',
                    'orders' => $this->reservedOrdersFromNumbers(
                        $rawOrderNumbersByGroup->get($groupKey, collect()),
                        $rawOrdersByNumber,
                    ),
                ]];
            })
            ->all();
    }

    public function relatedItems(PartCatalogItem $item): Collection
    {
        if ($item->source !== 'nikolacars') {
            return collect([$item]);
        }

        $partNumberPrefix = $this->partNumberPrefix((string) $item->part_number);

        if ($partNumberPrefix === '') {
            return collect([$item]);
        }

        $donorVinExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(raw_attributes, '$.donor_vin')"
            : "json_unquote(json_extract(raw_attributes, '$.donor_vin'))";

        return PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('part_number', 'like', $partNumberPrefix.'%')
            ->orderByRaw($donorVinExpression.' is null')
            ->orderByRaw($donorVinExpression)
            ->orderBy('name_ua')
            ->orderBy('name')
            ->get();
    }

    public function donorCarsByVin(PartCatalogItem $item, Collection $relatedItems): Collection
    {
        if ($item->source !== 'nikolacars') {
            return collect();
        }

        $vins = collect([$item])
            ->merge($relatedItems)
            ->map(fn (PartCatalogItem $relatedItem): string => Str::upper(trim((string) data_get($relatedItem->raw_attributes, 'donor_vin', ''))))
            ->filter()
            ->unique()
            ->values();

        if ($vins->isEmpty()) {
            return collect();
        }

        return DonorCar::query()
            ->whereIn('vin', $vins->all())
            ->get(['id', 'vin', 'model', 'year', 'color', 'paint_code'])
            ->keyBy('vin');
    }

    public function groupKey(PartCatalogItem $item): string
    {
        $partNumber = $this->normalizePartNumber((string) $item->part_number);

        if ($partNumber !== '') {
            return 'part-number:'.$partNumber;
        }

        return 'item:'.$item->id;
    }

    public function inventoryQuantity(Collection $items, ?Collection $productQuantities = null): float
    {
        $productQuantities ??= $this->productQuantitiesForItems($items);

        return round($items->sum(fn (PartCatalogItem $item): float => $this->itemInventoryQuantity($item, $productQuantities)), 3);
    }

    public function itemInventoryQuantity(PartCatalogItem $item, ?Collection $productQuantities = null): float
    {
        if ($this->isManuallySold($item)) {
            return 0.0;
        }

        $productId = $this->productIdFromItem($item);
        if ($productId > 0) {
            $productQuantities ??= $this->productQuantitiesForItems(collect([$item]));

            if ($productQuantities->has($productId)) {
                return (float) $productQuantities->get($productId);
            }
        }

        $quantity = data_get($item->raw_attributes, 'stock_quantity');

        return $quantity !== null && $quantity !== '' ? round((float) $quantity, 3) : 0.0;
    }

    public function productQuantitiesForItems(Collection $items): Collection
    {
        $productIds = $this->productIdsForItems($items);

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->with('stockItems')
            ->whereIn('id', $productIds->all())
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => $this->productInventoryQuantity($product),
            ]);
    }

    public function productPricePayloadsForItems(Collection $items): Collection
    {
        $productIds = $this->productIdsForItems($items);

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $productIds->all())
            ->get(['id', 'selling_price', 'currency'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => [
                    'amount' => $product->selling_price !== null ? (float) $product->selling_price : null,
                    'currency' => $product->currency ?: 'USD',
                ],
            ]);
    }

    protected function productIdsForItems(Collection $items): Collection
    {
        return $items
            ->map(fn (PartCatalogItem $item): int => $this->productIdFromItem($item))
            ->filter()
            ->unique()
            ->values();
    }

    protected function productIdFromItem(PartCatalogItem $item): int
    {
        $productId = (int) data_get($item->raw_attributes, 'product_id');

        if ($productId > 0) {
            return $productId;
        }

        return preg_match('~^nikolacars://(?:donor-product|inventory-product)/(\d+)$~', (string) $item->source_url, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    protected function itemPriceAmountUsd(PartCatalogItem $item, array $usdRate, ?Collection $productPrices = null): ?float
    {
        $productPrices ??= $this->productPricePayloadsForItems(collect([$item]));
        $productPrice = $this->productPricePayloadForItem($item, $productPrices);

        if ($productPrice !== null) {
            if ($productPrice['amount'] === null) {
                return null;
            }

            return $this->priceAmountUsd((float) $productPrice['amount'], (string) $productPrice['currency'], $usdRate);
        }

        return $item->priceAmountUsd($usdRate);
    }

    protected function itemPriceAmountUah(PartCatalogItem $item, array $usdRate, ?Collection $productPrices = null): ?float
    {
        $productPrices ??= $this->productPricePayloadsForItems(collect([$item]));
        $productPrice = $this->productPricePayloadForItem($item, $productPrices);

        if ($productPrice !== null) {
            if ($productPrice['amount'] === null) {
                return null;
            }

            return app(ExchangeRateService::class)->productSellingPriceUahRoundedToTen(
                (float) $productPrice['amount'],
                (string) $productPrice['currency'],
                $usdRate,
            );
        }

        return $item->price_amount !== null
            ? app(ExchangeRateService::class)->productSellingPriceUahRoundedToTen((float) $item->price_amount, $item->currency, $usdRate)
            : null;
    }

    protected function productPricePayloadForItem(PartCatalogItem $item, Collection $productPrices): ?array
    {
        $productId = $this->productIdFromItem($item);

        if ($productId <= 0 || ! $productPrices->has($productId)) {
            return null;
        }

        $payload = $productPrices->get($productId);

        return is_array($payload) ? $payload : null;
    }

    protected function priceAmountUsd(float $amount, string $currency, array $usdRate): ?float
    {
        $currency = Str::upper($currency ?: 'USD');

        if ($currency === 'UAH') {
            $rate = (float) ($usdRate['rate'] ?? 0);

            return $rate > 0 ? round($amount / $rate, 2) : null;
        }

        return round($amount, 2);
    }

    protected function productDonorColorsForItems(Collection $items): Collection
    {
        $productIds = $this->productIdsForItems($items);

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->with('donorCar:id,color')
            ->whereIn('id', $productIds->all())
            ->get(['id', 'donor_car_id'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => trim((string) $product->donorCar?->color),
            ])
            ->filter();
    }

    protected function donorColorForItem(PartCatalogItem $item, Collection $productDonorColors): string
    {
        $productId = $this->productIdFromItem($item);

        return $productId > 0
            ? trim((string) $productDonorColors->get($productId, ''))
            : '';
    }

    protected function productImageUrlsForItems(Collection $items): Collection
    {
        $productIds = $this->productIdsForItems($items);

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $productIds->all())
            ->get(['id', 'main_image', 'images_json'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => ProductPhotoNormalizer::productPhotos($product)
                    ->map(fn (string $path): string => PublicStorageUrl::url($path) ?? $path)
                    ->unique()
                    ->values(),
            ]);
    }

    public function imageUrlsForItem(PartCatalogItem $item): Collection
    {
        return $this->imageUrlsForGroup(
            collect([$item]),
            $this->productImageUrlsForItems(collect([$item])),
            $this->officialPartImageUrlsForItems(collect([$item])),
        );
    }

    protected function imageUrlsForGroup(Collection $group, Collection $productImageUrls, ?Collection $officialPartImageUrls = null): Collection
    {
        $officialPartImageUrls ??= collect();

        return $group
            ->flatMap(function (PartCatalogItem $item) use ($officialPartImageUrls, $productImageUrls): Collection {
                $productId = $this->productIdFromItem($item);
                $partNumber = $this->normalizePartNumber((string) $item->part_number);

                return $productImageUrls
                    ->get($productId, collect())
                    ->merge($officialPartImageUrls->get($partNumber, collect()))
                    ->merge((array) data_get($item->raw_attributes, 'image_urls', []));
            })
            ->map(fn (mixed $url): string => trim((string) $url))
            ->filter()
            ->reject(fn (string $url): bool => ProductPhotoNormalizer::isCatalogSchemeImage($url))
            ->map(fn (string $url): string => PublicStorageUrl::url($url) ?? $url)
            ->unique(fn (string $url): string => ProductPhotoNormalizer::imageKey($url))
            ->values();
    }

    protected function officialPartImageUrlsForItems(Collection $items): Collection
    {
        $partNumbers = $items
            ->map(fn (PartCatalogItem $item): string => $this->normalizePartNumber((string) $item->part_number))
            ->filter(fn (string $partNumber): bool => $this->isTeslaPartNumberShape($partNumber))
            ->unique()
            ->values();

        if ($partNumbers->isEmpty()) {
            return collect();
        }

        $prefixes = $partNumbers
            ->map(fn (string $partNumber): ?string => preg_match('/^(\d{7})/', $partNumber, $matches) === 1 ? $matches[1] : null)
            ->filter()
            ->unique()
            ->values();

        if ($prefixes->isEmpty()) {
            return collect();
        }

        $candidatesByPrefix = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereIn(DB::raw('substr(part_number, 1, 7)'), $prefixes->all())
            ->orderByRaw('case when source_url like ? then 1 else 0 end', ['%vin=%'])
            ->orderByRaw('case when raw_attributes like ? then 1 else 0 end', ['%"donor_vin"%'])
            ->orderByRaw('part_catalog_category_id is null')
            ->orderBy('id')
            ->get(['id', 'part_number', 'source_url', 'part_catalog_category_id', 'raw_attributes'])
            ->groupBy(fn (PartCatalogItem $item): string => substr($this->normalizePartNumber((string) $item->part_number), 0, 7));

        return $partNumbers
            ->mapWithKeys(function (string $partNumber) use ($candidatesByPrefix): array {
                $prefix = substr($partNumber, 0, 7);
                $candidates = $candidatesByPrefix->get($prefix, collect());
                $officialItem = $candidates->first(
                    fn (PartCatalogItem $candidate): bool => $this->normalizePartNumber((string) $candidate->part_number) === $partNumber
                ) ?: $candidates->first();

                return [
                    $partNumber => $officialItem instanceof PartCatalogItem
                        ? $this->officialPartImageUrls($officialItem)
                        : collect(),
                ];
            });
    }

    protected function officialPartImageUrls(PartCatalogItem $officialItem): Collection
    {
        return collect((array) data_get($officialItem->raw_attributes, 'part_image_urls', []))
            ->merge((array) data_get($officialItem->raw_attributes, 'image_urls', []))
            ->map(fn (mixed $url): string => trim((string) $url))
            ->filter()
            ->reject(fn (string $url): bool => ProductPhotoNormalizer::isCatalogSchemeImage($url))
            ->unique(fn (string $url): string => ProductPhotoNormalizer::imageKey($url))
            ->values();
    }

    public function productInventoryQuantity(Product $product): float
    {
        if (in_array($product->storage_status, [
            Product::STORAGE_STATUS_SOLD,
            Product::STORAGE_STATUS_WRITTEN_OFF,
        ], true) || $product->is_active === false) {
            return 0.0;
        }

        $product->loadMissing('stockItems');

        if ($product->stockItems->isEmpty()) {
            return 0.0;
        }

        $availableQuantity = $product->stockItems->sum(function ($stockItem): float {
            $quantity = (float) $stockItem->quantity;
            $reserved = (float) $stockItem->reserved_quantity;

            return max(0.0, round($quantity - $reserved, 3));
        });

        if ($availableQuantity > 0) {
            return round((float) $availableQuantity, 3);
        }

        return round((float) $product->stockItems->sum('quantity'), 3);
    }

    public function reservedQuantity(Collection $items): float
    {
        $itemIds = $items->pluck('id')->filter()->values();
        $productIds = $items
            ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'))
            ->filter()
            ->values();
        if ($itemIds->isNotEmpty()) {
            $reservedByOrders = (float) CustomerOrderItem::query()
                ->where(function ($query) use ($itemIds, $productIds): void {
                    $query->whereIn('part_catalog_item_id', $itemIds->all());

                    if ($productIds->isNotEmpty()) {
                        $query->orWhereIn('product_id', $productIds->all());
                    }
                })
                ->whereHas('order', fn ($query) => $query->reservable())
                ->sum('quantity');

            if ($reservedByOrders > 0) {
                return round($reservedByOrders, 3);
            }
        }

        return round($items->sum(function (PartCatalogItem $item): float {
            $quantity = data_get($item->raw_attributes, 'reserved_quantity');

            return $quantity !== null && $quantity !== '' ? (float) $quantity : 0.0;
        }), 3);
    }

    public function reservedOrders(Collection $items): Collection
    {
        $itemIds = $items->pluck('id')->filter()->values();
        $productIds = $items
            ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'))
            ->filter()
            ->values();
        $orders = collect();

        if ($itemIds->isNotEmpty()) {
            $orders = CustomerOrderItem::query()
                ->with('order')
                ->where(function ($query) use ($itemIds, $productIds): void {
                    $query->whereIn('part_catalog_item_id', $itemIds->all());

                    if ($productIds->isNotEmpty()) {
                        $query->orWhereIn('product_id', $productIds->all());
                    }
                })
                ->whereHas('order', fn ($query) => $query->reservable())
                ->get()
                ->groupBy('customer_order_id')
                ->map(function (Collection $orderItems): array {
                    /** @var CustomerOrderItem $first */
                    $first = $orderItems->first();

                    return [
                        'order' => $first->order,
                        'quantity' => round((float) $orderItems->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3),
                    ];
                })
                ->filter(fn (array $reservedOrder): bool => $reservedOrder['order'] instanceof CustomerOrder)
                ->values();
        }

        if ($orders->isNotEmpty()) {
            return $orders;
        }

        $orderNumbers = $items
            ->flatMap(fn (PartCatalogItem $item): array => (array) data_get($item->raw_attributes, 'reserved_orders', []))
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter()
            ->unique()
            ->values();

        if ($orderNumbers->isEmpty()) {
            return collect();
        }

        return CustomerOrder::query()
            ->whereIn('number', $orderNumbers->all())
            ->reservable()
            ->orderBy('number')
            ->get()
            ->map(fn (CustomerOrder $order): array => [
                'order' => $order,
                'quantity' => null,
            ])
            ->values();
    }

    protected function reservedOrdersFromOrderItems(Collection $orderItems): Collection
    {
        return $orderItems
            ->groupBy('customer_order_id')
            ->map(function (Collection $items): array {
                /** @var CustomerOrderItem $first */
                $first = $items->first();

                return [
                    'order' => $first->order,
                    'quantity' => round((float) $items->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3),
                ];
            })
            ->filter(fn (array $reservedOrder): bool => $reservedOrder['order'] instanceof CustomerOrder)
            ->values();
    }

    protected function reservedOrdersFromRawAttributes(Collection $items): Collection
    {
        $orderNumbers = $this->rawReservedOrderNumbers($items);

        if ($orderNumbers->isEmpty()) {
            return collect();
        }

        $ordersByNumber = CustomerOrder::query()
            ->whereIn('number', $orderNumbers->all())
            ->reservable()
            ->orderBy('number')
            ->get()
            ->keyBy('number');

        return $this->reservedOrdersFromNumbers($orderNumbers, $ordersByNumber);
    }

    protected function rawReservedOrderNumbers(Collection $items): Collection
    {
        return $items
            ->flatMap(fn (PartCatalogItem $item): array => (array) data_get($item->raw_attributes, 'reserved_orders', []))
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter()
            ->unique()
            ->values();
    }

    protected function reservedOrdersFromNumbers(Collection $orderNumbers, Collection $ordersByNumber): Collection
    {
        if ($orderNumbers->isEmpty()) {
            return collect();
        }

        $selectedNumbers = $orderNumbers->flip();

        return $ordersByNumber
            ->filter(fn (CustomerOrder $order, string $number): bool => $selectedNumbers->has($number))
            ->map(fn (CustomerOrder $order): array => [
                'order' => $order,
                'quantity' => null,
            ])
            ->values();
    }

    public function inventoryTotalUsd(Collection $items, array $usdRate, float $reservedQuantity = 0.0, ?Collection $productQuantities = null, ?Collection $productPrices = null): float
    {
        $remainingReservedQuantity = max(0.0, $reservedQuantity);
        $productQuantities ??= $this->productQuantitiesForItems($items);
        $productPrices ??= $this->productPricePayloadsForItems($items);

        return round($items->sum(function (PartCatalogItem $item) use ($productPrices, $productQuantities, $usdRate, &$remainingReservedQuantity): float {
            if ($this->isManuallySold($item)) {
                return 0.0;
            }

            $price = $this->itemPriceAmountUsd($item, $usdRate, $productPrices);
            if ($price === null) {
                return 0.0;
            }

            $stock = $this->itemInventoryQuantity($item, $productQuantities);
            $availableStock = $this->availableInventoryQuantity($stock, $remainingReservedQuantity);
            $remainingReservedQuantity = max(0.0, round($remainingReservedQuantity - $stock, 3));

            return $price * $availableStock;
        }), 2);
    }

    public function uniqueItemsCount(?Collection $items = null): int
    {
        $items ??= PartCatalogItem::query()->where('source', 'nikolacars')->get();

        return $items->groupBy(fn (PartCatalogItem $item): string => $this->groupKey($item))->count();
    }

    public function formattedInventoryTotalUsd(array $usdRate): string
    {
        $total = $this
            ->itemGroups(
                $this->activeItemsQuery()->get(),
                $usdRate,
                fn (PartCatalogItem $item): string => (string) ($item->name_ua ?: $item->name_ru ?: $item->name),
            )
            ->sum(fn (array $group): float => (float) $group['total_value_usd']);

        return number_format($total, 2, '.', ' ').' USD';
    }

    public function isManuallySold(PartCatalogItem $item): bool
    {
        return (string) data_get($item->raw_attributes, 'manual_sold_at', '') !== '';
    }

    public function activeItemsQuery()
    {
        $stockQuantity = $this->jsonTextExpression('raw_attributes', 'stock_quantity');

        return PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->where(function ($query): void {
                $query
                    ->whereNull('raw_attributes')
                    ->orWhere('raw_attributes', 'not like', '%"manual_sold_at"%');
            })
            ->where(function ($query) use ($stockQuantity): void {
                $query
                    ->whereDoesntHave('sales', fn ($salesQuery) => $salesQuery
                        ->where('source', self::SOURCE))
                    ->orWhereRaw("cast(coalesce({$stockQuantity}, '0') as decimal(12,3)) > 0");
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('quality')
                    ->orWhere('quality', '!=', NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS);
            })
            ->tap(fn ($query) => $this->whereCheckedDonorInventoryStatus($query))
            ->where(function ($query): void {
                $query
                    ->whereNull('raw_attributes')
                    ->orWhere(function ($activeQuery): void {
                        foreach ([Product::STORAGE_STATUS_SOLD, Product::STORAGE_STATUS_WRITTEN_OFF] as $status) {
                            $activeQuery
                                ->where('raw_attributes', 'not like', '%"storage_status":"'.$status.'"%')
                                ->where('raw_attributes', 'not like', '%"storage_status": "'.$status.'"%');
                        }
                    });
            });
    }

    private function whereCheckedDonorInventoryStatus($query): void
    {
        $checkedStatuses = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES;
        $productId = $this->jsonTextExpression('part_catalog_items.raw_attributes', 'product_id');
        $productIdCast = match (DB::connection()->getDriverName()) {
            'pgsql' => "cast({$productId} as bigint)",
            'sqlite' => "cast({$productId} as integer)",
            default => "cast({$productId} as unsigned)",
        };
        $donorDamageStatus = $this->jsonTextExpression('part_catalog_items.raw_attributes', 'donor_damage_status');
        $projectedStatus = "coalesce(nullif(trim(part_catalog_items.quality), ''), nullif(trim({$donorDamageStatus}), ''))";

        $query->where(function ($builder) use ($checkedStatuses, $productId, $productIdCast, $projectedStatus): void {
            $builder
                ->where(function ($nonDonor): void {
                    $nonDonor
                        ->where(function ($sourceUrl): void {
                            $sourceUrl
                                ->whereNull('part_catalog_items.source_url')
                                ->orWhere('part_catalog_items.source_url', 'not like', 'nikolacars://donor-product/%');
                        })
                        ->where(function ($rawAttributes): void {
                            $rawAttributes
                                ->whereNull('part_catalog_items.raw_attributes')
                                ->orWhere(function ($raw): void {
                                    $raw
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"source_type":"donor"%')
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"source_type": "donor"%')
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"donor_car_id"%');
                                });
                        });
                })
                ->orWhere(function ($donor): void {
                    $donor
                        ->where('part_catalog_items.source_url', 'like', 'nikolacars://donor-product/%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"source_type":"donor"%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"source_type": "donor"%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"donor_car_id"%');
                })
                ->where(function ($status) use ($checkedStatuses, $productId, $productIdCast, $projectedStatus): void {
                    $status
                        ->whereExists(function ($exists) use ($checkedStatuses, $productIdCast): void {
                            $exists
                                ->selectRaw('1')
                                ->from('products')
                                ->whereRaw("products.id = {$productIdCast}")
                                ->whereIn('products.notes', $checkedStatuses);
                        })
                        ->orWhere(function ($legacy) use ($checkedStatuses, $productId, $projectedStatus): void {
                            $legacy
                                ->whereRaw("coalesce({$productId}, '') = ''")
                                ->whereRaw("{$projectedStatus} in (?, ?, ?)", $checkedStatuses);
                        });
                });
        });
    }

    private function jsonTextExpression(string $column, string $key): string
    {
        $path = '$.'.$key;

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "{$column}::jsonb ->> '{$key}'",
            'sqlite' => "json_extract({$column}, '{$path}')",
            default => "json_unquote(json_extract({$column}, '{$path}'))",
        };
    }

    public function normalizePartNumber(string $partNumber): string
    {
        $partNumber = Str::upper(str_replace(' ', '', trim($partNumber)));

        if (preg_match('/^(\d{7})([A-Z0-9]{2})([A-Z0-9])$/', $partNumber, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $partNumber;
    }

    public function partNumberPrefix(string $partNumber): string
    {
        $partNumber = $this->normalizePartNumber($partNumber);

        return preg_match('/^(\d{7})/', $partNumber, $matches) === 1 ? $matches[1] : '';
    }

    public function isTeslaPartNumberShape(string $partNumber): bool
    {
        return preg_match('/^\d{7}-[A-Z0-9]{2}-[A-Z0-9]$/', $this->normalizePartNumber($partNumber)) === 1;
    }

    public function availability(float $quantity): string
    {
        return $this->quantityText($quantity);
    }

    public function displayDescription(PartCatalogItem $item, ?string $description): string
    {
        return $this->withoutPartNumber(trim((string) $description), (string) $item->part_number);
    }

    public function displayCategory(PartCatalogItem $item): string
    {
        $category = trim((string) (
            data_get($item->raw_attributes, 'category_display')
            ?: data_get($item->raw_attributes, 'category_path')
            ?: $item->main_category_name
            ?: $item->compatibility_text
            ?: ''
        ));

        if ($category === '') {
            return '';
        }

        return collect(preg_split('/\s*\/\s*/u', $category) ?: [])
            ->map(fn (string $part): string => $this->translateCategoryPart($part))
            ->filter()
            ->unique()
            ->implode(' / ');
    }

    public function displayModel(PartCatalogItem $item): string
    {
        $model = trim((string) ($item->model_label ?: $item->model_name ?: $item->compatibility_text ?: ''));

        if ($model === '' || preg_match('/\b(?:19|20)\d{2}\b/u', $model) === 1) {
            return $model;
        }

        $years = $this->modelYears($item);

        return trim($model.' '.$years);
    }

    public function withoutPartNumber(string $name, string $partNumber): string
    {
        $partNumber = trim($partNumber);

        if ($name === '' || $partNumber === '') {
            return $name;
        }

        $partNumberPattern = preg_quote($partNumber, '/');
        $partNumberLabelPattern = '(?:\x{0430}\x{0440}\x{0442}\.?|\x{0430}\x{0440}\x{0442}\x{0438}\x{043A}\x{0443}\x{043B}(?:\x{044B})?|part\s*(?:no\.?|number)?|vendor\s*code)\s*[:\x{2116}#-]?\s*';
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberLabelPattern.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $name);
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $cleaned);

        if ($cleaned === $name) {
            return $name;
        }

        $cleaned = trim((string) preg_replace('/\s{2,}/u', ' ', $cleaned));

        return trim($cleaned, " \t\n\r\0\x0B,;.-");
    }

    private function quantityText(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' '."\u{0448}\u{0442}";
    }

    private function availableInventoryQuantity(float $stockQuantity, float $reservedQuantity): float
    {
        return max(0.0, round($stockQuantity - $reservedQuantity, 3));
    }

    private function unitPriceText(Collection $priceValues): string
    {
        if ($priceValues->isEmpty()) {
            return '-';
        }

        $min = round((float) $priceValues->min(), 2);
        $max = round((float) $priceValues->max(), 2);

        if ($min === $max) {
            return number_format($min, 2, '.', ' ').' USD';
        }

        return number_format($min, 2, '.', ' ').'-'.number_format($max, 2, '.', ' ').' USD';
    }

    private function unitPriceUahText(Collection $priceValues): string
    {
        if ($priceValues->isEmpty()) {
            return '-';
        }

        $min = round((float) $priceValues->min(), 2);
        $max = round((float) $priceValues->max(), 2);

        if ($min === $max) {
            return number_format($min, 0, '.', ' ').' грн';
        }

        return number_format($min, 0, '.', ' ').'-'.number_format($max, 0, '.', ' ').' грн';
    }

    private function uniqueAttributeValues(Collection $items, string $key): Collection
    {
        return $items
            ->map(fn (PartCatalogItem $item): string => (string) data_get($item->raw_attributes, $key, ''))
            ->filter()
            ->unique()
            ->values();
    }

    private function modelYears(PartCatalogItem $item): string
    {
        $yearFrom = $item->year_from !== null ? (int) $item->year_from : null;
        $yearTo = $item->year_to !== null ? (int) $item->year_to : null;

        if ($yearFrom === null && $yearTo === null) {
            return '';
        }

        if ($yearFrom !== null && $yearTo !== null) {
            return $yearFrom === $yearTo ? (string) $yearFrom : "{$yearFrom}-{$yearTo}";
        }

        return $yearFrom !== null ? "{$yearFrom}-" : "-{$yearTo}";
    }

    public function translateCategoryPart(string $part): string
    {
        $part = $this->withoutTeslaCategoryCode($part);

        if ($part === '') {
            return '';
        }

        $normalized = Str::lower((string) preg_replace('/\s+/u', ' ', $part));

        return self::CATEGORY_RU_LABELS[$normalized] ?? $part;
    }

    public function withoutTeslaCategoryCode(string $part): string
    {
        return trim((string) preg_replace('/^\d+\s*[-\x{2013}\x{2014}]\s*/u', '', trim($part)));
    }

    public function categorySearchAliases(string $query): array
    {
        $query = Str::lower((string) preg_replace('/\s+/u', ' ', trim($query)));

        if ($query === '') {
            return [];
        }

        return collect(self::CATEGORY_RU_LABELS)
            ->filter(function (string $label, string $source) use ($query): bool {
                $label = Str::lower((string) preg_replace('/\s+/u', ' ', trim($label)));
                $source = Str::lower((string) preg_replace('/\s+/u', ' ', trim($source)));

                return str_contains($label, $query)
                    || str_contains($query, $label)
                    || str_contains($source, $query);
            })
            ->keys()
            ->values()
            ->all();
    }

    private const CATEGORY_RU_LABELS = [
        'body' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}",
        'closures' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A}",
        'hood' => "\u{041A}\u{0430}\u{043F}\u{043E}\u{0442}",
        'bumper and fascia' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{0438} \u{043E}\u{0431}\u{043B}\u{0438}\u{0446}\u{043E}\u{0432}\u{043A}\u{0430}",
        'body panels' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}\u{043D}\u{044B}\u{0435} \u{043F}\u{0430}\u{043D}\u{0435}\u{043B}\u{0438}",
        'closure panels' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{043A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A}\u{0430}",
        'closure components' => "\u{041A}\u{043E}\u{043C}\u{043F}\u{043E}\u{043D}\u{0435}\u{043D}\u{0442}\u{044B} \u{0437}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{0438}\u{044F}",
        'closure assist mechanisms and hinges' => "\u{041C}\u{0435}\u{0445}\u{0430}\u{043D}\u{0438}\u{0437}\u{043C}\u{044B} \u{0434}\u{043E}\u{0432}\u{043E}\u{0434}\u{0447}\u{0438}\u{043A}\u{043E}\u{0432} \u{0438} \u{043F}\u{0435}\u{0442}\u{043B}\u{0438}",
        'front door hinges and fittings' => "\u{041F}\u{0435}\u{0442}\u{043B}\u{0438} \u{0438} \u{043A}\u{0440}\u{0435}\u{043F}\u{043B}\u{0435}\u{043D}\u{0438}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0439} \u{0434}\u{0432}\u{0435}\u{0440}\u{0438}",
        'front bumper fascia' => "\u{041E}\u{0431}\u{043B}\u{0438}\u{0446}\u{043E}\u{0432}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0433}\u{043E} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}",
        'rear bumper fascia' => "\u{041E}\u{0431}\u{043B}\u{0438}\u{0446}\u{043E}\u{0432}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{0435}\u{0433}\u{043E} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}",
        'front energy absorber' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439} \u{0430}\u{0431}\u{0441}\u{043E}\u{0440}\u{0431}\u{0435}\u{0440}",
        'rear energy absorber' => "\u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{0430}\u{0431}\u{0441}\u{043E}\u{0440}\u{0431}\u{0435}\u{0440}",
        'electrical' => "\u{042D}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{0438}\u{043A}\u{0430}",
        'interior' => "\u{0418}\u{043D}\u{0442}\u{0435}\u{0440}\u{044C}\u{0435}\u{0440}",
        'exterior' => "\u{042D}\u{043A}\u{0441}\u{0442}\u{0435}\u{0440}\u{044C}\u{0435}\u{0440}",
        'seats' => "\u{0421}\u{0438}\u{0434}\u{0435}\u{043D}\u{044C}\u{044F}",
        'brakes' => "\u{0422}\u{043E}\u{0440}\u{043C}\u{043E}\u{0437}\u{0430}",
        'suspension' => "\u{041F}\u{043E}\u{0434}\u{0432}\u{0435}\u{0441}\u{043A}\u{0430}",
        'wheels and tires' => "\u{041A}\u{043E}\u{043B}\u{0435}\u{0441}\u{0430} \u{0438} \u{0448}\u{0438}\u{043D}\u{044B}",
        'thermal' => "\u{0422}\u{0435}\u{0440}\u{043C}\u{043E}\u{0441}\u{0438}\u{0441}\u{0442}\u{0435}\u{043C}\u{0430}",
        'hv battery system' => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
        'hv battery assembly' => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0432} \u{0441}\u{0431}\u{043E}\u{0440}\u{0435}",
        'high voltage battery assembly' => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0432} \u{0441}\u{0431}\u{043E}\u{0440}\u{0435}",
        'high voltage system' => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0441}\u{0438}\u{0441}\u{0442}\u{0435}\u{043C}\u{0430}",
        "\u{0432}\u{0438}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}" => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
        "\u{0432}\u{0438}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430} \u{0441}\u{0438}\u{0441}\u{0442}\u{0435}\u{043C}\u{0430}" => "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0441}\u{0438}\u{0441}\u{0442}\u{0435}\u{043C}\u{0430}",
        'front drive unit' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439} \u{043F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434}\u{043D}\u{043E}\u{0439} \u{0431}\u{043B}\u{043E}\u{043A}",
        'rear drive unit' => "\u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{043F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434}\u{043D}\u{043E}\u{0439} \u{0431}\u{043B}\u{043E}\u{043A}",
        'external charging connectors' => "\u{0412}\u{043D}\u{0435}\u{0448}\u{043D}\u{0438}\u{0435} \u{0437}\u{0430}\u{0440}\u{044F}\u{0434}\u{043D}\u{044B}\u{0435} \u{0440}\u{0430}\u{0437}\u{044A}\u{0435}\u{043C}\u{044B}",
        'owner information' => "\u{0418}\u{043D}\u{0444}\u{043E}\u{0440}\u{043C}\u{0430}\u{0446}\u{0438}\u{044F} \u{0434}\u{043B}\u{044F} \u{0432}\u{043B}\u{0430}\u{0434}\u{0435}\u{043B}\u{044C}\u{0446}\u{0430}",
        "\u{043D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}" => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
        "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}" => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
    ];
}
