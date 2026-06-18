<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NikolaCarsTeslaCategoryResolver
{
    public const UNDETERMINED = "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}";

    protected const UNDETERMINED_ALIASES = [
        self::UNDETERMINED,
    ];

    public function resolveAll(array $options = []): array
    {
        $missingOnly = (bool) ($options['missing_only'] ?? false);
        $syncProducts = (bool) ($options['sync_products'] ?? false);
        $stats = ['items_seen' => 0, 'items_skipped' => 0, 'items_updated' => 0, 'items_matched' => 0, 'items_undetermined' => 0];

        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->orderBy('id')
            ->chunkById(300, function (Collection $items) use (&$stats, $missingOnly, $syncProducts): void {
                foreach ($items as $item) {
                    $stats['items_seen']++;

                    if ($missingOnly && ! $this->hasUndeterminedCategory($item)) {
                        $stats['items_skipped']++;

                        continue;
                    }

                    $result = $this->resolveItem($item);
                    $stats['items_updated'] += $result['updated'] ? 1 : 0;
                    $stats['items_matched'] += $result['category'] !== self::UNDETERMINED ? 1 : 0;
                    $stats['items_undetermined'] += $result['category'] === self::UNDETERMINED ? 1 : 0;

                    if ($syncProducts && $result['updated']) {
                        app(NikolaCarsCatalogProductSyncService::class)->syncItem($item->fresh());
                    }
                }
            });

        return $stats;
    }

    public function resolveItem(PartCatalogItem $item): array
    {
        if ($item->source !== 'nikolacars') {
            return ['updated' => false, 'category' => null];
        }

        if ($this->hasManualCategory($item)) {
            return [
                'updated' => false,
                'category' => $this->currentCategoryLabel($item),
            ];
        }

        $officialMatch = app(NikolaCarsOfficialPartMatcher::class)->match($item, [
            'require_category_data' => true,
        ]);
        $officialItem = $officialMatch->officialItem;
        $categoryParts = $officialItem ? $this->categoryParts($officialItem) : collect();
        $categoryLabel = $categoryParts->isNotEmpty()
            ? $categoryParts->implode(' / ')
            : self::UNDETERMINED;
        $category = $this->nikolaCarsCategory($categoryLabel, $officialItem);
        $rawAttributes = PartCatalogRawAttributes::from($item);

        unset($rawAttributes['category_display'], $rawAttributes['category_path']);
        $rawAttributes['tesla_category_match'] = array_filter([
            'status' => $officialItem ? 'matched' : 'not_found',
            'match_type' => $officialMatch->matchType,
            'part_prefix' => $officialMatch->partPrefix,
            'part_number' => $officialMatch->normalizedPartNumber ?: null,
            'official_item_id' => $officialItem?->id,
            'official_part_number' => $officialItem?->part_number,
            'category' => $categoryLabel,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $payload = [
            'part_catalog_category_id' => $category->id,
            'model_label' => $officialItem?->model_label ?: $item->model_label,
            'model_name' => $officialItem?->model_name ?: $item->model_name,
            'year_from' => $officialItem?->year_from ?: $item->year_from,
            'year_to' => $officialItem?->year_to ?: $item->year_to,
            'main_category_name' => $categoryParts->get(0) ?: self::UNDETERMINED,
            'subcategory_name' => $categoryParts->get(1),
            'node_name' => $categoryParts->get(2) ?: ($categoryParts->count() === 1 ? $categoryParts->get(0) : null),
            'raw_attributes' => $rawAttributes,
        ];

        $item->forceFill($payload)->save();

        return ['updated' => true, 'category' => $categoryLabel];
    }

    protected function categoryParts(PartCatalogItem $officialItem): Collection
    {
        $categoryParts = $this->categoryPartsFromCategory($officialItem);
        $parts = collect([
            $officialItem->main_category_name,
            $officialItem->subcategory_name,
            $officialItem->node_name,
        ])
            ->map(fn (?string $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $categoryParts->count() > 1 || $parts->isEmpty()
            ? $categoryParts
            : $parts;
    }

    protected function categoryPartsFromCategory(PartCatalogItem $officialItem): Collection
    {
        $category = $officialItem->category
            ?: $officialItem->occurrences->pluck('category')->filter()->first();

        if (! $category) {
            return collect();
        }

        $trail = $this->categoryTrail($category);
        $withoutModelRoot = $trail
            ->filter(fn (PartCatalogCategory $trailCategory): bool => (int) $trailCategory->depth > 0)
            ->values();

        return ($withoutModelRoot->isNotEmpty() ? $withoutModelRoot : $trail)
            ->map(fn (PartCatalogCategory $trailCategory): string => $this->categoryLabel($trailCategory))
            ->filter()
            ->unique()
            ->values();
    }

    protected function categoryTrail(PartCatalogCategory $category): Collection
    {
        $trail = collect();
        $current = $category;

        while ($current) {
            $trail->prepend($current);
            $current = $current->parent;
        }

        return $trail->values();
    }

    protected function categoryLabel(PartCatalogCategory $category): string
    {
        $label = app(NikolaCarsInventoryService::class)->withoutTeslaCategoryCode((string) (
            $category->name_ru
            ?: $category->name_ua
            ?: $category->name_en
            ?: $category->name
        ));

        if ($label === '') {
            return '';
        }

        $uppercaseCount = preg_match_all('/\p{Lu}/u', $label);
        $hasLowercase = preg_match('/\p{Ll}/u', $label) === 1;

        if (! $hasLowercase || $uppercaseCount > 1) {
            $label = mb_strtolower($label, 'UTF-8');
        }

        return mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($label, 1, null, 'UTF-8');
    }

    protected function nikolaCarsCategory(string $categoryLabel, ?PartCatalogItem $officialItem = null): PartCatalogCategory
    {
        $teslaCategory = $officialItem?->category
            ?: $officialItem?->occurrences->pluck('category')->filter()->first();

        if ($teslaCategory instanceof PartCatalogCategory) {
            return app(NikolaCarsTeslaCategoryTreeSyncService::class)->mirrorCategory($teslaCategory);
        }

        return PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://tesla-category/'.md5(Str::lower($categoryLabel))],
            [
                'source' => 'nikolacars',
                'parent_id' => null,
                'depth' => 0,
                'name' => $categoryLabel,
                'name_ru' => $categoryLabel,
                'name_ua' => $categoryLabel,
                'model_label' => $categoryLabel,
                'sort_order' => $categoryLabel === self::UNDETERMINED ? 9999 : 0,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function hasUndeterminedCategory(PartCatalogItem $item): bool
    {
        $labels = collect([
            data_get($item->raw_attributes, 'category_display'),
            data_get($item->raw_attributes, 'category_path'),
            $item->main_category_name,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter();

        if ($labels->isEmpty()) {
            return true;
        }

        return $labels->contains(fn (string $label): bool => in_array($label, $this->undeterminedAliases(), true));
    }

    protected function hasManualCategory(PartCatalogItem $item): bool
    {
        return (bool) data_get($item->raw_attributes, 'manual_category')
            && (int) $item->part_catalog_category_id > 0;
    }

    protected function currentCategoryLabel(PartCatalogItem $item): ?string
    {
        $label = trim((string) (
            data_get($item->raw_attributes, 'category_display')
            ?: data_get($item->raw_attributes, 'category_path')
            ?: $item->main_category_name
        ));

        return $label !== '' ? $label : null;
    }

    protected function undeterminedAliases(): array
    {
        $windows1251Mojibake = mb_convert_encoding(self::UNDETERMINED, 'UTF-8', 'Windows-1251');

        return array_values(array_unique([
            ...self::UNDETERMINED_ALIASES,
            $windows1251Mojibake,
            mb_convert_encoding($windows1251Mojibake, 'UTF-8', 'Windows-1251'),
        ]));
    }
}
