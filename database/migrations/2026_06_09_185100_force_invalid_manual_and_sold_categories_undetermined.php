<?php

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsTeslaCategoryResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;

return new class extends Migration
{
    private NikolaCarsInventoryService $inventory;

    private PartCatalogCategory $undeterminedCategory;

    public function up(): void
    {
        $this->inventory = app(NikolaCarsInventoryService::class);
        $this->undeterminedCategory = $this->undeterminedCategory();

        $this->repairManualProducts();
        $this->repairSoldParts();
    }

    public function down(): void
    {
        //
    }

    private function repairManualProducts(): void
    {
        Product::query()
            ->with('sourcePartCatalogItem')
            ->whereNotNull('donor_car_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('source_part_catalog_item_id')
                    ->orWhereHas('sourcePartCatalogItem', fn ($item) => $item->where('source', '!=', 'tesla_official'));
            })
            ->orderBy('id')
            ->chunkById(200, function (Collection $products): void {
                foreach ($products as $product) {
                    $sourceItem = $product->sourcePartCatalogItem;
                    $partNumber = $this->normalizedPartNumber(
                        $product->external_sku ?: $sourceItem?->part_number ?: $product->sku
                    );

                    if ($this->inventory->isTeslaPartNumberShape($partNumber)) {
                        continue;
                    }

                    if ($sourceItem instanceof PartCatalogItem && $sourceItem->source === 'nikolacars') {
                        $this->markItemUndetermined($sourceItem, $partNumber);
                    }

                    $product->forceFill(['category_id' => null])->save();
                }
            });
    }

    private function repairSoldParts(): void
    {
        PartSale::query()
            ->with('partCatalogItem')
            ->whereNotNull('donor_car_id')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales): void {
                foreach ($sales as $sale) {
                    $item = $sale->partCatalogItem;
                    $partNumber = $this->normalizedPartNumber(
                        $sale->part_number ?: $item?->part_number ?: $sale->code
                    );

                    if ($this->inventory->isTeslaPartNumberShape($partNumber)) {
                        continue;
                    }

                    if ($item instanceof PartCatalogItem && $item->source === 'nikolacars') {
                        $this->markItemUndetermined($item, $partNumber);
                    }

                    if (trim((string) $sale->category_path) !== NikolaCarsTeslaCategoryResolver::UNDETERMINED) {
                        $sale->forceFill([
                            'category_path' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
                        ])->save();
                    }
                }
            });
    }

    private function markItemUndetermined(PartCatalogItem $item, string $partNumber): void
    {
        $rawAttributes = $item->raw_attributes instanceof ArrayObject
            ? $item->raw_attributes->getArrayCopy()
            : (array) $item->raw_attributes;

        $rawAttributes['category_display'] = NikolaCarsTeslaCategoryResolver::UNDETERMINED;
        $rawAttributes['category_path'] = NikolaCarsTeslaCategoryResolver::UNDETERMINED;
        $rawAttributes['tesla_category_match'] = array_filter([
            'status' => 'invalid_part_number',
            'match_type' => 'none',
            'part_number' => $partNumber,
            'category' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $item->forceFill([
            'part_catalog_category_id' => $this->undeterminedCategory->id,
            'main_category_name' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
            'subcategory_name' => null,
            'node_name' => null,
            'raw_attributes' => $rawAttributes,
        ])->save();
    }

    private function normalizedPartNumber(?string $value): string
    {
        return $this->inventory->normalizePartNumber(trim((string) $value));
    }

    private function undeterminedCategory(): PartCatalogCategory
    {
        return PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://tesla-category/'.md5(mb_strtolower(NikolaCarsTeslaCategoryResolver::UNDETERMINED, 'UTF-8'))],
            [
                'source' => 'nikolacars',
                'parent_id' => null,
                'depth' => 0,
                'name' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
                'name_ru' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
                'name_ua' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
                'model_label' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
                'sort_order' => 9999,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );
    }
};
