<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\PartCatalogSourceStat;
use App\Support\PartCatalogLanguageMarkerConflict;
use App\Support\PartCatalogLanguageMarkers;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartCatalogSourceStatsService
{
    protected const COMPETITOR_SOURCES = [
        'tcarservice',
        'teslapartsukraine',
        'tsk',
        'stock-tesla',
        'teslahelp',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
    ];

    protected ?array $activeLanguageMarkers = null;

    public function countsFor(string $source): ?array
    {
        if (! $this->isTrackedSource($source) || ! Schema::hasTable('part_catalog_source_stats')) {
            return null;
        }

        $stat = PartCatalogSourceStat::query()->find($source);

        if ($stat === null) {
            $stat = $this->rebuild($source);
        }

        return [
            'image' => [
                'total' => (int) $stat->total_count,
                'with' => (int) $stat->with_image_count,
                'without' => (int) $stat->without_image_count,
            ],
            'name' => [
                'conflict' => (int) $stat->name_conflict_count,
                'missing_ru' => (int) $stat->missing_ru_count,
                'missing_ua' => (int) $stat->missing_ua_count,
            ],
        ];
    }

    public function rebuild(?string $source = null): PartCatalogSourceStat|array
    {
        if (! Schema::hasTable('part_catalog_source_stats')) {
            return [];
        }

        if ($source === null) {
            return collect(self::COMPETITOR_SOURCES)
                ->mapWithKeys(fn (string $trackedSource): array => [$trackedSource => $this->rebuild($trackedSource)])
                ->all();
        }

        $counts = [
            'total_count' => 0,
            'with_image_count' => 0,
            'without_image_count' => 0,
            'name_conflict_count' => 0,
            'missing_ru_count' => 0,
            'missing_ua_count' => 0,
        ];

        PartCatalogItem::query()
            ->where('source', $source)
            ->select($this->itemStatsColumns())
            ->orderBy('id')
            ->chunkById(1000, function ($items) use (&$counts): void {
                foreach ($items as $item) {
                    $flags = $this->flagsForItem($item);

                    $counts['total_count']++;
                    $counts[$flags['has_image'] ? 'with_image_count' : 'without_image_count']++;
                    $counts['name_conflict_count'] += $flags['name_conflict'] ? 1 : 0;
                    $counts['missing_ru_count'] += $flags['missing_ru'] ? 1 : 0;
                    $counts['missing_ua_count'] += $flags['missing_ua'] ? 1 : 0;
                }
            });

        return PartCatalogSourceStat::query()->updateOrCreate(
            ['source' => $source],
            $counts + ['rebuilt_at' => now()]
        );
    }

    protected function itemStatsColumns(): array
    {
        return collect([
            'id',
            'source',
            'name_ru',
            'name_ua',
            'name_ru_manually_locked_at',
            'name_ua_manually_locked_at',
            'raw_attributes',
        ])
            ->filter(fn (string $column): bool => Schema::hasColumn('part_catalog_items', $column))
            ->values()
            ->all();
    }

    public function itemCreated(PartCatalogItem $item): void
    {
        if (! $this->canUpdateItemStats($item)) {
            return;
        }

        $this->applyDelta((string) $item->source, $this->deltaForFlags($this->flagsForItem($item), 1));
    }

    public function itemUpdated(PartCatalogItem $item): void
    {
        if (! Schema::hasTable('part_catalog_source_stats')) {
            return;
        }

        $oldSource = (string) $item->getOriginal('source');
        $newSource = (string) $item->source;
        $oldFlags = $this->flagsForItem($this->itemFromAttributes($item->getOriginal()));
        $newFlags = $this->flagsForItem($item);

        if ($this->isTrackedSource($oldSource)) {
            $this->applyDelta($oldSource, $this->deltaForFlags($oldFlags, -1));
        }

        if ($this->isTrackedSource($newSource)) {
            $this->applyDelta($newSource, $this->deltaForFlags($newFlags, 1));
        }
    }

    public function itemDeleted(PartCatalogItem $item): void
    {
        if (! $this->canUpdateItemStats($item)) {
            return;
        }

        $this->applyDelta((string) $item->source, $this->deltaForFlags($this->flagsForItem($item), -1));
    }

    public function flagsForItem(PartCatalogItem $item): array
    {
        return [
            'has_image' => $this->itemHasCatalogImage($item),
            'missing_ru' => blank($item->name_ru),
            'missing_ua' => blank($item->name_ua),
            'name_conflict' => $this->itemHasNameConflict($item),
        ];
    }

    protected function applyDelta(string $source, array $delta): void
    {
        if (! $this->isTrackedSource($source) || ! Schema::hasTable('part_catalog_source_stats')) {
            return;
        }

        PartCatalogSourceStat::query()->firstOrCreate(['source' => $source]);

        $driver = DB::connection()->getDriverName();
        $cast = $driver === 'mysql' ? 'signed' : 'integer';
        $updates = collect($delta)
            ->mapWithKeys(fn (int $value, string $column): array => [
                $column => DB::raw("case when cast({$column} as {$cast}) + ({$value}) < 0 then 0 else cast({$column} as {$cast}) + ({$value}) end"),
            ])
            ->all();

        PartCatalogSourceStat::query()
            ->whereKey($source)
            ->update($updates + ['updated_at' => now()]);
    }

    protected function deltaForFlags(array $flags, int $direction): array
    {
        return [
            'total_count' => $direction,
            'with_image_count' => ($flags['has_image'] ? 1 : 0) * $direction,
            'without_image_count' => ($flags['has_image'] ? 0 : 1) * $direction,
            'name_conflict_count' => ($flags['name_conflict'] ? 1 : 0) * $direction,
            'missing_ru_count' => ($flags['missing_ru'] ? 1 : 0) * $direction,
            'missing_ua_count' => ($flags['missing_ua'] ? 1 : 0) * $direction,
        ];
    }

    protected function canUpdateItemStats(PartCatalogItem $item): bool
    {
        return $this->isTrackedSource((string) $item->source)
            && Schema::hasTable('part_catalog_source_stats');
    }

    protected function isTrackedSource(string $source): bool
    {
        return in_array($source, self::COMPETITOR_SOURCES, true);
    }

    protected function itemFromAttributes(array $attributes): PartCatalogItem
    {
        $item = new PartCatalogItem;
        $item->forceFill($attributes);
        $item->exists = true;

        return $item;
    }

    protected function itemHasCatalogImage(PartCatalogItem $item): bool
    {
        $rawAttributes = $this->rawAttributes($item);

        if (filter_var(Arr::get($rawAttributes, 'catalog_image_missing'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $keys = (string) $item->source === 'tesla_official'
            ? ['part_image_urls', 'image_url']
            : ['image_urls', 'remote_image_urls', 'image_url', 'remote_image_url', 'primary_image_url'];

        foreach ($keys as $key) {
            foreach (Arr::wrap(Arr::get($rawAttributes, $key)) as $url) {
                if ($this->usableImageUrl($url)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function usableImageUrl(mixed $url): bool
    {
        $url = trim((string) $url);

        return $url !== ''
            && ! Str::contains($url, [
                '/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_',
                '/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_',
            ]);
    }

    protected function itemHasNameConflict(PartCatalogItem $item): bool
    {
        return $this->localeHasNameConflict($item, 'ru')
            || $this->localeHasNameConflict($item, 'ua');
    }

    protected function localeHasNameConflict(PartCatalogItem $item, string $locale): bool
    {
        $lockColumn = 'name_'.$locale.'_manually_locked_at';
        if ($item->{$lockColumn} !== null) {
            return false;
        }

        $conflict = Arr::get($this->rawAttributes($item), 'name_language_marker_conflict_'.$locale);
        if ((int) Arr::get((array) $conflict, 'count', 0) <= 0) {
            return false;
        }

        return PartCatalogLanguageMarkerConflict::hasActiveMarker($conflict, $this->activeLanguageMarkers());
    }

    protected function activeLanguageMarkers(): array
    {
        return $this->activeLanguageMarkers ??= PartCatalogLanguageMarkers::activeNormalized()->all();
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }
}
