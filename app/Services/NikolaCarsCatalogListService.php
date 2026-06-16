<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NikolaCarsCatalogListService
{
    public function activeItems(): Collection
    {
        return app(NikolaCarsInventoryService::class)->activeItemsQuery()->get();
    }

    public function itemGroups(Collection $items, array $usdRate, callable $displayItemName): Collection
    {
        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            $items,
            $usdRate,
            $displayItemName,
        );

        return $groups
            ->filter(fn (array $group): bool => (bool) $group['is_reserved'])
            ->concat($groups->filter(fn (array $group): bool => ! (bool) $group['is_reserved'] && (float) $group['quantity'] > 0))
            ->concat($groups->filter(fn (array $group): bool => ! (bool) $group['is_reserved'] && (float) $group['quantity'] <= 0))
            ->values();
    }

    public function paginateItemGroups(Collection $groups, Request $request): LengthAwarePaginator
    {
        $pageName = 'items_page';
        $perPage = 100;
        $page = max(1, Paginator::resolveCurrentPage($pageName));

        return (new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName,
            ],
        ))->withQueryString();
    }

    public function orderByAvailabilityBucket(Builder $query, string $driver): void
    {
        $stockQuantity = $this->jsonTextExpression('raw_attributes', 'stock_quantity', $driver);
        $reservedQuantity = $this->jsonTextExpression('raw_attributes', 'reserved_quantity', $driver);
        $availableDifference = "cast(coalesce({$stockQuantity}, '0') as decimal(12,3)) - cast(coalesce({$reservedQuantity}, '0') as decimal(12,3))";
        $availableQuantity = "case when {$availableDifference} > 0 then {$availableDifference} else 0 end";

        $query->orderByRaw(
            "case
                when cast(coalesce({$stockQuantity}, '0') as decimal(12,3)) > 0
                    and cast(coalesce({$reservedQuantity}, '0') as decimal(12,3)) > 0
                    then 0
                when {$availableQuantity} > 0
                    then 1
                else 2
            end"
        );
    }

    public function applyVinFilter(Builder $query, array|string $vins, string $driver): void
    {
        $vins = collect((array) $vins)
            ->map(fn (mixed $vin): string => trim((string) $vin))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($vins === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($vins), '?'));

        if ($driver === 'pgsql') {
            $query->whereRaw(
                "coalesce(raw_attributes::jsonb ->> 'donor_vin', '') in ({$placeholders})",
                $vins,
            );

            return;
        }

        if ($driver === 'sqlite') {
            $query->whereRaw(
                "coalesce(json_extract(raw_attributes, '$.donor_vin'), '') in ({$placeholders})",
                $vins,
            );

            return;
        }

        $query->whereRaw(
            "coalesce(json_unquote(json_extract(raw_attributes, '$.donor_vin')), '') in ({$placeholders})",
            $vins,
        );
    }

    public function donorFilterOptions(Collection $items): Collection
    {
        return $items
            ->map(fn (PartCatalogItem $item): string => Str::upper(trim((string) data_get($item->raw_attributes, 'donor_vin', ''))))
            ->filter()
            ->unique(fn (string $value): string => Str::upper($value))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function topCategoryFilterOptions(Collection $items): Collection
    {
        $options = $items
            ->map(fn (PartCatalogItem $item): string => $this->topCategory($item))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $withoutCategory = $options->filter(fn (string $value): bool => Str::lower($value) === Str::lower("\u{0411}\u{0435}\u{0437} \u{043A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0438}\u{0438}"));

        return $withoutCategory
            ->concat($options->reject(fn (string $value): bool => Str::lower($value) === Str::lower("\u{0411}\u{0435}\u{0437} \u{043A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0438}\u{0438}")))
            ->values();
    }

    public function filterItemsByTopCategory(Collection $items, array $topCategories): Collection
    {
        $selected = collect($topCategories)
            ->map(fn (string $category): string => Str::lower($this->normalizeTopCategory($category)))
            ->filter()
            ->flip();

        if ($selected->isEmpty()) {
            return $items;
        }

        return $items
            ->filter(fn (PartCatalogItem $item): bool => $selected->has(Str::lower($this->topCategory($item))))
            ->values();
    }

    public function donorCarsByVinFromItems(Collection $items): Collection
    {
        $donorLabels = $items
            ->map(fn (PartCatalogItem $item): string => Str::upper(trim((string) data_get($item->raw_attributes, 'donor_vin', ''))))
            ->filter()
            ->unique()
            ->values();

        if ($donorLabels->isEmpty()) {
            return collect();
        }

        return DonorCar::query()
            ->whereIn('vin', $donorLabels->all())
            ->get(['id', 'vin', 'model', 'year', 'color', 'paint_code'])
            ->keyBy(fn (DonorCar $donorCar): string => Str::upper(trim((string) $donorCar->vin)));
    }

    public function donorCarsByVinOptions(Collection $vins): Collection
    {
        $donorLabels = $vins
            ->map(fn (string $vin): string => Str::upper(trim($vin)))
            ->filter()
            ->unique()
            ->values();

        if ($donorLabels->isEmpty()) {
            return collect();
        }

        return DonorCar::query()
            ->whereIn('vin', $donorLabels->all())
            ->get(['id', 'vin', 'model', 'year', 'photos'])
            ->keyBy(fn (DonorCar $donorCar): string => Str::upper(trim((string) $donorCar->vin)));
    }

    public function inventoryTotalUsd(Collection $items, array $usdRate): float
    {
        return app(NikolaCarsInventoryService::class)->inventoryTotalUsd($items, $usdRate);
    }

    public function itemsCount(?Collection $items = null): int
    {
        return ($items ?? $this->activeItems())->count();
    }

    public function uniqueArticleCount(?Collection $items = null): int
    {
        $inventory = app(NikolaCarsInventoryService::class);

        return ($items ?? $this->activeItems())
            ->map(fn (PartCatalogItem $item): string => $inventory->normalizePartNumber((string) $item->part_number))
            ->filter()
            ->unique()
            ->count();
    }

    public function addedTodayCount(?Collection $items = null): int
    {
        $items ??= $this->activeItems();
        $today = today();
        $manualProductIds = $items
            ->filter(fn (PartCatalogItem $item): bool => trim((string) data_get($item->raw_attributes, 'manual_create_source_type')) !== '')
            ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'))
            ->filter()
            ->unique()
            ->values();
        $manualProductIdsAddedToday = $manualProductIds->isEmpty()
            ? collect()
            : Product::query()
                ->whereKey($manualProductIds->all())
                ->whereBetween('created_at', [
                    $today->copy()->startOfDay(),
                    $today->copy()->endOfDay(),
                ])
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->flip();

        return $items
            ->flatMap(function (PartCatalogItem $item) use ($manualProductIdsAddedToday, $today): array {
                $keys = [];
                $productId = (int) data_get($item->raw_attributes, 'product_id');
                $entryKey = $productId > 0 ? 'product:'.$productId : 'item:'.$item->getKey();

                if (
                    $productId > 0
                    && trim((string) data_get($item->raw_attributes, 'manual_create_source_type')) !== ''
                    && $manualProductIdsAddedToday->has($productId)
                ) {
                    $keys[] = $entryKey;
                }

                if (
                    $this->isCheckedDamageStatus($item)
                    && $this->isToday(data_get($item->raw_attributes, 'donor_damage_checked_at'), $today)
                ) {
                    $keys[] = $entryKey;
                }

                return $keys;
            })
            ->filter()
            ->unique()
            ->count();
    }

    public function formattedInventoryTotalUsd(): string
    {
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $total = $this->inventoryTotalUsd($this->activeItems(), $usdRate);

        return number_format($total, 2, '.', ' ').' USD';
    }

    protected function topCategory(PartCatalogItem $item): string
    {
        $category = app(NikolaCarsInventoryService::class)->displayCategory($item);
        $parts = preg_split('/\s*\/\s*/u', $category) ?: [];

        return $this->normalizeTopCategory((string) ($parts[0] ?? ''));
    }

    protected function normalizeTopCategory(string $category): string
    {
        return trim((string) preg_replace('/^\d+\s*[-\x{2013}\x{2014}]\s*/u', '', trim($category)));
    }

    protected function isCheckedDamageStatus(PartCatalogItem $item): bool
    {
        $status = trim((string) ($item->quality ?: data_get($item->raw_attributes, 'donor_damage_status')));

        return in_array($status, NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES, true);
    }

    protected function isToday(mixed $value, Carbon $today): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        try {
            return Carbon::parse($value)
                ->timezone(config('app.timezone'))
                ->isSameDay($today);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function jsonTextExpression(string $column, string $key, string $driver): string
    {
        $path = '$.'.$key;

        return $driver === 'sqlite'
            ? "json_extract({$column}, '{$path}')"
            : "json_unquote(json_extract({$column}, '{$path}'))";
    }
}
