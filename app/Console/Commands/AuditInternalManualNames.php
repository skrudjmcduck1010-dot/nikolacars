<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogManualNameService;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AuditInternalManualNames extends Command
{
    protected $signature = 'parts:audit-internal-manual-names
        {--part-number=* : Limit to one or more exact part numbers}
        {--locale=all : Locale to inspect: all, ru, or ua}
        {--limit=0 : Maximum part-number groups to inspect}
        {--examples=10 : Mismatch examples to show}
        {--repair : Propagate newest non-empty manual value to exact internal matches}
        {--dry-run : Force read-only mode, even with --repair}';

    protected $description = 'Audit and repair manual RU/UA names shared by NikolaCars and Tesla official exact part-number rows.';

    public function handle(PartCatalogManualNameService $manualNames): int
    {
        $repair = (bool) $this->option('repair') && ! (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $locales = $this->locales();

        if ($locales === []) {
            $this->error('Invalid --locale value. Use all, ru, or ua.');

            return self::FAILURE;
        }

        $partNumbers = collect((array) $this->option('part-number'))
            ->map(fn (mixed $partNumber): string => $manualNames->normalizedPartNumber((string) $partNumber))
            ->filter()
            ->unique()
            ->values();

        $stats = [
            'part_numbers_seen' => 0,
            'items_seen' => 0,
            'manual_locale_groups' => 0,
            'in_sync' => 0,
            'out_of_sync' => 0,
            'would_repair' => 0,
            'repaired' => 0,
            'blank_locked_without_value' => 0,
            'ru_out_of_sync' => 0,
            'ua_out_of_sync' => 0,
        ];
        $examples = [];

        $query = $this->internalItemsQuery()
            ->when($partNumbers->isNotEmpty(), fn (Builder $query) => $query->where(function (Builder $partQuery) use ($partNumbers): void {
                foreach ($partNumbers as $partNumber) {
                    $partQuery->orWhereRaw('upper(trim(part_number)) = ?', [$partNumber]);
                }
            }))
            ->orderByRaw('upper(trim(part_number))')
            ->orderBy('id');

        $currentPartNumber = null;
        $currentItems = collect();
        $stop = false;

        foreach ($query->cursor() as $item) {
            $partNumber = $manualNames->normalizedPartNumber($item->part_number);
            if ($partNumber === '') {
                continue;
            }

            if ($currentPartNumber !== null && $partNumber !== $currentPartNumber) {
                $this->inspectPartNumberGroup(
                    $manualNames,
                    $currentPartNumber,
                    $currentItems,
                    $locales,
                    $repair,
                    $stats,
                    $examples,
                    $examplesLimit
                );

                if ($limit > 0 && $stats['part_numbers_seen'] >= $limit) {
                    $stop = true;

                    break;
                }

                $currentItems = collect();
            }

            $currentPartNumber = $partNumber;
            $currentItems->push($item);
        }

        if (! $stop && $currentPartNumber !== null && $currentItems->isNotEmpty()) {
            $this->inspectPartNumberGroup(
                $manualNames,
                $currentPartNumber,
                $currentItems,
                $locales,
                $repair,
                $stats,
                $examples,
                $examplesLimit
            );
        }

        $this->info(($repair ? 'Repaired' : 'Scanned').' internal manual localized names.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['part_number', 'locale', 'winner_item_id', 'winner_source', 'winner_value', 'mismatched_item_ids', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, PartCatalogItem>  $items
     * @param  array<int, string>  $locales
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectPartNumberGroup(
        PartCatalogManualNameService $manualNames,
        string $partNumber,
        Collection $items,
        array $locales,
        bool $repair,
        array &$stats,
        array &$examples,
        int $examplesLimit
    ): void {
        $stats['part_numbers_seen']++;
        $stats['items_seen'] += $items->count();

        foreach ($locales as $locale) {
            $this->inspectLocaleGroup(
                $manualNames,
                $partNumber,
                $items,
                $locale,
                $repair,
                $stats,
                $examples,
                $examplesLimit
            );
        }
    }

    /**
     * @param  Collection<int, PartCatalogItem>  $items
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectLocaleGroup(
        PartCatalogManualNameService $manualNames,
        string $partNumber,
        Collection $items,
        string $locale,
        bool $repair,
        array &$stats,
        array &$examples,
        int $examplesLimit
    ): void {
        $column = $locale === 'ru' ? 'name_ru' : 'name_ua';
        $lockedItems = $items
            ->filter(fn (PartCatalogItem $item): bool => $this->manualLockTimestamp($item, $column) !== null)
            ->values();
        $winner = $lockedItems
            ->filter(fn (PartCatalogItem $item): bool => trim((string) $item->{$column}) !== '')
            ->sortByDesc(fn (PartCatalogItem $item): string => $this->manualLockSortKey($item, $column).'-'.str_pad((string) $item->id, 12, '0', STR_PAD_LEFT))
            ->first();

        if (! $winner instanceof PartCatalogItem) {
            if ($lockedItems->isNotEmpty()) {
                $stats['blank_locked_without_value']++;
            }

            return;
        }

        $stats['manual_locale_groups']++;
        $winnerValue = trim((string) $winner->{$column});
        $mismatchedItems = $items
            ->filter(fn (PartCatalogItem $item): bool => trim((string) $item->{$column}) !== $winnerValue
                || $this->manualLockTimestamp($item, $column) === null)
            ->values();

        if ($mismatchedItems->isEmpty()) {
            $stats['in_sync']++;

            return;
        }

        $stats['out_of_sync']++;
        $stats[$locale.'_out_of_sync']++;
        $action = 'would_repair';

        if ($repair) {
            $manualNames->propagateExistingLocks($winner, [$column => $winnerValue]);
            $stats['repaired']++;
            $action = 'repaired';
        } else {
            $stats['would_repair']++;
        }

        if (count($examples) < $examplesLimit) {
            $examples[] = [
                'part_number' => $partNumber,
                'locale' => $locale,
                'winner_item_id' => $winner->id,
                'winner_source' => $winner->source,
                'winner_value' => $winnerValue,
                'mismatched_item_ids' => $mismatchedItems->pluck('id')->take(8)->implode(', '),
                'action' => $action,
            ];
        }
    }

    protected function internalItemsQuery(): Builder
    {
        return PartCatalogItem::query()
            ->whereNotNull('part_number')
            ->where('part_number', '!=', '')
            ->where(function (Builder $sourceQuery): void {
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

    /**
     * @return array<int, string>
     */
    protected function selectColumns(): array
    {
        return array_values(array_filter([
            'id',
            'source',
            'source_url',
            'part_number',
            'name_ru',
            'name_ua',
            'raw_attributes',
            Schema::hasColumn('part_catalog_items', 'name_ru_manually_locked_at') ? 'name_ru_manually_locked_at' : null,
            Schema::hasColumn('part_catalog_items', 'name_ua_manually_locked_at') ? 'name_ua_manually_locked_at' : null,
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function locales(): array
    {
        return match ((string) $this->option('locale')) {
            'all' => ['ru', 'ua'],
            'ru' => ['ru'],
            'ua' => ['ua'],
            default => [],
        };
    }

    protected function manualLockTimestamp(PartCatalogItem $item, string $column): mixed
    {
        $lockColumn = $column === 'name_ru'
            ? 'name_ru_manually_locked_at'
            : 'name_ua_manually_locked_at';

        if (Schema::hasColumn('part_catalog_items', $lockColumn) && $item->{$lockColumn} !== null) {
            return $item->{$lockColumn};
        }

        return data_get(PartCatalogRawAttributes::from($item), 'manual_name_locks.'.($column === 'name_ru' ? 'ru' : 'ua'));
    }

    protected function manualLockSortKey(PartCatalogItem $item, string $column): string
    {
        $timestamp = $this->manualLockTimestamp($item, $column);

        return is_object($timestamp) && method_exists($timestamp, 'format')
            ? $timestamp->format('Y-m-d H:i:s.u')
            : (string) $timestamp;
    }
}
