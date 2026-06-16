<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartCatalogLocalizedNameCleaner;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartCatalogManualNameService
{
    public const INTERNAL_MANUAL_NAME_SOURCES = [
        'tesla_official',
        'nikolacars',
    ];

    public function lockAndPropagate(PartCatalogItem $item, array $updates): array
    {
        $updates = collect($updates)
            ->only(['name_ru', 'name_ua'])
            ->map(fn (mixed $value): ?string => PartCatalogLocalizedNameCleaner::clean($value))
            ->all();

        $partNumber = $this->normalizedPartNumber($item->part_number);
        if ($updates === []) {
            return ['name_ru' => 0, 'name_ua' => 0];
        }

        $lockedAt = now();
        $counts = ['name_ru' => 0, 'name_ua' => 0];

        if ($partNumber === '') {
            $this->applyLocksToItem($item, $updates, $lockedAt);

            foreach (array_keys($updates) as $column) {
                $counts[$column] = 1;
            }

            return $counts;
        }

        DB::transaction(function () use ($item, $partNumber, $updates, $lockedAt, &$counts): void {
            if (! $this->isInternalManualNameItem($item)) {
                $this->applyLocksToItem($item, $updates, $lockedAt);

                foreach (array_keys($updates) as $column) {
                    $counts[$column] = 1;
                }

                $item->refresh();

                return;
            }

            $matchedItems = $this->internalManualNameItemsForPartNumber($partNumber)
                ->orderBy('id')
                ->get($this->lockSelectColumns());

            if ($matchedItems->doesntContain(fn (PartCatalogItem $matchedItem): bool => (int) $matchedItem->id === (int) $item->id)) {
                $matchedItems->push($item);
            }

            foreach ($matchedItems as $matchedItem) {
                $this->applyLocksToItem($matchedItem, $updates, $lockedAt);

                foreach (array_keys($updates) as $column) {
                    $counts[$column]++;
                }
            }

            if ($matchedItems->contains(fn (PartCatalogItem $matchedItem): bool => (int) $matchedItem->id === (int) $item->id)) {
                $item->refresh();
            }
        });

        return $counts;
    }

    public function propagateExistingLocks(PartCatalogItem $item, array $updates): array
    {
        $updates = collect($updates)
            ->only(['name_ru', 'name_ua'])
            ->map(fn (mixed $value): ?string => PartCatalogLocalizedNameCleaner::clean($value))
            ->all();

        $partNumber = $this->normalizedPartNumber($item->part_number);
        if ($updates === []) {
            return ['name_ru' => 0, 'name_ua' => 0];
        }

        $lockedAtByColumn = [];
        foreach (array_keys($updates) as $column) {
            $lockedAtByColumn[$column] = $this->manualLockTimestamp($item, $column) ?? now();
        }

        $counts = ['name_ru' => 0, 'name_ua' => 0];

        if ($partNumber === '') {
            $this->applyLocksToItemWithColumnTimestamps($item, $updates, $lockedAtByColumn);

            foreach (array_keys($updates) as $column) {
                $counts[$column] = 1;
            }

            return $counts;
        }

        DB::transaction(function () use ($item, $partNumber, $updates, $lockedAtByColumn, &$counts): void {
            if (! $this->isInternalManualNameItem($item)) {
                $this->applyLocksToItemWithColumnTimestamps($item, $updates, $lockedAtByColumn);

                foreach (array_keys($updates) as $column) {
                    $counts[$column] = 1;
                }

                $item->refresh();

                return;
            }

            $matchedItems = $this->internalManualNameItemsForPartNumber($partNumber)
                ->orderBy('id')
                ->get($this->lockSelectColumns());

            if ($matchedItems->doesntContain(fn (PartCatalogItem $matchedItem): bool => (int) $matchedItem->id === (int) $item->id)) {
                $matchedItems->push($item);
            }

            foreach ($matchedItems as $matchedItem) {
                $this->applyLocksToItemWithColumnTimestamps($matchedItem, $updates, $lockedAtByColumn);

                foreach (array_keys($updates) as $column) {
                    $counts[$column]++;
                }
            }

            if ($matchedItems->contains(fn (PartCatalogItem $matchedItem): bool => (int) $matchedItem->id === (int) $item->id)) {
                $item->refresh();
            }
        });

        return $counts;
    }

    public function lockItem(PartCatalogItem $item, array $updates): array
    {
        $updates = collect($updates)
            ->only(['name_ru', 'name_ua'])
            ->map(fn (mixed $value): ?string => PartCatalogLocalizedNameCleaner::clean($value))
            ->all();

        if ($updates === []) {
            return ['name_ru' => 0, 'name_ua' => 0];
        }

        $this->applyLocksToItem($item, $updates, now());
        $item->refresh();

        return [
            'name_ru' => array_key_exists('name_ru', $updates) ? 1 : 0,
            'name_ua' => array_key_exists('name_ua', $updates) ? 1 : 0,
        ];
    }

    public function isLocked(PartCatalogItem $item, string $column): bool
    {
        $lockColumn = $this->lockColumn($column);
        if ($lockColumn !== null && $this->hasColumn($lockColumn) && $item->{$lockColumn} !== null) {
            return true;
        }

        return $this->manualLockTimestamp($item, $column) !== null;
    }

    public function lockedPayloadForPartNumber(PartCatalogItem $item): array
    {
        $payload = [];
        foreach ($this->lockedNameValuesForPartNumber($item->part_number, $item->getKey()) as $column => $lockedName) {
            $payload[$column] = $lockedName['value'];
            $payload = array_merge($payload, $this->lockPayload([$column => $lockedName['value']], $lockedName['locked_at']));
        }

        return $payload;
    }

    public function lockedPayloadForPartNumberValue(?string $partNumber, ?int $exceptItemId = null): array
    {
        $payload = [];
        foreach ($this->lockedNameValuesForPartNumber($partNumber, $exceptItemId) as $column => $lockedName) {
            $payload[$column] = $lockedName['value'];
            $payload = array_merge($payload, $this->lockPayload([$column => $lockedName['value']], $lockedName['locked_at']));
        }

        return $payload;
    }

    public function lockedNameValuesForPartNumber(?string $partNumber, ?int $exceptItemId = null): array
    {
        $partNumber = $this->normalizedPartNumber($partNumber);
        if ($partNumber === '') {
            return [];
        }

        $query = $this->internalManualNameItemsForPartNumber($partNumber);
        if ($exceptItemId !== null) {
            $query->whereKeyNot($exceptItemId);
        }

        $lockedItems = $query->get($this->lockSelectColumns());
        $values = [];

        foreach (['name_ru', 'name_ua'] as $column) {
            $lockedItem = $lockedItems
                ->filter(fn (PartCatalogItem $candidate): bool => $this->manualLockTimestamp($candidate, $column) !== null
                    && trim((string) $candidate->{$column}) !== '')
                ->sortByDesc(fn (PartCatalogItem $candidate): string => (string) $this->manualLockTimestamp($candidate, $column))
                ->first();

            if ($lockedItem === null) {
                continue;
            }

            $values[$column] = [
                'value' => $lockedItem->{$column},
                'locked_at' => $this->manualLockTimestamp($lockedItem, $column),
            ];
        }

        return $values;
    }

    public function internalManualNameItemsForPartNumber(string $normalizedPartNumber): Builder
    {
        return PartCatalogItem::query()
            ->whereIn('source', self::INTERNAL_MANUAL_NAME_SOURCES)
            ->whereNotNull('part_number')
            ->whereRaw('upper(trim(part_number)) = ?', [$normalizedPartNumber])
            ->where(fn (Builder $query): Builder => $this->whereInternalManualNameItem($query));
    }

    public function normalizedPartNumber(?string $partNumber): string
    {
        return Str::upper(trim((string) $partNumber));
    }

    protected function lockPayload(array $updates, mixed $lockedAt): array
    {
        $payload = [];

        foreach ($updates as $column => $value) {
            $payload[$column] = $value;

            $lockColumn = $this->lockColumn($column);
            if ($lockColumn !== null && $this->hasColumn($lockColumn)) {
                $payload[$lockColumn] = $lockedAt;
            }
        }

        return $payload;
    }

    protected function applyLocksToItem(PartCatalogItem $item, array $updates, mixed $lockedAt): void
    {
        $this->applyLocksToItemWithColumnTimestamps(
            $item,
            $updates,
            array_fill_keys(array_keys($updates), $lockedAt),
        );
    }

    protected function applyLocksToItemWithColumnTimestamps(PartCatalogItem $item, array $updates, array $lockedAtByColumn): void
    {
        $rawAttributes = $this->rawAttributes($item);

        foreach ($updates as $column => $value) {
            $locale = $this->localeForColumn($column);
            $lockedAt = $lockedAtByColumn[$column] ?? now();

            $rawAttributes['manual_name_locks'][$locale] = (string) $lockedAt;
            unset($rawAttributes['name_language_marker_conflict_'.$locale]);
            $payload[$column] = $value;

            $lockColumn = $this->lockColumn($column);
            if ($lockColumn !== null && $this->hasColumn($lockColumn)) {
                $payload[$lockColumn] = $lockedAt;
            }
        }

        $item->forceFill(($payload ?? []) + [
            'raw_attributes' => $rawAttributes,
        ])->save();
    }

    protected function isInternalManualNameItem(PartCatalogItem $item): bool
    {
        if ($item->source === 'nikolacars') {
            return true;
        }

        if ($item->source !== 'tesla_official') {
            return false;
        }

        $sourceUrl = (string) $item->source_url;

        return Str::startsWith($sourceUrl, [
            'https://parts.tesla.com/',
            'tesla-common://donor-product/',
        ]);
    }

    protected function whereInternalManualNameItem(Builder $query): Builder
    {
        return $query->where(function (Builder $sourceQuery): void {
            $sourceQuery
                ->where('source', 'nikolacars')
                ->orWhere(function (Builder $teslaQuery): void {
                    $teslaQuery
                        ->where('source', 'tesla_official')
                        ->where(function (Builder $urlQuery): void {
                            $urlQuery
                                ->where('source_url', 'like', 'https://parts.tesla.com/%')
                                ->orWhere('source_url', 'like', 'tesla-common://donor-product/%');
                        });
                });
        });
    }

    protected function lockSelectColumns(): array
    {
        return array_values(array_filter([
            'id',
            'part_number',
            'source',
            'name_ru',
            'name_ua',
            'raw_attributes',
            $this->hasColumn('name_ru_manually_locked_at') ? 'name_ru_manually_locked_at' : null,
            $this->hasColumn('name_ua_manually_locked_at') ? 'name_ua_manually_locked_at' : null,
        ]));
    }

    protected function manualLockTimestamp(PartCatalogItem $item, string $column): mixed
    {
        $lockColumn = $this->lockColumn($column);
        if ($lockColumn !== null && $this->hasColumn($lockColumn) && $item->{$lockColumn} !== null) {
            return $item->{$lockColumn};
        }

        return data_get($this->rawAttributes($item), 'manual_name_locks.'.$this->localeForColumn($column));
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function hasColumn(string $column): bool
    {
        static $columns = [];

        return $columns[$column] ??= Schema::hasColumn('part_catalog_items', $column);
    }

    protected function localeForColumn(string $column): string
    {
        return $column === 'name_ru' ? 'ru' : 'ua';
    }

    protected function lockColumn(string $column): ?string
    {
        return match ($column) {
            'name_ru' => 'name_ru_manually_locked_at',
            'name_ua' => 'name_ua_manually_locked_at',
            default => null,
        };
    }
}
