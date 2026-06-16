<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PartCatalogCategoryTreeService
{
    public function modelCategoryBlocks(PartCatalogCategory $modelCategory, string $source, callable $modelLabelQueryValues): Collection
    {
        $blocks = PartCatalogCategory::query()
            ->where('source', $source)
            ->where('parent_id', $modelCategory->id)
            ->withCount(['children', 'items'])
            ->with(['children' => fn (HasMany $query) => $query
                ->withCount(['children', 'items'])
                ->orderBy('sort_order')
                ->orderBy('code')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('name')
            ->get();

        $blockCategories = $blocks->flatMap(fn (PartCatalogCategory $category) => collect([$category])->merge($category->children));
        if (! $this->shouldSkipBranchItemCounts($source)) {
            $this->appendBranchItemCounts($blockCategories, $source, $modelLabelQueryValues);
        }

        $this->appendPreviewFallbacks($blockCategories);

        return $blocks;
    }

    public function appendPreviewFallbacks(Collection $categories): void
    {
        $categories->each(function (PartCatalogCategory $category): void {
            if ($category->preview_image_url || ! in_array($category->source, ['teslapartsukraine', 'tsk', 'teslahelp', 'stock-tesla', 'driveparts', 'dkparts', 'erazborka', 'toprazborka', 'teslawestparts', 'teslacompany'], true)) {
                return;
            }

            $category->setAttribute('preview_image_url', app(PartCatalogCategoryRouteService::class)->matchingTcarsCategory($category)?->preview_image_url);
        });
    }

    public function appendBranchItemCounts(Collection $categories, ?string $source = null, ?callable $modelLabelQueryValues = null): void
    {
        if ($categories->isEmpty()) {
            return;
        }

        $source = $this->catalogSourceValue($source ?? (string) $categories->first()->source);
        $categoryIds = $categories
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return;
        }

        if ($source === 'tesla_official') {
            $this->appendTeslaOfficialBranchItemCounts($categories, $categoryIds, $modelLabelQueryValues);

            return;
        }

        $placeholders = $categoryIds->map(fn (): string => '?')->implode(', ');
        $cacheKey = 'part-catalog:branch-item-counts:v7:'.$source.':'.md5($categoryIds->implode(','));
        $branchItemCounts = $this->rememberCatalogCache($cacheKey, function () use ($categoryIds, $placeholders, $source): array {
            if (in_array($source, ['tsk', 'driveparts', 'dkparts', 'stock-tesla', 'erazborka'], true)) {
                $rows = DB::select(
                    <<<SQL
                    with recursive category_tree(root_id, root_depth, id) as (
                        select id, depth, id
                        from part_catalog_categories
                        where id in ({$placeholders})
                            and source = ?

                        union all

                        select category_tree.root_id, category_tree.root_depth, child.id
                        from part_catalog_categories child
                        inner join category_tree on child.parent_id = category_tree.id
                        where child.source = ?
                            and not (? = 'dkparts' and category_tree.root_depth = 0)
                    ),
                    branch_items as (
                        select category_tree.root_id, part_catalog_items.id as item_id
                        from category_tree
                        inner join part_catalog_items
                            on part_catalog_items.part_catalog_category_id = category_tree.id
                            and part_catalog_items.source = ?

                        union

                        select category_tree.root_id, part_catalog_item_occurrences.part_catalog_item_id as item_id
                        from category_tree
                        inner join part_catalog_item_occurrences
                            on part_catalog_item_occurrences.part_catalog_category_id = category_tree.id
                            and part_catalog_item_occurrences.source = ?
                        inner join part_catalog_items
                            on part_catalog_items.id = part_catalog_item_occurrences.part_catalog_item_id
                            and part_catalog_items.source = ?
                    )
                    select root_id, count(distinct item_id) as items_count
                    from branch_items
                    group by root_id
                    SQL,
                    [
                        ...$categoryIds->all(),
                        $source,
                        $source,
                        $source,
                        $source,
                        $source,
                        $source,
                    ]
                );

                return collect($rows)
                    ->mapWithKeys(fn (object $row): array => [(int) $row->root_id => (int) $row->items_count])
                    ->all();
            }

            $rows = DB::select(
                <<<SQL
                with recursive category_tree(root_id, id) as (
                    select id, id
                    from part_catalog_categories
                    where id in ({$placeholders})
                        and source = ?

                    union all

                    select category_tree.root_id, child.id
                    from part_catalog_categories child
                    inner join category_tree on child.parent_id = category_tree.id
                    where child.source = ?
                )
                select category_tree.root_id, count(part_catalog_items.id) as items_count
                from category_tree
                left join part_catalog_items
                    on part_catalog_items.part_catalog_category_id = category_tree.id
                    and part_catalog_items.source = ?
                group by category_tree.root_id
                SQL,
                [
                    ...$categoryIds->all(),
                    $source,
                    $source,
                    $source,
                ]
            );

            return collect($rows)
                ->mapWithKeys(fn (object $row): array => [(int) $row->root_id => (int) $row->items_count])
                ->all();
        });

        $categories->each(function (PartCatalogCategory $category) use ($branchItemCounts, $modelLabelQueryValues): void {
            $category->setAttribute('branch_items_count',
                ($branchItemCounts[(int) $category->id] ?? 0)
                + $this->uncategorizedModelItemsCount($category, $modelLabelQueryValues)
            );
        });
    }

    public function whereInSelectedCatalogBranch(Builder $query, PartCatalogCategory $category, array $branchCategoryIds): void
    {
        $branchCategoryIds = $branchCategoryIds !== [] ? $branchCategoryIds : [0];

        if ($category->source === 'tesla_official') {
            $itemIds = $this->teslaOfficialBranchItemIds($branchCategoryIds);
            $query->whereIn('id', $itemIds !== [] ? $itemIds : [0]);

            return;
        }

        $query->where(function (Builder $branchBuilder) use ($category, $branchCategoryIds): void {
            $branchBuilder->whereIn('part_catalog_category_id', $branchCategoryIds);

            if ($category->source === 'driveparts') {
                $this->orWhereDrivePartsRawCategoryBranch($branchBuilder, $branchCategoryIds);
            }

            if ($category->source === 'tesla_official' && $this->shouldUseTeslaOfficialRawCategoryFallback($branchCategoryIds)) {
                $branchBuilder->orWhere(function (Builder $rawBuilder) use ($branchCategoryIds): void {
                    foreach ($branchCategoryIds as $categoryId) {
                        $rawBuilder
                            ->orWhere('raw_attributes', 'like', '%"category_id":'.$categoryId.'%')
                            ->orWhere('raw_attributes', 'like', '%"category_id": '.$categoryId.'%');
                    }
                });
            }

            if (! in_array($category->source, ['tsk', 'driveparts', 'dkparts', 'stock-tesla', 'erazborka', 'tesla_official'], true)) {
                return;
            }

            $branchBuilder->orWhereHas('occurrences', function (Builder $occurrenceBuilder) use ($category, $branchCategoryIds): void {
                $occurrenceBuilder
                    ->where('source', $category->source)
                    ->whereIn('part_catalog_category_id', $branchCategoryIds);
            });
        });
    }

    public function categoryBranchIds(PartCatalogCategory $category): array
    {
        $rows = DB::select(
            <<<'SQL'
            with recursive category_tree(id) as (
                select id
                from part_catalog_categories
                where id = ?

                union all

                select child.id
                from part_catalog_categories child
                inner join category_tree on child.parent_id = category_tree.id
                where child.source = ?
            )
            select id from category_tree
            SQL,
            [
                $category->id,
                $category->source,
            ]
        );

        return collect($rows)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function appendTeslaOfficialBranchItemCounts(Collection $categories, Collection $categoryIds, ?callable $modelLabelQueryValues): void
    {
        $cacheKey = 'part-catalog:tesla-official-branch-item-counts:v1:'.md5($categoryIds->implode(','));
        $branchItemCounts = $this->rememberCatalogCache($cacheKey, function () use ($categoryIds): array {
            $allCategories = PartCatalogCategory::query()
                ->where('source', 'tesla_official')
                ->get(['id', 'parent_id']);
            $childrenByParent = $allCategories->groupBy(fn (PartCatalogCategory $category): int => (int) ($category->parent_id ?: 0));

            $categoryToRoots = [];
            $stack = [];
            foreach ($categoryIds as $rootId) {
                $stack[] = [(int) $rootId, (int) $rootId];
            }

            while ($stack !== []) {
                [$rootId, $categoryId] = array_pop($stack);
                $categoryToRoots[$categoryId] ??= [];
                $categoryToRoots[$categoryId][$rootId] = true;

                foreach ($childrenByParent->get($categoryId, collect()) as $child) {
                    $stack[] = [$rootId, (int) $child->id];
                }
            }

            $branchCategoryIds = array_keys($categoryToRoots);
            if ($branchCategoryIds === []) {
                return [];
            }

            $itemIdsByRoot = [];
            $addItem = function (int $categoryId, int $itemId) use (&$itemIdsByRoot, $categoryToRoots): void {
                foreach (array_keys($categoryToRoots[$categoryId] ?? []) as $rootId) {
                    $itemIdsByRoot[$rootId] ??= [];
                    $itemIdsByRoot[$rootId][$itemId] = true;
                }
            };

            DB::table('part_catalog_items')
                ->where('source', 'tesla_official')
                ->whereIn('part_catalog_category_id', $branchCategoryIds)
                ->orderBy('id')
                ->select(['id', 'part_catalog_category_id'])
                ->chunk(5000, function ($rows) use ($addItem): void {
                    foreach ($rows as $row) {
                        $addItem((int) $row->part_catalog_category_id, (int) $row->id);
                    }
                });

            DB::table('part_catalog_item_occurrences')
                ->where('source', 'tesla_official')
                ->whereIn('part_catalog_category_id', $branchCategoryIds)
                ->orderBy('id')
                ->select(['id', 'part_catalog_item_id', 'part_catalog_category_id'])
                ->chunk(5000, function ($rows) use ($addItem): void {
                    foreach ($rows as $row) {
                        $addItem((int) $row->part_catalog_category_id, (int) $row->part_catalog_item_id);
                    }
                });

            return collect($itemIdsByRoot)
                ->map(fn (array $itemIds): int => count($itemIds))
                ->all();
        });

        $categories->each(function (PartCatalogCategory $category) use ($branchItemCounts, $modelLabelQueryValues): void {
            $category->setAttribute('branch_items_count',
                ($branchItemCounts[(int) $category->id] ?? 0)
                + $this->uncategorizedModelItemsCount($category, $modelLabelQueryValues)
            );
        });
    }

    protected function teslaOfficialBranchItemIds(array $branchCategoryIds): array
    {
        $cacheKey = 'part-catalog:tesla-official-branch-item-ids:v1:'.md5(implode(',', $branchCategoryIds));

        return $this->rememberCatalogCache($cacheKey, function () use ($branchCategoryIds): array {
            $itemIds = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('part_catalog_category_id', $branchCategoryIds)
                ->pluck('id');

            $occurrenceItemIds = DB::table('part_catalog_item_occurrences')
                ->where('source', 'tesla_official')
                ->whereIn('part_catalog_category_id', $branchCategoryIds)
                ->pluck('part_catalog_item_id');

            if ($this->shouldUseTeslaOfficialRawCategoryFallback($branchCategoryIds)) {
                $rawItemIds = PartCatalogItem::query()
                    ->where('source', 'tesla_official')
                    ->where(function (Builder $rawBuilder) use ($branchCategoryIds): void {
                        foreach ($branchCategoryIds as $categoryId) {
                            $rawBuilder
                                ->orWhere('raw_attributes', 'like', '%"category_id":'.$categoryId.'%')
                                ->orWhere('raw_attributes', 'like', '%"category_id": '.$categoryId.'%');
                        }
                    })
                    ->pluck('id');

                $itemIds = $itemIds->merge($rawItemIds);
            }

            return $itemIds
                ->merge($occurrenceItemIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        });
    }

    protected function shouldUseTeslaOfficialRawCategoryFallback(array $branchCategoryIds): bool
    {
        if ($branchCategoryIds === []) {
            return true;
        }

        if (! PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereIn('part_catalog_category_id', $branchCategoryIds)
            ->exists()) {
            return false;
        }

        return ! DB::table('part_catalog_item_occurrences')
            ->where('source', 'tesla_official')
            ->whereIn('part_catalog_category_id', $branchCategoryIds)
            ->exists();
    }

    protected function orWhereDrivePartsRawCategoryBranch(Builder $query, array $branchCategoryIds): void
    {
        $urls = $this->drivePartsBranchCategoryUrls($branchCategoryIds);
        if ($urls === []) {
            return;
        }

        $query->orWhere(function (Builder $rawBuilder) use ($urls): void {
            $rawBuilder->where(function (Builder $urlBuilder) use ($urls): void {
                foreach ($urls as $url) {
                    $urlBuilder->orWhere('raw_attributes', 'like', '%'.$this->escapeLike($url).'%');
                }
            });
        });
    }

    protected function drivePartsBranchCategoryUrls(array $branchCategoryIds): array
    {
        if ($branchCategoryIds === []) {
            return [];
        }

        return PartCatalogCategory::query()
            ->where('source', 'driveparts')
            ->whereIn('id', $branchCategoryIds)
            ->pluck('source_url')
            ->flatMap(fn (?string $url): array => $this->drivePartsCategoryUrlVariants((string) $url))
            ->unique()
            ->values()
            ->all();
    }

    protected function drivePartsCategoryUrlVariants(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $ukrainianUrl = preg_replace('#://([^/]+)/ru/#', '://$1/', $url) ?: $url;
        $russianUrl = str_contains($ukrainianUrl, '://drive-parts.com.ua/ru/')
            ? $ukrainianUrl
            : (preg_replace('#://([^/]+)/#', '://$1/ru/', $ukrainianUrl, 1) ?: $ukrainianUrl);

        return collect([$url, $ukrainianUrl, $russianUrl])
            ->map(fn (string $candidate): string => rtrim($candidate, '/').'/')
            ->flatMap(fn (string $candidate): array => [
                $candidate,
                str_replace('/', '\\/', $candidate),
            ])
            ->unique()
            ->values()
            ->all();
    }

    protected function escapeLike(string $value): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? addcslashes($value, '\\%_')
            : addcslashes($value, '%_');
    }

    protected function uncategorizedModelItemsCount(PartCatalogCategory $category, ?callable $modelLabelQueryValues): int
    {
        if ((int) $category->depth !== 0 || trim((string) $category->model_label) === '') {
            return 0;
        }

        $modelLabels = $modelLabelQueryValues !== null
            ? $modelLabelQueryValues([(string) $category->model_label])
            : [(string) $category->model_label];

        if ($modelLabels === []) {
            return 0;
        }

        return (int) PartCatalogItem::query()
            ->where('source', $category->source)
            ->whereNull('part_catalog_category_id')
            ->whereIn('model_label', $modelLabels)
            ->count();
    }

    protected function shouldSkipBranchItemCounts(string $source): bool
    {
        return $this->catalogSourceValue($source) === 'driveparts';
    }

    protected function catalogSourceValue(?string $source): string
    {
        return $source ?? 'tesla_official';
    }

    protected function rememberCatalogCache(string $key, callable $callback): mixed
    {
        if (app()->runningUnitTests()) {
            return $callback();
        }

        return Cache::remember($key, now()->addMinutes(15), $callback);
    }
}
