<?php

namespace App\Services;

use App\Models\DeletedPart;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class DeletedPartArchiveService
{
    public function archiveProduct(Product $product, string $source = 'products'): DeletedPart
    {
        $product->loadMissing(['donorCar:id,vin,model,year,color', 'sourcePartCatalogItem']);
        $catalogItem = $product->sourcePartCatalogItem;

        return DeletedPart::query()->create([
            'source' => $source,
            'product_id' => $product->id,
            'part_catalog_item_id' => $catalogItem?->id,
            'donor_car_id' => $product->donor_car_id,
            'donor_vin' => $product->donorCar?->vin,
            'sku' => $product->sku,
            'part_number' => $product->external_sku ?: $catalogItem?->part_number,
            'name' => $product->name,
            'product_snapshot' => $this->productSnapshot($product),
            'part_catalog_item_snapshot' => $catalogItem ? $this->catalogItemSnapshot($catalogItem) : null,
            'deleted_by' => Auth::id(),
            'deleted_at' => now(),
        ]);
    }

    public function archiveNikolaCarsItem(PartCatalogItem $item, Collection $products): DeletedPart
    {
        $firstProduct = $products->first();
        $donorCar = $firstProduct?->donorCar;
        $rawAttributes = $this->arrayValue($item->raw_attributes);

        return DeletedPart::query()->create([
            'source' => 'nikolacars',
            'product_id' => $firstProduct?->id,
            'part_catalog_item_id' => $item->id,
            'donor_car_id' => $donorCar?->id ?: data_get($rawAttributes, 'donor_car_id'),
            'donor_vin' => $donorCar?->vin ?: data_get($rawAttributes, 'donor_vin'),
            'sku' => $firstProduct?->sku ?: data_get($rawAttributes, 'code'),
            'part_number' => $item->part_number,
            'name' => $item->name_ru ?: $item->name_ua ?: $item->name ?: $item->part_number,
            'part_catalog_item_snapshot' => $this->catalogItemSnapshot($item),
            'related_product_snapshots' => $products
                ->map(fn (Product $product): array => $this->productSnapshot($product))
                ->values()
                ->all(),
            'deleted_by' => Auth::id(),
            'deleted_at' => now(),
        ]);
    }

    protected function productSnapshot(Product $product): array
    {
        $attributes = $product->attributesToArray();

        return array_replace($attributes, [
            'donor_vin' => $product->donorCar?->vin,
            'donor_label' => $product->donorCar
                ? trim(collect([$product->donorCar->display_model, $product->donorCar->vin])->filter()->implode(' '))
                : null,
            'source_catalog_source' => $product->sourcePartCatalogItem?->source,
            'source_catalog_part_number' => $product->sourcePartCatalogItem?->part_number,
        ]);
    }

    protected function catalogItemSnapshot(PartCatalogItem $item): array
    {
        $attributes = $item->attributesToArray();
        $attributes['raw_attributes'] = $this->arrayValue($item->raw_attributes);

        return $attributes;
    }

    protected function arrayValue(mixed $value): array
    {
        if ($value instanceof \ArrayObject) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
