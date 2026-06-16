<?php

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsOfficialPartMatcher;
use App\Services\NikolaCarsTeslaCategoryResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;

return new class extends Migration
{
    private NikolaCarsInventoryService $inventory;

    private NikolaCarsOfficialPartMatcher $matcher;

    private NikolaCarsTeslaCategoryResolver $resolver;

    public function up(): void
    {
        $this->inventory = app(NikolaCarsInventoryService::class);
        $this->matcher = app(NikolaCarsOfficialPartMatcher::class);
        $this->resolver = app(NikolaCarsTeslaCategoryResolver::class);

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
            ->with([
                'category:id,name',
                'sourcePartCatalogItem.category.parent.parent.parent.parent',
                'sourcePartCatalogItem.occurrences.category.parent.parent.parent.parent',
            ])
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
                    $isValidTeslaPart = $this->inventory->isTeslaPartNumberShape($partNumber);
                    $currentCategory = $this->currentProductCategory($product);

                    if (! $this->shouldRepairCategory($currentCategory, $isValidTeslaPart)) {
                        continue;
                    }

                    if ($sourceItem instanceof PartCatalogItem && $sourceItem->source === 'nikolacars') {
                        $this->resolver->resolveItem($sourceItem);

                        continue;
                    }

                    if (! $isValidTeslaPart) {
                        $product->forceFill(['category_id' => null])->save();
                    }
                }
            });
    }

    private function repairSoldParts(): void
    {
        PartSale::query()
            ->with([
                'partCatalogItem.category.parent.parent.parent.parent',
                'partCatalogItem.occurrences.category.parent.parent.parent.parent',
            ])
            ->whereNotNull('donor_car_id')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales): void {
                foreach ($sales as $sale) {
                    $item = $sale->partCatalogItem;
                    $partNumber = $this->normalizedPartNumber(
                        $sale->part_number ?: $item?->part_number ?: $sale->code
                    );
                    $isValidTeslaPart = $this->inventory->isTeslaPartNumberShape($partNumber);
                    $itemCategory = $this->currentItemCategory($item);
                    $saleCategory = trim((string) $sale->category_path);
                    $currentCategory = $itemCategory !== '' ? $itemCategory : $saleCategory;
                    $salePathNeedsCleanup = $saleCategory !== '' && $this->isBadCategory($saleCategory);

                    if (! $this->shouldRepairCategory($currentCategory, $isValidTeslaPart) && ! $salePathNeedsCleanup) {
                        continue;
                    }

                    if ($item instanceof PartCatalogItem && $item->source === 'nikolacars') {
                        if (! $isValidTeslaPart || $this->isBadCategory($itemCategory)) {
                            $this->resolver->resolveItem($item);
                            $item = $item->fresh(['category.parent.parent.parent.parent', 'occurrences.category.parent.parent.parent.parent']);
                        }

                        $category = $this->currentItemCategory($item) ?: NikolaCarsTeslaCategoryResolver::UNDETERMINED;
                    } else {
                        $category = $isValidTeslaPart
                            ? $this->officialCategoryLabel($partNumber)
                            : NikolaCarsTeslaCategoryResolver::UNDETERMINED;
                    }

                    if (trim((string) $sale->category_path) !== $category) {
                        $sale->forceFill(['category_path' => $category])->save();
                    }
                }
            });
    }

    private function normalizedPartNumber(?string $value): string
    {
        return $this->inventory->normalizePartNumber(trim((string) $value));
    }

    private function shouldRepairCategory(string $category, bool $isValidTeslaPart): bool
    {
        if (! $isValidTeslaPart) {
            return ! $this->isUndetermined($category);
        }

        return $this->isBadCategory($category);
    }

    private function isBadCategory(?string $category): bool
    {
        $category = trim((string) $category);

        if ($category === '') {
            return true;
        }

        return $this->isUndetermined($category)
            || preg_match('/^tesla\s*;/iu', $category) === 1
            || preg_match('/[A-HJ-NPR-Z0-9]{17}/i', $category) === 1
            || preg_match('/^\d+\s*[-–—:]\s*/u', $category) === 1
            || preg_match('/\/\s*\d+\s*[-–—:]\s*/u', $category) === 1;
    }

    private function isUndetermined(?string $category): bool
    {
        return mb_strtolower(trim((string) $category), 'UTF-8') === mb_strtolower(NikolaCarsTeslaCategoryResolver::UNDETERMINED, 'UTF-8');
    }

    private function currentProductCategory(Product $product): string
    {
        return $this->currentItemCategory($product->sourcePartCatalogItem)
            ?: trim((string) $product->category?->name);
    }

    private function currentItemCategory(?PartCatalogItem $item): string
    {
        if (! $item instanceof PartCatalogItem) {
            return '';
        }

        $category = trim((string) (
            data_get($item->raw_attributes, 'category_display')
            ?: data_get($item->raw_attributes, 'category_path')
            ?: $this->catalogCategoryPath($this->catalogCategoryForItem($item))
            ?: collect([$item->main_category_name, $item->subcategory_name, $item->node_name])->filter()->implode(' / ')
        ));

        return $category !== '' ? $this->cleanCategoryPath($category) : '';
    }

    private function officialCategoryLabel(string $partNumber): string
    {
        $match = $this->matcher->match($partNumber, ['require_category_data' => true]);
        $officialItem = $match->officialItem;

        if (! $officialItem instanceof PartCatalogItem) {
            return NikolaCarsTeslaCategoryResolver::UNDETERMINED;
        }

        $category = $this->catalogCategoryPath($this->catalogCategoryForItem($officialItem));

        if ($category !== '') {
            return $category;
        }

        $category = collect([
            $officialItem->main_category_name,
            $officialItem->subcategory_name,
            $officialItem->node_name,
        ])
            ->map(fn ($value): string => $this->cleanCategoryPart((string) $value))
            ->filter()
            ->unique()
            ->implode(' / ');

        return $category !== '' ? $category : NikolaCarsTeslaCategoryResolver::UNDETERMINED;
    }

    private function catalogCategoryForItem(PartCatalogItem $item): ?PartCatalogCategory
    {
        return $item->category instanceof PartCatalogCategory
            ? $item->category
            : $item->occurrences->pluck('category')->filter()->first();
    }

    private function catalogCategoryPath(?PartCatalogCategory $category): string
    {
        if (! $category instanceof PartCatalogCategory) {
            return '';
        }

        $trail = collect();
        $current = $category;

        while ($current instanceof PartCatalogCategory) {
            $trail->prepend($current);
            $current = $current->parent;
        }

        return $trail
            ->filter(fn (PartCatalogCategory $trailCategory): bool => (int) $trailCategory->depth > 0)
            ->map(fn (PartCatalogCategory $trailCategory): string => $this->cleanCategoryPart((string) (
                $trailCategory->name_ru
                ?: $trailCategory->name_ua
                ?: $trailCategory->name_en
                ?: $trailCategory->name
            )))
            ->filter()
            ->unique()
            ->implode(' / ');
    }

    private function cleanCategoryPath(string $category): string
    {
        return collect(preg_split('/\s*(?:\/|>|\\\\)\s*/u', $category) ?: [])
            ->map(fn (string $part): string => $this->cleanCategoryPart($part))
            ->filter()
            ->unique()
            ->implode(' / ');
    }

    private function cleanCategoryPart(string $part): string
    {
        $part = $this->inventory->withoutTeslaCategoryCode(trim($part));

        if ($part === '') {
            return '';
        }

        $part = trim((string) preg_replace('/^\d+\s*[-–—:.]\s*/u', '', $part));

        if ($part === '') {
            return '';
        }

        $uppercaseCount = preg_match_all('/\p{Lu}/u', $part);
        $hasLowercase = preg_match('/\p{Ll}/u', $part) === 1;

        if (! $hasLowercase || $uppercaseCount > 1) {
            $part = mb_strtolower($part, 'UTF-8');
        }

        return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($part, 1, null, 'UTF-8');
    }
};
