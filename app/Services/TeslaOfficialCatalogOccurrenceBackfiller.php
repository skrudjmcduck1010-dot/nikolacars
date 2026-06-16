<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeslaOfficialCatalogOccurrenceBackfiller
{
    protected string $source = 'tesla_official';

    public function backfill(array $options = []): array
    {
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $canonicalizeItems = (bool) ($options['canonicalize_items'] ?? false);
        $missingOnly = (bool) ($options['missing_only'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'items_scanned' => 0,
            'occurrences_seen' => 0,
            'occurrences_saved' => 0,
            'categories_saved' => 0,
            'items_canonicalized' => 0,
            'legacy_category_items_canonicalized' => 0,
            'items_skipped' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('raw_attributes', 'like', '%official_catalog_occurrences%')
            ->when($missingOnly, fn ($query) => $query->whereDoesntHave('occurrences', fn ($occurrenceQuery) => $occurrenceQuery->where('source', $this->source)))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (PartCatalogItem $item) use ($canonicalizeItems, $progress, &$stats): void {
            $stats['items_scanned']++;
            $raw = $this->rawAttributes($item);
            $occurrences = collect((array) ($raw['official_catalog_occurrences'] ?? []))
                ->filter(fn (mixed $occurrence): bool => is_array($occurrence))
                ->values();

            if ($occurrences->isEmpty()) {
                $stats['items_skipped']++;

                return;
            }

            $canonicalCategories = collect();

            foreach ($occurrences as $occurrence) {
                $stats['occurrences_seen']++;
                $category = $this->canonicalCategoryForOccurrence($occurrence);

                if (! $category instanceof PartCatalogCategory) {
                    continue;
                }

                $canonicalCategories->push($category);
                $stats['categories_saved']++;

                $this->saveOccurrence($item, $category, $occurrence);
                $stats['occurrences_saved']++;
            }

            if ($canonicalizeItems && $canonicalCategories->isNotEmpty() && $this->shouldReplaceItemCategory($item)) {
                $preferredCategory = $this->preferredCategoryForItem($canonicalCategories, $occurrences);

                if ($preferredCategory instanceof PartCatalogCategory) {
                    $item->forceFill(['part_catalog_category_id' => $preferredCategory->id])->save();
                    $stats['items_canonicalized']++;
                }
            }

            if (is_callable($progress) && $stats['items_scanned'] % 250 === 0) {
                $progress("Backfilled {$stats['items_scanned']} Tesla official items.");
            }
        });

        if ((bool) ($options['canonicalize_legacy_categories'] ?? false)) {
            $stats['legacy_category_items_canonicalized'] = $this->canonicalizeLegacyItemCategories($limit, $progress);
        }

        return $stats;
    }

    public function canonicalizeLegacyItemCategories(int $limit = 0, ?callable $progress = null): int
    {
        $updated = 0;
        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereHas('category', fn ($categoryQuery) => $categoryQuery
                ->where('source', $this->source)
                ->where('source_url', 'like', 'https://parts.tesla.com/%'))
            ->with('category')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (PartCatalogItem $item) use (&$updated, $progress): void {
            $category = $this->canonicalCategoryForItem($item);

            if (! $category instanceof PartCatalogCategory) {
                return;
            }

            $item->forceFill(array_filter([
                'part_catalog_category_id' => $category->id,
                'model_label' => $category->model_label ?: $item->model_label,
                'model_name' => $category->model_name ?: $item->model_name,
                'year_from' => $category->year_from ?: $item->year_from,
                'year_to' => $category->year_to ?: $item->year_to,
                'main_category_code' => $this->ancestor($category, 1)?->code ?: $item->main_category_code,
                'main_category_name' => $this->ancestor($category, 1)?->name ?: $item->main_category_name,
                'subcategory_code' => $this->ancestor($category, 2)?->code ?: $item->subcategory_code,
                'subcategory_name' => $this->ancestor($category, 2)?->name ?: $item->subcategory_name,
                'node_name' => $category->name ?: $item->node_name,
            ], fn ($value) => $value !== null && $value !== ''))->save();
            $updated++;

            if (is_callable($progress) && $updated % 500 === 0) {
                $progress("Canonicalized {$updated} legacy Tesla item categories.");
            }
        });

        return $updated;
    }

    protected function canonicalCategoryForOccurrence(array $occurrence): ?PartCatalogCategory
    {
        $modelLabel = trim((string) ($occurrence['model_label'] ?? $occurrence['model_name'] ?? ''));
        $mainCode = trim((string) ($occurrence['main_category_code'] ?? ''));
        $mainName = trim((string) ($occurrence['main_category_name'] ?? ''));
        $subcategoryCode = trim((string) ($occurrence['subcategory_code'] ?? ''));
        $subcategoryName = trim((string) ($occurrence['subcategory_name'] ?? ''));
        $nodeName = trim((string) ($occurrence['node_name'] ?? ''));

        if ($modelLabel === '' || $mainName === '' || $subcategoryName === '' || $nodeName === '') {
            return null;
        }

        $oldCategory = isset($occurrence['category_id'])
            ? PartCatalogCategory::query()->find((int) $occurrence['category_id'])
            : null;
        $modelMeta = [
            'name' => $modelLabel,
            'model_name' => $this->modelNameFromLabel($modelLabel),
            'year_from' => $oldCategory?->year_from,
            'year_to' => $oldCategory?->year_to,
        ];

        $modelCategory = $this->saveCategory($this->modelUrl($modelLabel), [
            'source' => $this->source,
            'parent_id' => null,
            'depth' => 0,
            'code' => null,
            'name' => $modelLabel,
            'name_en' => $modelLabel,
            'model_label' => $modelLabel,
            'model_name' => $modelMeta['model_name'],
            'year_from' => $modelMeta['year_from'],
            'year_to' => $modelMeta['year_to'],
            'preview_image_url' => $this->closestPreview($oldCategory, 0),
        ]);

        $mainCategory = $this->saveCategory($this->mainUrl($modelLabel, $mainCode, $mainName), [
            'source' => $this->source,
            'parent_id' => $modelCategory->id,
            'depth' => 1,
            'code' => $mainCode ?: null,
            'name' => $mainName,
            'name_en' => $mainName,
            'model_label' => $modelLabel,
            'model_name' => $modelMeta['model_name'],
            'year_from' => $modelMeta['year_from'],
            'year_to' => $modelMeta['year_to'],
            'preview_image_url' => $this->closestPreview($oldCategory, 1),
        ]);

        $subcategory = $this->saveCategory($this->subcategoryUrl($modelLabel, $mainCode, $mainName, $subcategoryCode, $subcategoryName), [
            'source' => $this->source,
            'parent_id' => $mainCategory->id,
            'depth' => 2,
            'code' => $subcategoryCode ?: null,
            'name' => $subcategoryName,
            'name_en' => $subcategoryName,
            'model_label' => $modelLabel,
            'model_name' => $modelMeta['model_name'],
            'year_from' => $modelMeta['year_from'],
            'year_to' => $modelMeta['year_to'],
            'preview_image_url' => $this->closestPreview($oldCategory, 2),
        ]);

        return $this->saveCategory($this->systemGroupUrl($modelLabel, $mainCode, $mainName, $subcategoryCode, $subcategoryName, $nodeName), [
            'source' => $this->source,
            'parent_id' => $subcategory->id,
            'depth' => 3,
            'code' => null,
            'name' => $nodeName,
            'name_en' => $nodeName,
            'model_label' => $modelLabel,
            'model_name' => $modelMeta['model_name'],
            'year_from' => $modelMeta['year_from'],
            'year_to' => $modelMeta['year_to'],
            'preview_image_url' => $oldCategory?->preview_image_url,
        ]);
    }

    protected function canonicalCategoryForItem(PartCatalogItem $item): ?PartCatalogCategory
    {
        $modelLabel = trim((string) ($item->model_label ?: $item->category?->model_label));
        $mainCode = trim((string) $item->main_category_code);
        $mainName = trim((string) $item->main_category_name);
        $subcategoryCode = trim((string) $item->subcategory_code);
        $subcategoryName = trim((string) $item->subcategory_name);
        $nodeName = trim((string) ($item->node_name ?: $item->category?->name));

        if ($modelLabel === '' || $mainName === '') {
            return null;
        }

        if ($subcategoryName === '' && (int) ($item->category?->depth ?? 0) === 1) {
            $modelCategory = $this->saveCategory($this->modelUrl($modelLabel), [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $modelLabel,
                'name_en' => $modelLabel,
                'model_label' => $modelLabel,
                'model_name' => $item->model_name ?: $this->modelNameFromLabel($modelLabel),
                'year_from' => $item->year_from ?: $item->category?->year_from,
                'year_to' => $item->year_to ?: $item->category?->year_to,
            ]);

            return $this->saveCategory($this->mainUrl($modelLabel, $mainCode, $mainName), [
                'source' => $this->source,
                'parent_id' => $modelCategory->id,
                'depth' => 1,
                'code' => $mainCode ?: null,
                'name' => $mainName,
                'name_en' => $mainName,
                'model_label' => $modelLabel,
                'model_name' => $item->model_name ?: $this->modelNameFromLabel($modelLabel),
                'year_from' => $item->year_from ?: $item->category?->year_from,
                'year_to' => $item->year_to ?: $item->category?->year_to,
                'preview_image_url' => $item->category?->preview_image_url,
            ]);
        }

        if ($subcategoryName === '' || $nodeName === '') {
            return null;
        }

        return $this->canonicalCategoryForOccurrence([
            'model_label' => $modelLabel,
            'model_name' => $item->model_name ?: $this->modelNameFromLabel($modelLabel),
            'main_category_code' => $mainCode,
            'main_category_name' => $mainName,
            'subcategory_code' => $subcategoryCode,
            'subcategory_name' => $subcategoryName,
            'node_name' => $nodeName,
            'category_id' => $item->part_catalog_category_id,
        ]);
    }

    protected function saveOccurrence(PartCatalogItem $item, PartCatalogCategory $category, array $occurrence): void
    {
        $occurrenceKey = hash('sha256', collect([
            $this->source,
            $item->id,
            $category->id,
            $occurrence['catalog_external_reference'] ?? null,
            $occurrence['system_group_external_reference'] ?? null,
            $occurrence['donor_vin'] ?? null,
        ])->map(fn (mixed $value): string => trim((string) $value))->implode('|'));

        PartCatalogItemOccurrence::query()->updateOrCreate(
            ['occurrence_key' => $occurrenceKey],
            [
                'part_catalog_item_id' => $item->id,
                'part_catalog_category_id' => $category->id,
                'source' => $this->source,
                'page_url' => $this->teslaSystemGroupUrl($occurrence),
                'product_url' => $item->source_url,
                'part_number' => $item->part_number,
                'name' => $item->name,
                'scheme_number' => $item->scheme_number,
                'quantity' => data_get($item->raw_attributes, 'quantity'),
                'raw_attributes' => array_filter($occurrence, fn ($value) => $value !== null && $value !== ''),
            ]
        );
    }

    protected function saveCategory(string $sourceUrl, array $attributes): PartCatalogCategory
    {
        $category = PartCatalogCategory::query()->firstOrNew(['source_url' => $sourceUrl]);

        if ($category->exists && trim((string) $category->preview_image_url) !== '') {
            unset($attributes['preview_image_url']);
        } elseif (($attributes['preview_image_url'] ?? null) === null || ($attributes['preview_image_url'] ?? '') === '') {
            unset($attributes['preview_image_url']);
        }

        $category->fill($attributes);
        $category->source_url = $sourceUrl;
        $category->save();

        return $category;
    }

    protected function preferredCategoryForItem(Collection $categories, Collection $occurrences): ?PartCatalogCategory
    {
        $donorIndex = $occurrences->search(fn (array $occurrence): bool => trim((string) ($occurrence['donor_vin'] ?? '')) !== '');

        return $donorIndex !== false
            ? $categories->values()->get($donorIndex)
            : $categories->first();
    }

    protected function shouldReplaceItemCategory(PartCatalogItem $item): bool
    {
        $category = $item->category;

        return ! $category instanceof PartCatalogCategory
            || $category->source !== $this->source
            || Str::startsWith((string) $category->source_url, 'https://parts.tesla.com/')
            || Str::contains((string) $category->source_url, ['find-part-', 'find-part-catalog']);
    }

    protected function ancestor(PartCatalogCategory $category, int $depth): ?PartCatalogCategory
    {
        while ($category instanceof PartCatalogCategory && (int) $category->depth > $depth) {
            $category = $category->parent;
        }

        return (int) $category->depth === $depth ? $category : null;
    }

    protected function closestPreview(?PartCatalogCategory $category, int $depth): ?string
    {
        while ($category instanceof PartCatalogCategory && (int) $category->depth > $depth) {
            $category = $category->parent;
        }

        return (int) ($category?->depth ?? -1) === $depth ? $category?->preview_image_url : null;
    }

    protected function teslaSystemGroupUrl(array $occurrence): ?string
    {
        $catalog = trim((string) ($occurrence['catalog_external_reference'] ?? ''));
        $systemGroup = trim((string) ($occurrence['system_group_external_reference'] ?? ''));

        return $catalog !== '' && $systemGroup !== ''
            ? "https://parts.tesla.com/en-US/catalogs?catalogExternalReference={$catalog}&systemGroupExternalReference={$systemGroup}"
            : null;
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        $raw = $item->raw_attributes;

        return $raw instanceof \ArrayObject ? $raw->getArrayCopy() : (array) $raw;
    }

    protected function modelNameFromLabel(string $modelLabel): string
    {
        return match (true) {
            str_contains($modelLabel, 'Model 3') => 'Model 3',
            str_contains($modelLabel, 'Model Y') => 'Model Y',
            str_contains($modelLabel, 'Model S') => 'Model S',
            str_contains($modelLabel, 'Model X') => 'Model X',
            default => 'Tesla',
        };
    }

    protected function modelUrl(string $modelLabel): string
    {
        return 'tesla-official://catalog/'.$this->segment(null, $modelLabel);
    }

    protected function mainUrl(string $modelLabel, ?string $mainCode, string $mainName): string
    {
        return $this->modelUrl($modelLabel).'/category/'.$this->segment($mainCode, $mainName);
    }

    protected function subcategoryUrl(string $modelLabel, ?string $mainCode, string $mainName, ?string $subcategoryCode, string $subcategoryName): string
    {
        return $this->mainUrl($modelLabel, $mainCode, $mainName).'/subcategory/'.$this->segment($subcategoryCode, $subcategoryName);
    }

    protected function systemGroupUrl(string $modelLabel, ?string $mainCode, string $mainName, ?string $subcategoryCode, string $subcategoryName, string $nodeName): string
    {
        return $this->subcategoryUrl($modelLabel, $mainCode, $mainName, $subcategoryCode, $subcategoryName).'/system-group/'.$this->segment(null, $nodeName);
    }

    protected function segment(?string $code, string $name): string
    {
        $label = trim(collect([$code, $name])->filter()->implode(' '));

        return Str::slug($label !== '' ? $label : 'category');
    }
}
