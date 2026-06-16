<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use Illuminate\Support\Collection;

class NikolaCarsTeslaCategoryTreeSyncService
{
    public const SOURCE_URL_PREFIX = 'nikolacars://tesla-category/';

    public function syncAll(): array
    {
        $stats = [
            'tesla_categories_seen' => 0,
            'nikolacars_categories_created' => 0,
            'nikolacars_categories_updated' => 0,
            'nikolacars_categories_unchanged' => 0,
        ];

        PartCatalogCategory::query()
            ->where('source', 'tesla_official')
            ->orderBy('depth')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunk(300, function (Collection $categories) use (&$stats): void {
                foreach ($categories as $category) {
                    $stats['tesla_categories_seen']++;
                    $result = $this->syncCategory($category);

                    match ($result) {
                        'created' => $stats['nikolacars_categories_created']++,
                        'updated' => $stats['nikolacars_categories_updated']++,
                        default => $stats['nikolacars_categories_unchanged']++,
                    };
                }
            });

        return $stats;
    }

    public function syncCategory(PartCatalogCategory $teslaCategory): string
    {
        if ($teslaCategory->source !== 'tesla_official') {
            return 'skipped';
        }

        $parentId = null;
        if ($teslaCategory->parent_id) {
            $parent = PartCatalogCategory::query()->find($teslaCategory->parent_id);
            $parentId = $parent instanceof PartCatalogCategory
                ? $this->mirrorCategory($parent)->id
                : null;
        }

        $payload = [
            'source' => 'nikolacars',
            'parent_id' => $parentId,
            'preview_image_url' => $teslaCategory->preview_image_url,
            'depth' => $teslaCategory->depth,
            'code' => $teslaCategory->code,
            'name' => $teslaCategory->name,
            'name_en' => $teslaCategory->name_en,
            'name_ru' => $teslaCategory->name_ru,
            'name_ua' => $teslaCategory->name_ua,
            'model_label' => $teslaCategory->model_label,
            'model_name' => $teslaCategory->model_name,
            'year_from' => $teslaCategory->year_from,
            'year_to' => $teslaCategory->year_to,
            'sort_order' => $teslaCategory->sort_order,
            'children_scanned_at' => $teslaCategory->children_scanned_at,
            'products_scanned_at' => $teslaCategory->products_scanned_at,
        ];

        $mirror = PartCatalogCategory::query()->firstOrNew([
            'source_url' => $this->mirrorSourceUrl($teslaCategory),
        ]);
        $exists = $mirror->exists;
        $mirror->fill($payload);

        if (! $mirror->isDirty()) {
            return 'unchanged';
        }

        $mirror->save();

        return $exists ? 'updated' : 'created';
    }

    public function mirrorCategory(PartCatalogCategory $teslaCategory): PartCatalogCategory
    {
        $this->syncCategory($teslaCategory);

        return PartCatalogCategory::query()
            ->where('source_url', $this->mirrorSourceUrl($teslaCategory))
            ->firstOrFail();
    }

    public function mirrorSourceUrl(PartCatalogCategory $teslaCategory): string
    {
        return self::SOURCE_URL_PREFIX.$teslaCategory->id;
    }
}
