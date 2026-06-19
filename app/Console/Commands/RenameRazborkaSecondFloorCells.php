<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RenameRazborkaSecondFloorCells extends Command
{
    protected $signature = 'warehouse:rename-razborka-floor2-cells
        {--write : Apply changes; without this option only a dry-run is performed}';

    protected $description = 'Rename and create Razborka second floor warehouse cells.';

    private const WAREHOUSE_ID = 2;

    private const FLOOR = 'floor_2';

    /**
     * @var array<string, string>
     */
    private array $renames = [
        'P1C' => 'C1C',
        'P2C' => 'C3C',
        'P3C' => 'C4C',
        'P4C' => 'C5C',
        'P1D' => 'C1D',
        'P2D' => 'C3D',
        'P3D' => 'C4D',
        'P4D' => 'C5D',
        'P4A' => 'C5A',
        'P3A' => 'C4A',
        'P2A' => 'C3A',
        'P1A' => 'C1A',
        'P1B' => 'C1B',
        'P2B' => 'C3B',
        'P3B' => 'C4B',
        'P4B' => 'C5B',
        'L1A' => 'B1D',
        'L2A' => 'B2D',
        'L3A' => 'B3D',
        'L4A' => 'B4D',
        'L1B' => 'B1C',
        'L2B' => 'B2C',
        'L3B' => 'B3C',
        'L4B' => 'B4C',
        'L1C' => 'B1B',
        'L2C' => 'B2B',
        'L3C' => 'B3B',
        'L4C' => 'B4B',
        'L1D' => 'B1A',
        'L2D' => 'B2A',
        'L3D' => 'B3A',
        'L4D' => 'B4A',
    ];

    /**
     * @var list<string>
     */
    private array $createOnly = [
        'C2C',
        'C2D',
        'C2A',
        'C2B',
        'A1A',
        'A2A',
        'A3A',
        'A4A',
        'A5A',
        'A6A',
        'A1B',
        'A2B',
        'A3B',
        'A4B',
        'A5B',
        'A6B',
        'A1C',
        'A2C',
        'A3C',
        'A4C',
        'A5C',
        'A6C',
        'A1D',
        'A2D',
        'A3D',
        'A4D',
        'A5D',
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $warehouse = Warehouse::query()->find(self::WAREHOUSE_ID);

        if (! $warehouse instanceof Warehouse) {
            $this->error('Warehouse id 2 was not found.');

            return self::FAILURE;
        }

        $stats = [
            'mode' => $write ? 'write' : 'dry-run',
            'renames_seen' => count($this->renames),
            'renamed_locations' => 0,
            'merged_locations' => 0,
            'archived_locations' => 0,
            'normalized_target_labels' => 0,
            'created_locations' => 0,
            'already_target_exists' => 0,
            'missing_source_created_target' => 0,
            'stock_items_moved_to_existing_target' => 0,
            'stock_items_merged' => 0,
            'stock_items_deleted_after_merge' => 0,
            'errors' => 0,
        ];

        $actions = [];
        $errors = [];

        DB::transaction(function () use ($write, &$stats, &$actions, &$errors): void {
            $locations = $this->floorLocations();
            $knownCells = $this->knownActiveCells($locations);

            foreach ($this->renames as $sourceCell => $targetCell) {
                $source = $this->findByCell($locations, $sourceCell);
                $target = $this->findByCell($locations, $targetCell);

                if (! $source instanceof Location) {
                    if (! $target instanceof Location) {
                        $stats['missing_source_created_target']++;
                        $stats['created_locations']++;
                        $actions[] = [$sourceCell, $targetCell, 'create target; source missing'];

                        if ($write) {
                            $this->createLocation($targetCell);
                            $locations = $this->floorLocations();
                        }

                        $knownCells->put($this->normalizeCell($targetCell), true);

                        continue;
                    }

                    $stats['already_target_exists']++;
                    $actions[] = [$sourceCell, $targetCell, 'target already exists; source missing'];

                    if ($write && $this->ensureLocationLabel($target, $targetCell)) {
                        $stats['normalized_target_labels']++;
                    }

                    continue;
                }

                if ($target instanceof Location && $target->isNot($source)) {
                    $stats['merged_locations']++;
                    $actions[] = [$sourceCell, $targetCell, 'merge source stock into existing target and archive source'];

                    if ($write) {
                        $mergeStats = $this->mergeStockItems($source, $target);
                        $stats['stock_items_moved_to_existing_target'] += $mergeStats['moved'];
                        $stats['stock_items_merged'] += $mergeStats['merged'];
                        $stats['stock_items_deleted_after_merge'] += $mergeStats['deleted'];
                        if ($this->ensureLocationLabel($target, $targetCell)) {
                            $stats['normalized_target_labels']++;
                        }
                        $this->archiveLocation($source, $sourceCell);
                        $stats['archived_locations']++;
                        $locations = $this->floorLocations();
                    }

                    $knownCells->forget($this->normalizeCell($sourceCell));
                    $knownCells->put($this->normalizeCell($targetCell), true);

                    continue;
                }

                $stats['renamed_locations']++;
                $actions[] = [$sourceCell, $targetCell, 'rename location'];

                if ($write) {
                    $this->renameLocation($source, $targetCell);
                    $locations = $this->floorLocations();
                }

                $knownCells->forget($this->normalizeCell($sourceCell));
                $knownCells->put($this->normalizeCell($targetCell), true);
            }

            $targets = collect(array_values($this->renames))
                ->merge($this->createOnly)
                ->unique()
                ->values();

            foreach ($targets as $cell) {
                $locations = $write ? $this->floorLocations() : $locations;
                $normalizedCell = $this->normalizeCell($cell);
                $existingRequiredLocation = $this->findByCell($locations, $cell);
                if ($knownCells->has($normalizedCell) || $existingRequiredLocation instanceof Location) {
                    if ($write && $existingRequiredLocation instanceof Location && $this->ensureLocationLabel($existingRequiredLocation, $cell)) {
                        $stats['normalized_target_labels']++;
                    }
                    $knownCells->put($normalizedCell, true);

                    continue;
                }

                $stats['created_locations']++;
                $actions[] = ['', $cell, 'create missing required cell'];

                if ($write) {
                    $this->createLocation($cell);
                }

                $knownCells->put($normalizedCell, true);
            }

            $duplicateTargets = $this->duplicateActiveCells();
            if ($duplicateTargets->isNotEmpty()) {
                $stats['errors'] += $duplicateTargets->count();
                foreach ($duplicateTargets as $cell => $count) {
                    $errors[] = "Duplicate active cell {$cell}: {$count}";
                }

                if ($write) {
                    throw new \RuntimeException('Duplicate active cells detected after rename.');
                }
            }
        });

        $this->table(['metric', 'value'], collect($stats)->map(fn ($value, $key): array => [$key, $value])->all());

        if ($actions !== []) {
            $this->line('');
            $this->table(['from', 'to', 'action'], array_slice($actions, 0, 80));
        }

        if ($errors !== []) {
            $this->line('');
            $this->table(['error'], collect($errors)->map(fn (string $error): array => [$error])->all());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Location>
     */
    private function floorLocations(): Collection
    {
        return Location::query()
            ->where('warehouse_id', self::WAREHOUSE_ID)
            ->where('floor', self::FLOOR)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int, Location> $locations
     */
    private function findByCell(Collection $locations, string $cell): ?Location
    {
        $normalized = $this->normalizeCell($cell);

        return $locations->first(
            fn (Location $location): bool => $this->normalizeCell($location->cell ?: $location->full_code) === $normalized
        );
    }

    /**
     * @param Collection<int, Location> $locations
     * @return Collection<string, bool>
     */
    private function knownActiveCells(Collection $locations): Collection
    {
        return $locations
            ->filter(fn (Location $location): bool => (bool) $location->is_active)
            ->mapWithKeys(fn (Location $location): array => [
                $this->normalizeCell($location->cell ?: $location->full_code) => true,
            ]);
    }

    private function normalizeCell(?string $cell): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $cell));
    }

    private function createLocation(string $cell): Location
    {
        return Location::query()->create([
            'warehouse_id' => self::WAREHOUSE_ID,
            'floor' => self::FLOOR,
            'zone' => null,
            'row' => null,
            'shelf' => null,
            'cell' => $this->displayCell($cell),
            'full_code' => $this->fullCode($cell),
            'is_active' => true,
        ]);
    }

    private function renameLocation(Location $location, string $cell): void
    {
        $location->forceFill([
            'cell' => $this->displayCell($cell),
            'full_code' => $this->fullCode($cell),
            'is_active' => true,
        ])->save();
    }

    private function ensureLocationLabel(Location $location, string $cell): bool
    {
        $fullCode = $this->fullCode($cell);
        $displayCell = $this->displayCell($cell);
        if ((string) $location->cell === $displayCell
            && (string) $location->full_code === $fullCode
            && (bool) $location->is_active) {
            return false;
        }

        $location->forceFill([
            'cell' => $displayCell,
            'full_code' => $fullCode,
            'is_active' => true,
        ])->save();

        return true;
    }

    private function archiveLocation(Location $location, string $sourceCell): void
    {
        $location->forceFill([
            'cell' => 'ARCHIVED-'.$sourceCell,
            'full_code' => 'ARCHIVED-'.$this->fullCode($sourceCell).'-'.$location->id,
            'is_active' => false,
        ])->save();
    }

    /**
     * @return array{moved:int, merged:int, deleted:int}
     */
    private function mergeStockItems(Location $source, Location $target): array
    {
        $stats = ['moved' => 0, 'merged' => 0, 'deleted' => 0];

        StockItem::query()
            ->where('location_id', $source->id)
            ->orderBy('id')
            ->get()
            ->each(function (StockItem $stockItem) use ($target, &$stats): void {
                $existing = StockItem::query()
                    ->where('product_id', $stockItem->product_id)
                    ->where('location_id', $target->id)
                    ->where('testing_status', $stockItem->testing_status)
                    ->first();

                if ($existing instanceof StockItem) {
                    $existing->forceFill([
                        'quantity' => (float) $existing->quantity + (float) $stockItem->quantity,
                        'reserved_quantity' => (float) $existing->reserved_quantity + (float) $stockItem->reserved_quantity,
                    ])->save();
                    $stockItem->delete();
                    $stats['merged']++;
                    $stats['deleted']++;

                    return;
                }

                $stockItem->forceFill([
                    'warehouse_id' => $target->warehouse_id,
                    'location_id' => $target->id,
                ])->save();
                $stats['moved']++;
            });

        return $stats;
    }

    private function fullCode(string $cell): string
    {
        return 'WH2-F2-'.$this->normalizeCell($cell);
    }

    private function displayCell(string $cell): string
    {
        $normalized = $this->normalizeCell($cell);

        if (preg_match('/^([A-Z]+)(\d+)([A-Z]+)$/', $normalized, $matches) === 1) {
            return "{$matches[1]}/{$matches[2]}/{$matches[3]}";
        }

        return $cell;
    }

    /**
     * @return Collection<string, int>
     */
    private function duplicateActiveCells(): Collection
    {
        return $this->floorLocations()
            ->filter(fn (Location $location): bool => (bool) $location->is_active)
            ->map(fn (Location $location): string => $this->normalizeCell($location->cell ?: $location->full_code))
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1);
    }
}
