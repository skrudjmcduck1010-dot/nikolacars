<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeslaCatalogDonorProductSync
{
    protected const SOURCE = 'tesla_official';

    public function syncDonor(DonorCar $donorCar): array
    {
        $stats = [
            'products_seen' => 0,
            'catalog_items_created' => 0,
            'products_linked' => 0,
        ];

        $donorCar->products()
            ->whereNotNull('external_sku')
            ->orderBy('id')
            ->chunkById(300, function (Collection $products) use (&$stats): void {
                foreach ($products as $product) {
                    $stats['products_seen']++;
                    $result = $this->syncProduct($product);
                    $stats['catalog_items_created'] += $result['created'] ? 1 : 0;
                    $stats['products_linked'] += $result['linked'] ? 1 : 0;
                }
            });

        return $stats;
    }

    public function syncProduct(Product $product): array
    {
        $partNumber = $this->normalizePartNumber($product->external_sku);

        if ($partNumber === '') {
            return ['created' => false, 'linked' => false, 'item' => null];
        }

        $currentItem = $product->source_part_catalog_item_id
            ? PartCatalogItem::query()->find($product->source_part_catalog_item_id)
            : null;

        if ($currentItem !== null
            && $currentItem->source === self::SOURCE
            && ! $this->isVinSpecificOfficialItem($currentItem)
            && $this->compactPartNumber((string) $currentItem->part_number) === $this->compactPartNumber($partNumber)) {
            return ['created' => false, 'linked' => false, 'item' => $currentItem];
        }

        $item = $this->existingItem($partNumber);
        $created = false;

        if ($item === null) {
            $item = $this->createItem($product, $partNumber);
            $created = true;
        }

        $linked = (int) $product->source_part_catalog_item_id !== (int) $item->id
            && ! $this->donorAlreadyLinkedToItem($product, $item);

        if ($linked) {
            $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();
        }

        return ['created' => $created, 'linked' => $linked, 'item' => $item];
    }

    protected function donorAlreadyLinkedToItem(Product $product, PartCatalogItem $item): bool
    {
        if ($product->donor_car_id === null) {
            return false;
        }

        return Product::query()
            ->where('donor_car_id', $product->donor_car_id)
            ->where('source_part_catalog_item_id', $item->id)
            ->whereKeyNot($product->id)
            ->exists();
    }

    protected function existingItem(string $partNumber): ?PartCatalogItem
    {
        $normalized = $this->compactPartNumber($partNumber);

        return PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->whereNotNull('part_number')
            ->whereRaw('upper(replace(replace(trim(part_number), ?, ?), ?, ?)) = ?', ['-', '', ' ', '', $normalized])
            ->where('source_url', 'not like', '%vin=%')
            ->orderByRaw("case when source_url like 'https://parts.tesla.com/%' then 0 when source_url like 'tesla-common://donor-product/%' then 1 else 2 end")
            ->orderBy('id')
            ->first();
    }

    protected function isVinSpecificOfficialItem(PartCatalogItem $item): bool
    {
        return str_contains(Str::lower((string) $item->source_url), 'vin=');
    }

    protected function createItem(Product $product, string $partNumber): PartCatalogItem
    {
        $category = $this->categoryForProduct($product);
        $trail = $category ? $this->categoryTrail($category) : collect();
        $mainCategory = $trail->firstWhere('depth', 1);
        $subcategory = $trail->firstWhere('depth', 2);
        $name = trim((string) $product->name) ?: $partNumber;

        return PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category?->id,
            'source' => self::SOURCE,
            'source_url' => 'tesla-common://donor-product/'.$this->compactPartNumber($partNumber),
            'part_number' => $partNumber,
            'name' => $name,
            'name_en' => $name,
            'price_amount' => $product->selling_price,
            'currency' => $product->currency ?: 'USD',
            'model_label' => $product->generation ?: $product->model,
            'model_name' => $product->model,
            'main_category_code' => $mainCategory?->code,
            'main_category_name' => $mainCategory?->name ?? $category?->name,
            'subcategory_code' => $subcategory?->code,
            'subcategory_name' => $subcategory?->name,
            'node_name' => $category?->name,
            'compatibility_text' => $product->compatibility,
            'notes_en' => $product->description,
            'condition' => $product->condition_type,
            'availability' => $product->storage_status,
            'raw_attributes' => array_filter([
                'common_catalog_origin' => 'donor_product',
                'donor_product_id' => $product->id,
                'donor_car_id' => $product->donor_car_id,
                'donor_vin' => $product->donorCar?->vin,
                'product_sku' => $product->sku,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'source_updated_at' => now(),
        ]);
    }

    protected function categoryForProduct(Product $product): ?PartCatalogCategory
    {
        $modelLabel = trim((string) ($product->generation ?: $product->model));
        $modelName = trim((string) $product->model);

        $root = PartCatalogCategory::query()
            ->where('source', self::SOURCE)
            ->whereNull('parent_id')
            ->where('depth', 0)
            ->when($modelLabel !== '', fn ($query) => $query->where('model_label', $modelLabel))
            ->when($modelLabel === '' && $modelName !== '', fn ($query) => $query->where('model_name', $modelName))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($root === null) {
            $label = $modelLabel ?: $modelName ?: 'Donor Tesla';
            $root = PartCatalogCategory::query()->firstOrCreate(
                ['source_url' => 'tesla-common://donor-products/model/'.md5(Str::lower($label))],
                [
                    'source' => self::SOURCE,
                    'parent_id' => null,
                    'depth' => 0,
                    'name' => $label,
                    'name_en' => $label,
                    'model_label' => $modelLabel ?: $label,
                    'model_name' => $modelName ?: null,
                    'sort_order' => 9999,
                    'children_scanned_at' => now(),
                ],
            );
        }

        return PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'tesla-common://donor-products/category/'.md5((string) $root->id)],
            [
                'source' => self::SOURCE,
                'parent_id' => $root->id,
                'depth' => ((int) $root->depth) + 1,
                'name' => 'Donor imports',
                'name_en' => 'Donor imports',
                'model_label' => $root->model_label,
                'model_name' => $root->model_name,
                'sort_order' => 9999,
                'products_scanned_at' => now(),
            ],
        );
    }

    protected function categoryTrail(PartCatalogCategory $category): Collection
    {
        $trail = collect();
        $current = $category;

        while ($current !== null) {
            $trail->prepend($current);
            $current = $current->parent_id ? PartCatalogCategory::query()->find($current->parent_id) : null;
        }

        return $trail->values();
    }

    protected function normalizePartNumber(?string $value): string
    {
        $value = Str::upper(trim((string) $value));

        if (preg_match('/\b(\d{7})[-\s]?([A-Z0-9]{2})[-\s]?([A-Z0-9])\b/', $value, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $value;
    }

    protected function compactPartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }
}
