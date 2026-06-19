<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\NikolaCarsCatalogItemService;
use App\Services\NikolaCarsInventoryService;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplyWarehousePlacementFromJson extends Command
{
    protected $signature = 'warehouse:apply-placement-json
        {input : Path to JSON rows exported from the placement spreadsheet}
        {--write : Apply changes; without this option only a dry-run is performed}
        {--limit=0 : Limit number of source rows to inspect}
        {--examples=20 : Number of example rows to print per bucket}';

    protected $description = 'One-off placement of active unreserved /admin/zapchasti stock items from exported spreadsheet JSON.';

    protected array $stats = [];

    protected array $issues = [];

    protected array $examples = [];

    protected array $expandedWarehouseIds = [];

    public function handle(): int
    {
        $input = $this->argument('input');
        $path = $this->resolveInputPath((string) $input);
        $write = (bool) $this->option('write');
        $limit = max(0, (int) $this->option('limit'));

        if (! is_file($path)) {
            $this->error("Input file not found: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rows)) {
            $this->error('Input JSON must be an array of spreadsheet rows.');

            return self::FAILURE;
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $activeItems = app(NikolaCarsInventoryService::class)->activeItemsQuery()->get();
        $activeItemIds = $activeItems->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip();
        $reservedItemIds = $activeItems
            ->filter(fn (PartCatalogItem $item): bool => app(NikolaCarsCatalogItemService::class)->isReserved($item))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        $this->stats = [
            'mode' => $write ? 'write' : 'dry-run',
            'excel_rows' => count($rows),
            'active_zapchasti_items' => $activeItems->count(),
            'active_unreserved_zapchasti_items' => $activeItems->count() - $reservedItemIds->count(),
            'matched_active_unreserved_items' => 0,
            'moved_stock_items' => 0,
            'created_locations' => 0,
            'existing_locations' => 0,
            'expanded_warehouse_floor_count' => 0,
            'skipped_not_found_in_zapchasti' => 0,
            'skipped_not_active_or_sold' => 0,
            'skipped_reserved' => 0,
            'skipped_stock_reserved' => 0,
            'skipped_missing_linked_product' => 0,
            'skipped_inactive_or_sold_product' => 0,
            'skipped_no_positive_stock' => 0,
            'skipped_unknown_warehouse' => 0,
            'already_at_target' => 0,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->inspectRow($row, $activeItemIds, $reservedItemIds, $write);
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    protected function inspectRow(array $row, Collection $activeItemIds, Collection $reservedItemIds, bool $write): void
    {
        $code = $this->cell($row, 'Код');
        $article = $this->cell($row, 'Артикул');
        $barcode = $this->cell($row, 'Штрихкод');
        $name = $this->cell($row, 'Наименование');
        $stockText = $this->cell($row, 'Склад');
        $cell = $this->cell($row, 'Ячейка');
        $cell = $cell !== '' ? $cell : null;

        [$item, $matchMethod, $skipReason] = $this->findZapchastiItem($code, $barcode, $activeItemIds, $reservedItemIds);
        if (! $item instanceof PartCatalogItem) {
            $issue = match ($skipReason) {
                'not_active' => 'Позиция NikolaCars найдена, но не входит в активный /admin/zapchasti: продана/списана/сломана/неактивна',
                'reserved' => 'Позиция входит в /admin/zapchasti, но сейчас в резерве',
                default => 'Активная незарезервированная позиция /admin/zapchasti не найдена по коду/штрихкоду',
            };
            $statKey = match ($skipReason) {
                'not_active' => 'skipped_not_active_or_sold',
                'reserved' => 'skipped_reserved',
                default => 'skipped_not_found_in_zapchasti',
            };

            $this->stats[$statKey]++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, $issue);

            return;
        }

        $product = $this->productForItem($item, $code, $barcode);
        if (! $product instanceof Product) {
            $this->stats['skipped_missing_linked_product']++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, 'Позиция /admin/zapchasti найдена, но связанный Product не найден');

            return;
        }

        if (! $product->is_active || in_array($product->storage_status, [Product::STORAGE_STATUS_SOLD, Product::STORAGE_STATUS_WRITTEN_OFF], true)) {
            $this->stats['skipped_inactive_or_sold_product']++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, 'Связанный Product неактивен, продан или списан');

            return;
        }

        $stockItems = StockItem::query()
            ->with(['location.warehouse', 'product'])
            ->where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->get();

        if ($stockItems->sum('reserved_quantity') > 0) {
            $this->stats['skipped_stock_reserved']++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, 'У связанного Product есть reserved_quantity в stock_items');

            return;
        }

        $positiveStockItems = $stockItems->filter(fn (StockItem $stockItem): bool => (int) $stockItem->quantity > 0);
        if ($positiveStockItems->isEmpty()) {
            $this->stats['skipped_no_positive_stock']++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, 'У связанного Product нет положительного stock_items.quantity');

            return;
        }

        $warehouseName = $this->targetWarehouseName($stockText);
        $warehouse = $warehouseName !== null
            ? Warehouse::query()->where('name', $warehouseName)->first()
            : null;

        if (! $warehouse instanceof Warehouse) {
            $this->stats['skipped_unknown_warehouse']++;
            $this->recordIssue($row, $code, $article, $name, $stockText, $cell, 'Не удалось определить склад назначения');

            return;
        }

        $floor = $this->floorFromStockText($stockText);
        $location = $write
            ? DB::transaction(fn (): Location => $this->resolveLocation($warehouse, $floor, $cell, true))
            : $this->resolveLocation($warehouse, $floor, $cell, false);

        $moveCandidates = $positiveStockItems
            ->filter(fn (StockItem $stockItem): bool => (int) $stockItem->location_id !== (int) $location->id)
            ->values();

        if ($moveCandidates->isEmpty()) {
            $this->stats['already_at_target']++;
            $this->recordExample('already_at_target', $row, $item, $product, $location, $matchMethod);

            return;
        }

        $this->stats['matched_active_unreserved_items']++;
        $this->recordExample('will_move', $row, $item, $product, $location, $matchMethod, $moveCandidates);

        if (! $write) {
            $this->stats['moved_stock_items'] += $moveCandidates->count();

            return;
        }

        DB::transaction(function () use ($moveCandidates, $location): void {
            foreach ($moveCandidates as $stockItem) {
                app(StockService::class)->move($stockItem, (int) $stockItem->quantity, (int) $location->id, [
                    'document_number' => 'warehouse-placement-2026-06-16',
                    'comment' => 'One-off warehouse placement from NikolaCars nomenclature spreadsheet.',
                ]);

                $this->stats['moved_stock_items']++;
            }
        });
    }

    protected function findZapchastiItem(string $code, string $barcode, Collection $activeItemIds, Collection $reservedItemIds): array
    {
        $candidates = collect();
        $matchMethod = null;

        if ($code !== '') {
            $candidates = PartCatalogItem::query()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->where('raw_attributes->code', $code)
                ->get();
            $matchMethod = 'raw_attributes.code={Код}';
        }

        if ($candidates->isEmpty() && $code !== '') {
            $candidates = PartCatalogItem::query()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->where('raw_attributes->source_row->code', $code)
                ->get();
            $matchMethod = 'raw_attributes.source_row.code={Код}';
        }

        if ($candidates->isEmpty() && $code !== '') {
            $product = Product::query()
                ->with('sourcePartCatalogItem')
                ->where('sku', 'NC-'.$code)
                ->first();

            if ($product?->sourcePartCatalogItem?->source === NikolaCarsInventoryService::SOURCE) {
                $candidates = collect([$product->sourcePartCatalogItem]);
                $matchMethod = 'product.sku=NC-{Код} -> sourcePartCatalogItem';
            }
        }

        if ($candidates->isEmpty() && $barcode !== '') {
            $product = Product::query()
                ->with('sourcePartCatalogItem')
                ->where('barcode', $barcode)
                ->first();

            if ($product?->sourcePartCatalogItem?->source === NikolaCarsInventoryService::SOURCE) {
                $candidates = collect([$product->sourcePartCatalogItem]);
                $matchMethod = 'product.barcode -> sourcePartCatalogItem';
            }
        }

        if ($candidates->isEmpty()) {
            return [null, $matchMethod, 'not_found'];
        }

        $activeCandidates = $candidates
            ->filter(fn (PartCatalogItem $item): bool => $activeItemIds->has((int) $item->id))
            ->values();

        if ($activeCandidates->isEmpty()) {
            return [null, $matchMethod, 'not_active'];
        }

        $unreservedCandidates = $activeCandidates
            ->filter(fn (PartCatalogItem $item): bool => ! $reservedItemIds->has((int) $item->id))
            ->values();

        if ($unreservedCandidates->isEmpty()) {
            return [null, $matchMethod, 'reserved'];
        }

        return [$unreservedCandidates->first(), $matchMethod, null];
    }

    protected function productForItem(PartCatalogItem $item, string $code, string $barcode): ?Product
    {
        $productId = $this->productIdFromItem($item);
        if ($productId > 0) {
            return Product::query()->find($productId);
        }

        if ($code !== '') {
            $product = Product::query()
                ->where('sku', 'NC-'.$code)
                ->where('source_part_catalog_item_id', $item->id)
                ->first();

            if ($product instanceof Product) {
                return $product;
            }
        }

        if ($barcode !== '') {
            return Product::query()
                ->where('barcode', $barcode)
                ->where('source_part_catalog_item_id', $item->id)
                ->first();
        }

        return null;
    }

    protected function productIdFromItem(PartCatalogItem $item): int
    {
        $productId = (int) data_get($item->raw_attributes, 'product_id');

        if ($productId > 0) {
            return $productId;
        }

        return preg_match('~^nikolacars://(?:donor-product|inventory-product)/(\d+)$~', (string) $item->source_url, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    protected function resolveLocation(Warehouse $warehouse, string $floor, ?string $cell, bool $write): Location
    {
        $floorNumber = (int) Str::after($floor, 'floor_');
        if ($floorNumber > (int) $warehouse->floor_count) {
            if ($write) {
                $warehouse->forceFill(['floor_count' => $floorNumber])->save();
            }

            if (! isset($this->expandedWarehouseIds[$warehouse->id])) {
                $this->expandedWarehouseIds[$warehouse->id] = true;
                $this->stats['expanded_warehouse_floor_count']++;
            }
        }

        $query = Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('floor', $floor);

        $cell === null ? $query->whereNull('cell') : $query->where('cell', $cell);

        $location = $query->first();
        if ($location instanceof Location) {
            $this->stats['existing_locations']++;

            return $location;
        }

        if ($cell !== null) {
            $location = Location::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('floor', $floor)
                ->where('full_code', $cell)
                ->first();

            if ($location instanceof Location) {
                $this->stats['existing_locations']++;

                return $location;
            }
        }

        $this->stats['created_locations']++;
        $attributes = [
            'warehouse_id' => $warehouse->id,
            'floor' => $floor,
            'cell' => $cell,
            'full_code' => $this->uniqueLocationCode($warehouse, $floor, $cell),
            'is_active' => true,
        ];

        return $write ? Location::query()->create($attributes) : new Location($attributes);
    }

    protected function uniqueLocationCode(Warehouse $warehouse, string $floor, ?string $cell): string
    {
        $floorNumber = Str::after($floor, 'floor_') ?: '1';
        $cellCode = $cell ? Str::upper(Str::slug($cell) ?: 'CELL') : 'NO-CELL';
        $base = "WH{$warehouse->id}-F{$floorNumber}-{$cellCode}";
        $code = $base;
        $counter = 2;

        while (Location::query()->where('full_code', $code)->exists()) {
            $code = "{$base}-{$counter}";
            $counter++;
        }

        return $code;
    }

    protected function targetWarehouseName(string $warehouseText): ?string
    {
        if (mb_stripos($warehouseText, 'СТО') !== false) {
            return 'СТО';
        }

        if (mb_stripos($warehouseText, 'Гараж 278') !== false) {
            return 'Гараж 278';
        }

        if (mb_stripos($warehouseText, 'Гараж 214') !== false) {
            return 'Гараж 214';
        }

        if (mb_stripos($warehouseText, 'разбор') !== false || mb_stripos($warehouseText, 'Склад') !== false) {
            return 'Разборка';
        }

        return null;
    }

    protected function floorFromStockText(string $warehouseText): string
    {
        $normalized = mb_strtolower($warehouseText);

        if (preg_match('/(?:^|[^\d])2\s*(?:єт|эт|ет|поверх|floor)/iu', $normalized) === 1) {
            return 'floor_2';
        }

        return 'floor_1';
    }

    protected function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    protected function resolveInputPath(string $input): string
    {
        if (is_file($input)) {
            return $input;
        }

        $storagePath = storage_path('app/private/'.ltrim($input, '/\\'));
        if (is_file($storagePath)) {
            return $storagePath;
        }

        return base_path($input);
    }

    protected function recordIssue(array $row, string $code, string $article, string $name, string $stockText, ?string $cell, string $issue): void
    {
        if (count($this->issues) >= (int) $this->option('examples')) {
            return;
        }

        $this->issues[] = [
            $row['excel_row'] ?? '',
            $code,
            $article,
            Str::limit($name, 60),
            Str::limit($stockText, 50),
            (string) $cell,
            $issue,
        ];
    }

    protected function recordExample(string $bucket, array $row, PartCatalogItem $item, Product $product, Location $location, ?string $matchMethod, ?Collection $stockItems = null): void
    {
        $limit = (int) $this->option('examples');
        $this->examples[$bucket] ??= [];

        if (count($this->examples[$bucket]) >= $limit) {
            return;
        }

        $quantity = $stockItems?->sum('quantity') ?? 0;
        $from = $stockItems
            ? $stockItems
                ->map(fn (StockItem $stockItem): string => trim(collect([
                    $stockItem->location?->warehouse?->name,
                    $stockItem->location?->floor,
                    $stockItem->location?->cell ?: $stockItem->location?->full_code,
                    'qty='.$stockItem->quantity,
                ])->filter()->implode('/')))
                ->implode('; ')
            : '';

        $this->examples[$bucket][] = [
            $row['excel_row'] ?? '',
            $this->cell($row, 'Код'),
            $item->id,
            $product->id,
            $product->sku,
            $quantity,
            $from,
            $location->full_code,
            $matchMethod,
        ];
    }

    protected function printSummary(): void
    {
        $this->table(
            ['metric', 'value'],
            collect($this->stats)->map(fn (mixed $value, string $metric): array => [$metric, $value])->values()->all()
        );

        foreach ($this->examples as $bucket => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->info($bucket);
            $this->table(
                ['excel_row', 'code', 'item_id', 'product_id', 'sku', 'qty', 'from', 'to_location', 'matched_by'],
                $rows
            );
        }

        if ($this->issues !== []) {
            $this->newLine();
            $this->warn('Issues examples');
            $this->table(
                ['excel_row', 'code', 'article', 'name', 'warehouse_text', 'cell', 'issue'],
                $this->issues
            );
        }
    }
}
