<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockItem;
use Illuminate\Support\Collection;

class InventoryAutocompleteService
{
    public function productOptions(string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $likeQuery = '%'.$query.'%';

        return Product::query()
            ->select(['id', 'sku', 'external_sku', 'name'])
            ->where(function ($builder) use ($likeQuery): void {
                $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('sku', 'like', $likeQuery)
                    ->orWhere('external_sku', 'like', $likeQuery);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product));
    }

    public function stockItemOptions(string $query, bool $onlyAvailable = false, bool $includeProduct = false, int $limit = 20): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $likeQuery = '%'.$query.'%';

        return StockItem::query()
            ->with([
                'product:id,sku,external_sku,name',
                'warehouse:id,name',
                'location:id,full_code',
            ])
            ->when($onlyAvailable, fn ($builder) => $builder->where('available_quantity', '>', 0))
            ->where(function ($builder) use ($likeQuery): void {
                $builder
                    ->whereHas('product', function ($productQuery) use ($likeQuery): void {
                        $productQuery
                            ->where('name', 'like', $likeQuery)
                            ->orWhere('sku', 'like', $likeQuery)
                            ->orWhere('external_sku', 'like', $likeQuery);
                    })
                    ->orWhereHas('location', fn ($locationQuery) => $locationQuery->where('full_code', 'like', $likeQuery))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', $likeQuery));
            })
            ->orderByDesc('available_quantity')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (StockItem $stockItem): array => $this->stockItemOption($stockItem, $includeProduct));
    }

    public function selectedProduct(mixed $productId): ?array
    {
        if (! $productId) {
            return null;
        }

        $product = Product::query()
            ->select(['id', 'sku', 'external_sku', 'name'])
            ->find($productId);

        return $product ? $this->productOption($product) : null;
    }

    public function selectedStockItem(mixed $stockItemId, bool $includeProduct = false): ?array
    {
        if (! $stockItemId) {
            return null;
        }

        $stockItem = StockItem::query()
            ->with([
                'product:id,sku,external_sku,name',
                'warehouse:id,name',
                'location:id,full_code',
            ])
            ->find($stockItemId);

        return $stockItem ? $this->stockItemOption($stockItem, $includeProduct) : null;
    }

    public function productOption(Product $product): array
    {
        return [
            'id' => $product->id,
            'label' => collect([$product->sku, $product->name])->filter()->join(' · '),
            'meta' => $product->external_sku ? 'Артикул: '.$product->external_sku : '',
        ];
    }

    public function stockItemOption(StockItem $stockItem, bool $includeProduct = false): array
    {
        $product = $stockItem->product;
        $option = [
            'id' => $stockItem->id,
            'label' => collect([
                $product?->sku,
                $product?->name,
                $stockItem->location?->full_code,
            ])->filter()->join(' · '),
            'meta' => collect([
                $stockItem->warehouse?->name,
                'доступно '.$stockItem->available_quantity,
                'всего '.$stockItem->quantity,
            ])->filter()->join(' · '),
        ];

        if (! $includeProduct) {
            return $option;
        }

        $productOption = $product ? $this->productOption($product) : null;

        return [
            ...$option,
            'product_id' => $product?->id,
            'product_label' => $productOption['label'] ?? '',
            'product_meta' => $productOption['meta'] ?? '',
        ];
    }
}
